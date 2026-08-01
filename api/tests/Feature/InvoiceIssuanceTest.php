<?php

namespace Tests\Feature;

use App\Events\PaymentRequested;
use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\ConsolidatedInvoiceService;
use App\Services\Reconciliation\Assertions\InvoiceLineReconciliationAssertion;
use App\Services\Reconciliation\Assertions\InvoiceBalanceAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

/**
 * S04F STEP 2 — consolidated invoice issuance/aggregation (OD-25). School-payer
 * 成團 orders aggregate into ONE (school, programme) invoice; the original is the
 * sum of covered orders; re-running never double-counts; a covered order is a
 * receivable (not paid, no receipt).
 */
class InvoiceIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private School $school;

    private const FEE = 250000;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $c, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        $this->school = $this->sys(fn () => School::create(['name_en' => 'Sch'.Str::random(3), 'name_tc' => '甲', 'name_sc' => '甲']));
    }

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->setSystem();
        }
    }

    /** @return array{0: Programme, 1: string} programme, lobbyId — payer_party='school' */
    private function schoolProgramme(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = $this->sys(fn () => Programme::create(['code' => 'IN-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']));
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'],
                'eligibility' => ['capacity' => 5],
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'team_rules' => ['formation_deadline_on' => '2026-06-20', 'min_team_size' => 1],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$k}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/fee-items", ['name_en' => 'Fee', 'name_tc' => '費', 'name_sc' => '费', 'amount_minor' => self::FEE, 'currency' => 'HKD'])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $lobby = $this->postJson("/api/admin/programmes/{$programme->id}/team-categories", ['name_en' => 'Open', 'name_tc' => '開', 'name_sc' => '开', 'assignment_rule' => 'open', 'is_default' => true])->json('id');
        $this->app['auth']->forgetGuards();
        $this->sys(fn () => DB::table('programmes')->where('id', $programme->id)->update(['payer_party' => 'school']));

        return [$programme, $lobby];
    }

    private function pooledStudentOnRoll(Programme $programme): User
    {
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        $this->sys(fn () => DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $this->school->id, 'status' => 'active', 'origin' => 'registration', 'created_at' => now(), 'updated_at' => now()]));
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $this->postJson('/api/my/enrolments', ['programme_id' => $programme->id, 'student_id' => $student->id]);
        $req = DB::table('consent_requests')->where('student_id', $student->id)->where('signer_id', $guardian->id)->whereIn('status', ['sent', 'viewed'])->first();
        $this->getJson("/api/consent-requests/{$req->id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/sign", ['affirmed' => true, 'method' => 'typed', 'typed_name' => 'G'])->assertStatus(201);
        app(EnrolmentService::class)->evaluateConsentGate($programme->id, $student->id, $guardian);
        $this->app['auth']->forgetGuards();

        return $student;
    }

    private function confirmedTeamOf(Programme $programme, string $lobby, int $size): string
    {
        $creator = $this->pooledStudentOnRoll($programme);
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $programme->id, 'category_id' => $lobby, 'name' => 'Team'.Str::random(4)])->json('id');
        for ($i = 1; $i < $size; $i++) {
            $m = $this->pooledStudentOnRoll($programme);
            Sanctum::actingAs($m);
            $this->postJson("/api/teams/{$teamId}/join")->assertOk();
        }
        Sanctum::actingAs($creator);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $this->app['auth']->forgetGuards();

        return $teamId;
    }

    // ── one invoice per (school, programme); original = Σ covered orders ───────

    public function test_school_orders_aggregate_into_one_invoice(): void
    {
        Event::fake([PaymentRequested::class]); // suppress downstream event delivery
        [$programme, $lobby] = $this->schoolProgramme();
        $this->confirmedTeamOf($programme, $lobby, 2);

        $invoices = $this->sys(fn () => DB::table('consolidated_invoices')->where('programme_id', $programme->id)->get());
        $this->assertCount(1, $invoices, 'exactly one (school, programme) invoice');
        $inv = $invoices->first();
        $this->assertSame($this->school->id, (int) $inv->school_id);
        $this->assertSame(2 * self::FEE, (int) $inv->original_amount_minor, 'original = Σ the two covered orders');
        $this->assertSame(2 * self::FEE, (int) $inv->balance_minor, 'no credit notes yet → balance = original');
        $this->assertSame('issued', $inv->status);

        // covered orders: covered_by_invoice, attached, NOT paid, NO receipt (receivable)
        $orders = $this->sys(fn () => DB::table('orders')->where('programme_id', $programme->id)->get());
        $this->assertCount(2, $orders);
        $this->assertTrue($orders->every(fn ($o) => $o->status === 'covered_by_invoice' && $o->consolidated_invoice_id === $inv->id));
        $this->assertSame(0, $this->sys(fn () => DB::table('receipts')->whereIn('order_id', $orders->pluck('id'))->count()), 'no receipt until the school pays (BI-2)');

        // both money-integrity assertions green
        $this->assertTrue($this->sys(fn () => (new InvoiceLineReconciliationAssertion)->check()->passed));
        $this->assertTrue($this->sys(fn () => (new InvoiceBalanceAssertion)->check()->passed));
    }

    // ── idempotent — re-covering an order does not double-count ───────────────

    public function test_recovering_an_order_is_idempotent(): void
    {
        Event::fake([PaymentRequested::class]);
        [$programme, $lobby] = $this->schoolProgramme();
        $this->confirmedTeamOf($programme, $lobby, 2);

        $invoiceId = $this->sys(fn () => DB::table('consolidated_invoices')->where('programme_id', $programme->id)->value('id'));
        $before = $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->value('original_amount_minor'));

        // re-run coverOrder for an already-covered order → no change, no second invoice
        $order = $this->sys(fn () => DB::table('orders')->where('programme_id', $programme->id)->first());
        $this->sys(fn () => app(ConsolidatedInvoiceService::class)->coverOrder($order));

        $this->assertSame((int) $before, (int) $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->value('original_amount_minor')), 'no double-count');
        $this->assertSame(1, $this->sys(fn () => DB::table('consolidated_invoices')->where('programme_id', $programme->id)->count()), 'still one invoice');
    }

    // ── five-branch: another school's admin cannot read the invoice ───────────

    public function test_another_schools_admin_cannot_read_the_invoice(): void
    {
        Event::fake([PaymentRequested::class]);
        [$programme, $lobby] = $this->schoolProgramme();
        $this->confirmedTeamOf($programme, $lobby, 2);

        $otherSchool = $this->sys(fn () => School::create(['name_en' => 'Other'.Str::random(3), 'name_tc' => '乙', 'name_sc' => '乙']));
        $otherAdmin = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $otherAdmin->id, 'school_id' => $otherSchool->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        $scope = app(ScopeContext::class);
        $scope->set($otherAdmin);
        $seen = DB::table('consolidated_invoices')->where('programme_id', $programme->id)->count();
        $scope->setSystem();
        $this->assertSame(0, $seen, 'RLS hides another school\'s invoice');
    }

    // ── line_reconciliation teeth ─────────────────────────────────────────────

    public function test_line_reconciliation_reds_on_a_tampered_original_then_greens(): void
    {
        Event::fake([PaymentRequested::class]);
        [$programme, $lobby] = $this->schoolProgramme();
        $this->confirmedTeamOf($programme, $lobby, 2);
        $invoiceId = $this->sys(fn () => DB::table('consolidated_invoices')->where('programme_id', $programme->id)->value('id'));
        $this->assertTrue($this->sys(fn () => (new InvoiceLineReconciliationAssertion)->check()->passed), 'green after issuance');

        // tamper: original no longer equals Σ covered orders
        $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->update(['original_amount_minor' => 999]));
        $this->assertFalse($this->sys(fn () => (new InvoiceLineReconciliationAssertion)->check()->passed), 'reds when original ≠ Σ covered orders');

        // recompute (cover one order again) restores it
        $order = $this->sys(fn () => DB::table('orders')->where('consolidated_invoice_id', $invoiceId)->first());
        $this->sys(fn () => app(ConsolidatedInvoiceService::class)->coverOrder($order));
        $this->assertTrue($this->sys(fn () => (new InvoiceLineReconciliationAssertion)->check()->passed));
    }
}
