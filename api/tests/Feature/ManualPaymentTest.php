<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\OrderService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class ManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $recorder;

    private User $confirmer;

    private User $guardian;

    private User $coGuardian;

    private User $student;

    private Programme $programme;

    private string $orderId;

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
        // BI-9 staffing: two DISTINCT finance accounts (OD-14 requirement)
        $this->recorder = User::factory()->create(['role' => 'academy_admin']);
        $this->confirmer = User::factory()->create(['role' => 'academy_admin']);
        foreach ([$this->recorder, $this->confirmer] as $fin) {
            DB::table('admin_capabilities')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $fin->id,
                'capability' => 'finance', 'granted_by' => $this->ops->id, 'granted_at' => now(),
            ]);
        }
        $this->guardian = User::factory()->create(['role' => 'guardian']);
        $this->coGuardian = User::factory()->create(['role' => 'guardian']);
        $this->student = User::factory()->create(['role' => 'student']);
        foreach ([$this->guardian, $this->coGuardian] as $g) {
            DB::table('guardian_links')->insert([
                'id' => (string) Str::uuid7(), 'student_id' => $this->student->id,
                'guardian_id' => $g->id, 'status' => 'active', 'origin' => 'onboarding',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
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
            'code' => 'MAN-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'basics' => ['enrolment_closes_on' => '2027-01-10', 'starts_on' => '2027-02-01'], 'team_rules' => ['formation_deadline_on' => '2027-01-20'], default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/fee-items", [
            'name_en' => 'Programme fee', 'name_tc' => '課程費用', 'name_sc' => '课程费用',
            'amount_minor' => 250000, 'currency' => 'HKD',
        ])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $enrolmentId = $this->postJson('/api/my/enrolments', [
            'programme_id' => $this->programme->id, 'student_id' => $this->student->id,
        ])->json('id');
        $this->app['auth']->forgetGuards();
        $machine = app(EnrolmentService::class);
        foreach (['in_pool', 'teamed', 'confirmed'] as $to) {
            $machine->transition($enrolmentId, $to, $this->ops, 'manual payment walk');
        }
        $this->orderId = app(OrderService::class)->issueForEnrolment($enrolmentId, 'guardian', null, $this->ops)->id;
    }

    private function record(?User $as = null, int $amount = 250000, int $files = 2): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($as ?? $this->recorder);
        $evidence = [];
        for ($i = 0; $i < $files; $i++) {
            $evidence[] = UploadedFile::fake()->image("transfer-{$i}.png", 200, 200);
        }

        return $this->post('/api/admin/payments', [
            'order_id' => $this->orderId, 'amount_minor' => $amount, 'currency' => 'HKD',
            'note' => 'bank transfer ref 12345', 'evidence' => $evidence,
        ], ['Accept' => 'application/json']);
    }

    public function test_record_confirm_chain_with_evidence_via_the_existing_pipeline(): void
    {
        $paymentId = $this->record()->assertStatus(201)->json('id');

        // evidence rode the EXISTING BI-10 pipeline: context 'evidence', scanned clean
        $uploads = DB::table('payment_evidence as pe')->join('uploads as u', 'u.id', '=', 'pe.upload_id')
            ->where('pe.payment_id', $paymentId)->get(['u.context', 'u.status']);
        $this->assertCount(2, $uploads);
        foreach ($uploads as $u) {
            $this->assertSame(['evidence', 'clean'], [$u->context, $u->status]);
        }

        // a DIFFERENT finance account confirms (BI-9)
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->confirmer);
        $this->postJson("/api/admin/payments/{$paymentId}/confirm")->assertOk();

        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $this->assertSame(['manual', 'confirmed'], [$payment->origin, $payment->status]);
        $this->assertSame($this->recorder->id, (int) $payment->recorded_by);
        $this->assertSame($this->confirmer->id, (int) $payment->confirmed_by);
        $this->assertNotEquals((int) $payment->recorded_by, (int) $payment->confirmed_by);
        $this->assertSame('paid', DB::table('orders')->where('id', $this->orderId)->value('status'));
        $this->assertSame(1, (int) DB::table('receipts')->count(), 'gapless receipt issued on finalize');
        foreach (['payment.recorded', 'payment.confirmed', 'order.paid', 'receipt.issued'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action]);
        }
    }

    public function test_self_confirm_refused_server_side_and_at_the_database(): void
    {
        $paymentId = $this->record()->assertStatus(201)->json('id');

        // APP LAYER: the recorder confirming their own payment → 403 + audited
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->recorder);
        $this->postJson("/api/admin/payments/{$paymentId}/confirm")->assertStatus(403);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'payment', 'entity_id' => $paymentId,
            'action' => 'payment.bi9_refused', 'actor_id' => $this->recorder->id,
        ]);
        $this->assertSame('pending_confirmation', DB::table('payments')->where('id', $paymentId)->value('status'));

        // DB LAYER: even a raw UPDATE in the recorder's context is refused by
        // the policy WITH CHECK (confirmed_by = actor AND recorded_by <> actor)
        $scope = app(\App\Services\Authz\ScopeContext::class);
        $scope->set($this->recorder);
        try {
            DB::transaction(fn () => DB::table('payments')->where('id', $paymentId)->update([
                'status' => 'confirmed', 'confirmed_by' => $this->recorder->id,
            ]));
            $this->fail('the DB must refuse recorder = confirmer (BI-9 teeth)');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('row-level security', $e->getMessage());
        } finally {
            $scope->setSystem();
        }
        // BI-9 applies to rejection too
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->recorder);
        $this->postJson("/api/admin/payments/{$paymentId}/reject", ['reason' => 'my own mistake'])->assertStatus(403);
    }

    public function test_od47_boundary_from_both_sides(): void
    {
        // MANUAL side: recorder + confirmer both present and DISTINCT (proven above);
        // schema CHECK requires a recorder on manual rows
        try {
            DB::transaction(fn () => DB::table('payments')->insert([
                'id' => (string) Str::uuid7(), 'order_id' => $this->orderId, 'origin' => 'manual',
                'amount_minor' => 250000, 'currency' => 'HKD', 'via_link' => false,
                'status' => 'pending_confirmation', 'recorded_by' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('manual payment without a recorder must be impossible');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('pay_origin_actors_check', $e->getMessage());
        }

        // PROVIDER side: born confirmed with NEITHER recorder NOR confirmer (OD-47)
        DB::table('payments')->insert([
            'id' => $pid = (string) Str::uuid7(), 'order_id' => $this->orderId, 'origin' => 'provider',
            'provider' => 'mock', 'provider_ref' => 'mock_x', 'amount_minor' => 250000,
            'currency' => 'HKD', 'via_link' => true, 'status' => 'confirmed',
            'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('payments')->where('id', $pid)->first();
        $this->assertNull($row->recorded_by);
        $this->assertNull($row->confirmed_by);
        $this->assertSame('confirmed', $row->status);
        // and a provider row carrying a confirmer is schema-impossible
        try {
            DB::transaction(fn () => DB::table('payments')->where('id', $pid)->update(['confirmed_by' => $this->confirmer->id]));
            $this->fail('a provider payment with a human confirmer must be impossible');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'pay_origin_actors_check') || str_contains($e->getMessage(), 'row-level security'),
                $e->getMessage(),
            );
        }
    }

    public function test_full_amount_only_and_evidence_required(): void
    {
        $this->record(amount: 249999)->assertStatus(422); // underpayment: NOT recorded (OD-5)
        $this->record(amount: 250001)->assertStatus(422); // overpayment: not recordable here
        $this->record(files: 0)->assertStatus(422);       // 1..n evidence required
        $this->assertSame(0, (int) DB::table('payments')->count());
    }

    public function test_confirmation_waits_for_the_scan_bi10(): void
    {
        $paymentId = $this->record()->assertStatus(201)->json('id');
        // simulate a still-pending scan on one evidence file
        $uploadId = DB::table('payment_evidence')->where('payment_id', $paymentId)->value('upload_id');
        DB::table('uploads')->where('id', $uploadId)->update(['status' => 'pending']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->confirmer);
        $this->postJson("/api/admin/payments/{$paymentId}/confirm")->assertStatus(409);
        DB::table('uploads')->where('id', $uploadId)->update(['status' => 'clean']);
        $this->postJson("/api/admin/payments/{$paymentId}/confirm")->assertOk();
    }

    public function test_recording_against_a_paid_order_is_refused_and_audited(): void
    {
        DB::table('orders')->where('id', $this->orderId)->update(['status' => 'paid']);
        $this->record()->assertStatus(409);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'order', 'entity_id' => $this->orderId, 'action' => 'payment.late_refused',
        ]);
    }

    public function test_five_branch_on_the_payments_read_set(): void
    {
        $school = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $paymentId = $this->record()->assertStatus(201)->json('id');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->confirmer);
        $this->postJson("/api/admin/payments/{$paymentId}/confirm")->assertOk();

        // [1] guardian and [2] co-guardian see the family payment (OD-67)
        foreach ([$this->guardian, $this->coGuardian] as $g) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($g);
            $this->assertCount(1, $this->getJson('/api/payments')->json('data'), "guardian {$g->id}");
        }
        // [3] school_admin holds finance.view (S02B) but sees ZERO family payments (OD-67)
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($schoolAdmin);
        $this->assertCount(0, $this->getJson('/api/payments')->json('data'));
        // [4] finance sees all; unrelated guardian zero
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->assertCount(1, $this->getJson('/api/payments')->json('data'));
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->assertCount(0, $this->getJson('/api/payments')->json('data'));
        // [5] Member: 403 at the S01 matrix gate (control preserved); student:
        // row-visible under RLS but gated by the matrix (no finance.view) — the
        // matrix decision is S01-seeded and not this step's to change
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->getJson('/api/payments')->assertStatus(403);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->student);
        $this->getJson('/api/payments')->assertStatus(403);
    }
}
