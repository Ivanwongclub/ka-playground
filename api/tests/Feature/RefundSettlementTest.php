<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\OrderService;
use App\Services\Money\RefundService;
use App\Services\Money\WithdrawalSettlementService;
use App\Services\Reconciliation\Assertions\InvoiceBalanceAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class RefundSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $finA;

    private User $finB;

    private User $guardian;

    private User $student;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations', 'finance'] as $cap) {
            $this->grant($this->ops, $cap);
        }
        $this->finA = User::factory()->create(['role' => 'academy_admin']);
        $this->finB = User::factory()->create(['role' => 'academy_admin']);
        $this->grant($this->finA, 'finance');
        $this->grant($this->finB, 'finance');
        $this->guardian = User::factory()->create(['role' => 'guardian']);
        $this->student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $this->student->id,
            'guardian_id' => $this->guardian->id, 'status' => 'active', 'origin' => 'onboarding',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $lang) {
            $vid = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $lang, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$vid}/publish")->assertOk();
        }
        $this->programme = Programme::query()->create(['code' => 'RFD-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true], 'consent' => ['template_ref' => $templateId], 'basics' => ['enrolment_closes_on' => '2027-01-10', 'starts_on' => '2027-02-01'], 'team_rules' => ['formation_deadline_on' => '2027-01-20'], default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/fee-items", ['name_en' => 'Fee', 'name_tc' => '費', 'name_sc' => '费', 'amount_minor' => 250000, 'currency' => 'HKD'])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();
    }

    private function grant(User $u, string $cap): void
    {
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => $cap,
            'granted_by' => $u->id, 'granted_at' => now(),
        ]);
    }

    /** Enrol → confirm → order; returns [enrolmentId, orderId]. */
    private function confirmedOrder(string $payerParty = 'guardian', ?int $schoolId = null): array
    {
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $this->guardian->id,
            'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $enrolmentId = $this->postJson('/api/my/enrolments', ['programme_id' => $this->programme->id, 'student_id' => $student->id])->json('id');
        $this->app['auth']->forgetGuards();
        $machine = app(EnrolmentService::class);
        foreach (['in_pool', 'teamed', 'confirmed'] as $to) {
            $machine->transition($enrolmentId, $to, $this->ops, 'refund test walk');
        }
        $order = app(OrderService::class)->issueForEnrolment($enrolmentId, $payerParty, $schoolId, $this->ops);

        return [$enrolmentId, $order->id];
    }

    /** An APPROVED withdrawal for the enrolment (state only — money is this step). */
    private function approvedWithdrawal(string $enrolmentId): string
    {
        $id = (string) Str::uuid7();
        DB::table('withdrawal_requests')->insert([
            'id' => $id, 'enrolment_id' => $enrolmentId,
            'student_id' => DB::table('enrolments')->where('id', $enrolmentId)->value('student_id'),
            'requested_by' => $this->guardian->id, 'reason' => 'relocation', 'status' => 'approved',
            'decided_by' => $this->ops->id, 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function fixtureInvoice(int $schoolId, string $status, int $original = 250000): string
    {
        $id = (string) Str::uuid7();
        DB::table('consolidated_invoices')->insert([
            'id' => $id, 'school_id' => $schoolId, 'programme_id' => $this->programme->id,
            'original_amount_minor' => $original, 'balance_minor' => $original,
            'currency' => 'HKD', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    // ── trace to an approved withdrawal ──

    public function test_settlement_refused_for_a_non_approved_withdrawal(): void
    {
        [$enrolmentId, $orderId] = $this->confirmedOrder();
        DB::table('orders')->where('id', $orderId)->update(['status' => 'paid']);
        $wr = (string) Str::uuid7();
        DB::table('withdrawal_requests')->insert([
            'id' => $wr, 'enrolment_id' => $enrolmentId,
            'student_id' => DB::table('enrolments')->where('id', $enrolmentId)->value('student_id'),
            'requested_by' => $this->guardian->id, 'reason' => 'x', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            app(WithdrawalSettlementService::class)->settle($wr, $this->ops);
            $this->fail('settlement on a non-approved withdrawal must be refused');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('refunds')->count());
        $this->assertSame(0, DB::table('credit_notes')->count());
    }

    public function test_family_paid_refund_traces_to_approved_withdrawal_full_amount_to_payer(): void
    {
        [$enrolmentId, $orderId] = $this->confirmedOrder('guardian');
        DB::table('orders')->where('id', $orderId)->update(['status' => 'paid']);
        $wr = $this->approvedWithdrawal($enrolmentId);

        $result = app(WithdrawalSettlementService::class)->settle($wr, $this->ops);
        $refund = DB::table('refunds')->where('id', $result['refund_id'])->first();
        $this->assertNotNull($refund);
        $this->assertSame($wr, $refund->withdrawal_request_id, 'refund traces to the approved withdrawal');
        $this->assertSame(250000, (int) $refund->amount_minor, 'full order total (OD-48)');
        $this->assertSame('guardian', $refund->destination_party, 'destination = original payer (OD-25)');
        $this->assertSame('requested', $refund->status);
        $this->assertNull($result['credit_note_id'], 'family-paid → no credit note');
    }

    // ── OD-54 both ways ──

    public function test_od54_school_settled_before_payment_credit_note_only(): void
    {
        $school = School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S']);
        [$enrolmentId, $orderId] = $this->confirmedOrder('school', $school->id);
        $invoiceId = $this->fixtureInvoice($school->id, 'issued'); // UNPAID
        DB::table('orders')->where('id', $orderId)->update(['status' => 'covered_by_invoice', 'consolidated_invoice_id' => $invoiceId]);
        $wr = $this->approvedWithdrawal($enrolmentId);

        $result = app(WithdrawalSettlementService::class)->settle($wr, $this->ops);
        $this->assertNotNull($result['credit_note_id'], 'OD-54: credit note ALWAYS');
        $this->assertNull($result['refund_id'], 'unpaid invoice → balance drops, NO refund');
        $this->assertSame(0, (int) DB::table('consolidated_invoices')->where('id', $invoiceId)->value('balance_minor'),
            'balance dropped by the credit (250000 − 250000 = 0)');
        $this->assertTrue((new InvoiceBalanceAssertion)->check()->passed);
    }

    public function test_od54_school_settled_after_payment_credit_note_plus_refund_to_school(): void
    {
        $school = School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S']);
        [$enrolmentId, $orderId] = $this->confirmedOrder('school', $school->id);
        $invoiceId = $this->fixtureInvoice($school->id, 'paid'); // ALREADY PAID
        DB::table('orders')->where('id', $orderId)->update(['status' => 'covered_by_invoice', 'consolidated_invoice_id' => $invoiceId]);
        $wr = $this->approvedWithdrawal($enrolmentId);

        $result = app(WithdrawalSettlementService::class)->settle($wr, $this->ops);
        $this->assertNotNull($result['credit_note_id'], 'OD-54: credit note ALWAYS');
        $refund = DB::table('refunds')->where('id', $result['refund_id'])->first();
        $this->assertNotNull($refund, 'paid invoice → credit note BECOMES a refund-to-school');
        $this->assertSame('school', $refund->destination_party);
        $this->assertSame($school->id, (int) $refund->destination_school_id);
        $this->assertSame(250000, (int) $refund->amount_minor);
        // balance = original − credits holds in BOTH branches
        $this->assertSame(0, (int) DB::table('consolidated_invoices')->where('id', $invoiceId)->value('balance_minor'));
        $this->assertTrue((new InvoiceBalanceAssertion)->check()->passed);
    }

    // ── BI-9 in depth on the refund payout ──

    public function test_refund_bi9_both_layers(): void
    {
        [$enrolmentId, $orderId] = $this->confirmedOrder('guardian');
        DB::table('orders')->where('id', $orderId)->update(['status' => 'paid']);
        $refundId = app(WithdrawalSettlementService::class)->settle($this->approvedWithdrawal($enrolmentId), $this->ops)['refund_id'];

        // approve as finA
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->finA);
        $this->postJson("/api/admin/refunds/{$refundId}/approve", ['evidence_note' => 'bank transfer scheduled ref R-99'])->assertOk();

        // APP layer: the SAME person confirming → 403 + audit
        $this->postJson("/api/admin/refunds/{$refundId}/confirm")->assertStatus(403);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'refund', 'entity_id' => $refundId, 'action' => 'refund.bi9_refused', 'actor_id' => $this->finA->id,
        ]);
        // DB layer: a raw approver-context confirm is refused by the WITH CHECK
        $scope = app(\App\Services\Authz\ScopeContext::class);
        $scope->set($this->finA);
        try {
            DB::transaction(fn () => DB::table('refunds')->where('id', $refundId)->update([
                'status' => 'confirmed', 'confirmed_by' => $this->finA->id,
            ]));
            $this->fail('DB must refuse approver = confirmer on refunds (BI-9 teeth)');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('row-level security', $e->getMessage());
        } finally {
            $scope->setSystem();
        }
        // a DIFFERENT finance account confirms → success
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->finB);
        $this->postJson("/api/admin/refunds/{$refundId}/confirm")->assertOk();
        $refund = DB::table('refunds')->where('id', $refundId)->first();
        $this->assertSame('confirmed', $refund->status);
        $this->assertNotEquals((int) $refund->approved_by, (int) $refund->confirmed_by);
    }

    public function test_credit_notes_and_refunds_are_insert_only_at_the_database(): void
    {
        [$enrolmentId, $orderId] = $this->confirmedOrder('school', $s = School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S'])->id);
        $invoiceId = $this->fixtureInvoice($s, 'issued');
        DB::table('orders')->where('id', $orderId)->update(['status' => 'covered_by_invoice', 'consolidated_invoice_id' => $invoiceId]);
        app(WithdrawalSettlementService::class)->settle($this->approvedWithdrawal($enrolmentId), $this->ops);
        $cnId = DB::table('credit_notes')->value('id');
        try {
            DB::table('credit_notes')->where('id', $cnId)->update(['amount_minor' => 1]);
            $this->fail('credit_notes UPDATE must be impossible (BI-5)');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'permission denied') || str_contains($e->getMessage(), 'INSERT-only'), $e->getMessage());
        }
    }

    public function test_invoice_balance_assertion_has_teeth(): void
    {
        $school = School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S']);
        [$enrolmentId, $orderId] = $this->confirmedOrder('school', $school->id);
        $invoiceId = $this->fixtureInvoice($school->id, 'issued');
        DB::table('orders')->where('id', $orderId)->update(['status' => 'covered_by_invoice', 'consolidated_invoice_id' => $invoiceId]);
        app(WithdrawalSettlementService::class)->settle($this->approvedWithdrawal($enrolmentId), $this->ops);
        $this->assertTrue((new InvoiceBalanceAssertion)->check()->passed);

        // deliberate red: tamper the balance so it no longer equals original − credits
        DB::table('consolidated_invoices')->where('id', $invoiceId)->update(['balance_minor' => 999]);
        $result = (new InvoiceBalanceAssertion)->check();
        $this->assertFalse($result->passed);
        $this->assertStringContainsString('balance ≠ original − credits', $result->details);
    }

    public function test_end_to_end_through_the_approved_withdrawal_job(): void
    {
        // the settlement is wired into ApplyWithdrawal — an approved withdrawal
        // decided through the S04A workflow produces the money records
        [$enrolmentId, $orderId] = $this->confirmedOrder('guardian');
        DB::table('orders')->where('id', $orderId)->update(['status' => 'paid']);
        $wr = (string) Str::uuid7();
        DB::table('withdrawal_requests')->insert([
            'id' => $wr, 'enrolment_id' => $enrolmentId,
            'student_id' => DB::table('enrolments')->where('id', $enrolmentId)->value('student_id'),
            'requested_by' => $this->guardian->id, 'reason' => 'relocation', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(\App\Services\Enrolments\WithdrawalService::class)->decide($wr, true, 'confirmed with family', $this->ops);

        $this->assertSame('withdrawn', DB::table('enrolments')->where('id', $enrolmentId)->value('status'));
        $refund = DB::table('refunds')->where('withdrawal_request_id', $wr)->first();
        $this->assertNotNull($refund, 'the approved-withdrawal job settled the money');
        $this->assertSame(250000, (int) $refund->amount_minor);
    }
}
