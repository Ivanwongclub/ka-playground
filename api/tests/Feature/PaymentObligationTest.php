<?php

namespace Tests\Feature;

use App\Events\PaymentRequested;
use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\PaymentObligationConsumer;
use App\Services\Reconciliation\Assertions\PaymentObligationCompletenessAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class PaymentObligationTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $guardian;

    private Programme $programme;

    /** @var string[] */
    private array $enrolmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations', 'finance'] as $cap) {
            DB::table('admin_capabilities')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $this->ops->id,
                'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now(),
            ]);
        }
        $this->guardian = User::factory()->create(['role' => 'guardian']);
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $lang) {
            $vid = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", [
                'language' => $lang, 'body_html' => '<p>x {{signature}}</p>',
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$vid}/publish")->assertOk();
        }
        $this->programme = Programme::query()->create([
            'code' => 'OBL-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true, 'payment_deadline_days' => 7],
                'consent' => ['template_ref' => $templateId],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/fee-items", [
            'name_en' => 'Programme fee', 'name_tc' => '課程費用', 'name_sc' => '课程费用',
            'amount_minor' => 250000, 'currency' => 'HKD',
        ])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();
    }

    /** Enrol N students and walk each to Teamed (the state 成團 confirms FROM). */
    private function teamedEnrolments(int $count): array
    {
        $machine = app(EnrolmentService::class);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $student = User::factory()->create(['role' => 'student']);
            DB::table('guardian_links')->insert([
                'id' => (string) Str::uuid7(), 'student_id' => $student->id,
                'guardian_id' => $this->guardian->id, 'status' => 'active', 'origin' => 'onboarding',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($this->guardian);
            $id = $this->postJson('/api/my/enrolments', [
                'programme_id' => $this->programme->id, 'student_id' => $student->id,
            ])->json('id');
            $this->app['auth']->forgetGuards();
            foreach (['in_pool', 'teamed'] as $to) {
                $machine->transition($id, $to, $this->ops, 'obligation test walk');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * THE FIXTURE SEAT CLAIM (Q1): confirm + obligation in ONE transaction —
     * exactly what S05's 成團 will do, minus the real capacity counter.
     */
    private function fixtureClaim(array $enrolmentIds, string $payerParty = 'guardian', ?int $schoolId = null): void
    {
        $machine = app(EnrolmentService::class);
        DB::transaction(function () use ($enrolmentIds, $payerParty, $schoolId, $machine): void {
            foreach ($enrolmentIds as $id) {
                $enrolment = DB::table('enrolments')->where('id', $id)->first();
                $machine->transition($id, 'confirmed', $this->ops, 'fixture 成團');
                DB::table('payment_obligations')->insert([
                    'id' => (string) Str::uuid7(), 'enrolment_id' => $id,
                    'programme_id' => $enrolment->programme_id, 'student_id' => $enrolment->student_id,
                    'payer_party' => $payerParty, 'payer_school_id' => $schoolId,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function test_obligation_is_atomic_with_the_claim_both_or_neither(): void
    {
        [$id] = $this->teamedEnrolments(1);
        // Poison the obligation insert (duplicate id) — the WHOLE claim must roll back
        $dupe = (string) Str::uuid7();
        DB::table('enrolments')->where('id', $id)->first();
        try {
            DB::transaction(function () use ($id, $dupe): void {
                app(EnrolmentService::class)->transition($id, 'confirmed', $this->ops, 'poisoned claim');
                DB::table('payment_obligations')->insert([
                    'id' => $dupe, 'enrolment_id' => $id, 'programme_id' => $this->programme->id,
                    'student_id' => DB::table('enrolments')->where('id', $id)->value('student_id'),
                    'payer_party' => 'guardian', 'created_at' => now(),
                ]);
                DB::table('payment_obligations')->insert([
                    'id' => $dupe, 'enrolment_id' => $id, 'programme_id' => $this->programme->id,
                    'student_id' => DB::table('enrolments')->where('id', $id)->value('student_id'),
                    'payer_party' => 'guardian', 'created_at' => now(),
                ]); // pk violation
            });
            $this->fail('the poisoned claim should have thrown');
        } catch (\Illuminate\Database\QueryException) {
        }
        $this->assertSame('teamed', DB::table('enrolments')->where('id', $id)->value('status'),
            'Q1 atomicity: no confirmed enrolment without its obligation — both or neither');
        $this->assertSame(0, DB::table('payment_obligations')->count());
    }

    public function test_consumer_issues_family_order_with_deadline_and_event(): void
    {
        Event::fake([PaymentRequested::class]);
        [$id] = $this->teamedEnrolments(1);
        $this->fixtureClaim([$id]);

        $this->assertSame(1, app(PaymentObligationConsumer::class)->consume());

        $order = DB::table('orders')->where('enrolment_id', $id)->first();
        $this->assertNotNull($order);
        $this->assertSame(250000, (int) $order->total_amount_minor);
        $this->assertNotNull($order->payment_due_at, 'OD-43: 7-day clock starts at issuance');
        $obligation = DB::table('payment_obligations')->where('enrolment_id', $id)->first();
        $this->assertNotNull($obligation->consumed_at);
        $this->assertSame($order->id, $obligation->order_id);
        Event::assertDispatched(PaymentRequested::class, fn ($e) => $e->orderId === $order->id);
        // re-run is a no-op
        $this->assertSame(0, app(PaymentObligationConsumer::class)->consume());
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_school_settled_obligation_issues_order_without_family_task(): void
    {
        Event::fake([PaymentRequested::class]);
        $school = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        [$id] = $this->teamedEnrolments(1);
        $this->fixtureClaim([$id], 'school', $school->id);

        app(PaymentObligationConsumer::class)->consume();

        $order = DB::table('orders')->where('enrolment_id', $id)->first();
        $this->assertSame('school', $order->payer_party);
        $this->assertNull($order->payment_due_at, 'OD-50b: school-settled rows have NO family deadline');
        Event::assertNotDispatched(PaymentRequested::class);
    }

    public function test_kill_consumer_mid_batch_then_rescan_completes_idempotently(): void
    {
        $ids = $this->teamedEnrolments(6);
        $this->fixtureClaim($ids);

        // "kill" after 3 of 6
        $this->assertSame(3, app(PaymentObligationConsumer::class)->consume(limit: 3));
        $this->assertSame(3, DB::table('orders')->count());
        $this->assertSame(3, (int) DB::table('payment_obligations')->whereNull('consumed_at')->count());

        // crash-between-issuance-and-consumption: issue one order WITHOUT marking
        $stranded = DB::table('payment_obligations')->whereNull('consumed_at')->orderBy('created_at')->first();
        app(\App\Services\Money\OrderService::class)->issueForEnrolment($stranded->enrolment_id, 'guardian', null, null);
        $this->assertSame(4, DB::table('orders')->count());

        // full re-scan: everything completes, NOTHING duplicates
        $this->assertSame(3, app(PaymentObligationConsumer::class)->consume());
        $this->assertSame(6, DB::table('orders')->count(), 'exactly one order per enrolment after crash + rescan');
        $this->assertSame(0, (int) DB::table('payment_obligations')->whereNull('consumed_at')->count());
        $this->assertSame(6, (int) DB::table('payment_obligations')->whereNotNull('order_id')->count());
    }

    public function test_completeness_assertion_passes_healthy_and_fails_on_dead_consumer(): void
    {
        $ids = $this->teamedEnrolments(2);
        $this->fixtureClaim($ids);
        app(PaymentObligationConsumer::class)->consume();
        $this->assertTrue((new PaymentObligationCompletenessAssertion)->check()->passed);

        // dead consumer: a fresh obligation aged past the window, unconsumed
        [$id3] = $this->teamedEnrolments(1);
        $this->fixtureClaim([$id3]);
        DB::table('payment_obligations')->whereNull('consumed_at')->update(['created_at' => now()->subHour()]);
        $result = (new PaymentObligationCompletenessAssertion)->check();
        $this->assertFalse($result->passed);
        $this->assertStringContainsString('dead consumer', $result->details);

        // and the atomicity-break detector: confirmed with NO obligation at all
        app(PaymentObligationConsumer::class)->consume();
        $this->assertTrue((new PaymentObligationCompletenessAssertion)->check()->passed);
        [$id4] = $this->teamedEnrolments(1);
        app(\App\Services\Enrolments\EnrolmentService::class)->transition($id4, 'confirmed', $this->ops, 'no-obligation break');
        DB::table('enrolments')->where('id', $id4)->update(['updated_at' => now()->subHour()]);
        $result = (new PaymentObligationCompletenessAssertion)->check();
        $this->assertFalse($result->passed);
        $this->assertStringContainsString('atomicity break', $result->details);
    }

    public function test_nothing_user_reachable_fires_the_port(): void
    {
        // structural: no route references obligations or the consumer
        $routes = file_get_contents(base_path('routes/api.php'));
        $this->assertStringNotContainsString('obligation', strtolower($routes));
        $this->assertStringNotContainsString('ConsumePaymentObligations', $routes);
        // DB: request-context INSERT refused (even for finance-capability admins)
        [$id] = $this->teamedEnrolments(1);
        $scope = app(\App\Services\Authz\ScopeContext::class);
        $scope->set($this->ops);
        try {
            DB::transaction(fn () => DB::table('payment_obligations')->insert([
                'id' => (string) Str::uuid7(), 'enrolment_id' => $id,
                'programme_id' => $this->programme->id,
                'student_id' => DB::table('enrolments')->where('id', $id)->value('student_id'),
                'payer_party' => 'guardian', 'created_at' => now(),
            ]));
            $this->fail('request-context INSERT into payment_obligations must be refused');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('row-level security', $e->getMessage());
        } finally {
            $scope->setSystem();
        }
    }
}
