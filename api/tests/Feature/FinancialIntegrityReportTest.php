<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\ManualPaymentService;
use App\Services\Money\OrderService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class FinancialIntegrityReportTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $auditor;

    private User $guardian;

    private User $student;

    private Programme $programme;

    private string $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $config = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $config->id, 'capability' => 'configuration',
            'granted_by' => $config->id, 'granted_at' => now(),
        ]);
        $this->finance = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $this->finance->id, 'capability' => 'finance',
            'granted_by' => $config->id, 'granted_at' => now(),
        ]);
        $this->auditor = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $this->auditor->id, 'capability' => 'audit_read',
            'granted_by' => $config->id, 'granted_at' => now(),
        ]);
        $this->guardian = User::factory()->create(['role' => 'guardian']);
        $this->student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $this->student->id, 'guardian_id' => $this->guardian->id,
            'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($config);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $lang) {
            $vid = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $lang, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$vid}/publish")->assertOk();
        }
        $this->programme = Programme::query()->create(['code' => 'FIN-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) { 'fees' => ['has_fee_items' => true], 'consent' => ['template_ref' => $templateId], 'basics' => ['enrolment_closes_on' => '2027-01-10', 'starts_on' => '2027-02-01'], 'team_rules' => ['formation_deadline_on' => '2027-01-20'], default => ['x' => 1] };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/fee-items", ['name_en' => 'Fee', 'name_tc' => '費', 'name_sc' => '费', 'amount_minor' => 250000, 'currency' => 'HKD'])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $enrolmentId = $this->postJson('/api/my/enrolments', ['programme_id' => $this->programme->id, 'student_id' => $this->student->id])->json('id');
        $this->app['auth']->forgetGuards();
        $machine = app(EnrolmentService::class);
        foreach (['in_pool', 'teamed', 'confirmed'] as $to) {
            $machine->transition($enrolmentId, $to, $this->finance, 'report test walk');
        }
        $this->orderId = app(OrderService::class)->issueForEnrolment($enrolmentId, 'guardian', null, $this->finance)->id;
    }

    public function test_report_reads_live_from_source_no_cached_totals(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->finance);
        $before = collect($this->getJson('/api/reports/financial-integrity')->assertOk()->json('orders'))
            ->firstWhere('status', 'paid');
        $this->assertNull($before, 'no paid orders yet');

        // record + confirm a manual payment (BI-9) — no report refresh step
        $recorder = User::factory()->create(['role' => 'academy_admin']);
        $confirmer = User::factory()->create(['role' => 'academy_admin']);
        foreach ([$recorder, $confirmer] as $u) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => 'finance', 'granted_by' => $u->id, 'granted_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($recorder);
        $paymentId = $this->post('/api/admin/payments', [
            'order_id' => $this->orderId, 'amount_minor' => 250000, 'currency' => 'HKD',
            'evidence' => [UploadedFile::fake()->image('t.png', 100, 100)],
        ], ['Accept' => 'application/json'])->assertStatus(201)->json('id');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($confirmer);
        $this->postJson("/api/admin/payments/{$paymentId}/confirm")->assertOk();

        // the report reflects it IMMEDIATELY — computed live, not from a counter
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->finance);
        $report = $this->getJson('/api/reports/financial-integrity')->assertOk()->json();
        $this->assertTrue($report['live_from_source']);
        $paidOrders = collect($report['orders'])->firstWhere('status', 'paid');
        $this->assertSame(1, $paidOrders['n']);
        $this->assertSame(250000, (int) $paidOrders['minor']);
        $manualConfirmed = collect($report['payments_by_origin'])->first(fn ($b) => $b['origin'] === 'manual' && $b['status'] === 'confirmed');
        $this->assertSame(250000, (int) $manualConfirmed['minor']);
        $this->assertSame(1, $report['receipts']['count'], 'receipt issued on finalize, live-counted');
    }

    public function test_five_branch_the_report_does_not_widen_any_read_set(): void
    {
        // finance ✓ and audit_read ✓ (the two academy money/audit surfaces)
        foreach ([$this->finance, $this->auditor] as $allowed) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($allowed);
            $this->getJson('/api/reports/financial-integrity')->assertOk();
        }
        // super_admin ✓ (holds both)
        $super = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $super->id, 'capability' => 'super_admin', 'granted_by' => $super->id, 'granted_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($super);
        $this->getJson('/api/reports/financial-integrity')->assertOk();

        // a school admin — even one holding finance.view via role — gets 403:
        // the report is academy-scoped and widens NOTHING
        $school = School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S']);
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        // guardian, student, member: nothing
        foreach ([$schoolAdmin, $this->guardian, $this->student, User::factory()->create(['role' => 'member'])] as $denied) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($denied);
            $this->getJson('/api/reports/financial-integrity')->assertStatus(403);
        }
    }
}
