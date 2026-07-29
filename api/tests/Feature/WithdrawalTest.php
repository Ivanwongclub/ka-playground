<?php

namespace Tests\Feature;

use App\Events\WithdrawalDecided;
use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $guardian;

    private User $coGuardian;

    private User $student;

    private Programme $programme;

    private string $enrolmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $cap) {
            DB::table('admin_capabilities')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $this->ops->id,
                'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now(),
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
            'code' => 'WDL-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->enrolmentId = $this->postJson('/api/my/enrolments', [
            'programme_id' => $this->programme->id, 'student_id' => $this->student->id,
        ])->json('id');
    }

    private function pendingRequest(): string
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);

        return $this->postJson("/api/my/enrolments/{$this->enrolmentId}/withdrawal", [
            'reason' => 'family relocation',
        ])->assertStatus(201)->json('id');
    }

    public function test_full_chain_request_endorse_approve_withdrawn_with_audit(): void
    {
        Event::fake([WithdrawalDecided::class]);
        $school = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $id = $this->pendingRequest();

        // pastoral endorsement — a record, not authority: status stays pending
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($schoolAdmin);
        $this->postJson("/api/withdrawal-requests/{$id}/endorse", ['comment' => 'Family discussed with school; supported'])->assertOk();
        $this->assertSame('pending', DB::table('withdrawal_requests')->where('id', $id)->value('status'));

        // OD-26: academy operations decides; the transition applies via system job
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/withdrawal-requests/{$id}/decide", ['approve' => true, 'reason' => 'confirmed with family'])->assertOk();

        $this->assertSame('approved', DB::table('withdrawal_requests')->where('id', $id)->value('status'));
        $this->assertSame('withdrawn', DB::table('enrolments')->where('id', $this->enrolmentId)->value('status'));
        foreach (['withdrawal.requested', 'withdrawal.endorsed', 'withdrawal.approved'] as $action) {
            $this->assertDatabaseHas('audit_events', ['entity_type' => 'withdrawal_request', 'entity_id' => $id, 'action' => $action]);
        }
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'enrolment', 'entity_id' => $this->enrolmentId,
            'action' => 'enrolment.withdrawn', 'actor_id' => $this->ops->id,
        ]);
        Event::assertDispatched(WithdrawalDecided::class);
    }

    public function test_non_ops_cannot_decide(): void
    {
        $id = $this->pendingRequest();
        $configOnly = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $configOnly->id,
            'capability' => 'configuration', 'granted_by' => $this->ops->id, 'granted_at' => now(),
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($configOnly);
        $this->postJson("/api/admin/withdrawal-requests/{$id}/decide", ['approve' => true])->assertStatus(403);
        $this->assertSame('pending', DB::table('withdrawal_requests')->where('id', $id)->value('status'));
    }

    public function test_conflicting_guardian_cancel_is_referred_never_executed(): void
    {
        $id = $this->pendingRequest();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->coGuardian); // NOT the requester
        $this->postJson("/api/withdrawal-requests/{$id}/cancel")->assertStatus(409);
        $this->assertSame('pending', DB::table('withdrawal_requests')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'withdrawal_request', 'entity_id' => $id,
            'action' => 'withdrawal.conflict_referred', 'actor_id' => $this->coGuardian->id,
        ]);

        // the requester CAN cancel
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->postJson("/api/withdrawal-requests/{$id}/cancel")->assertOk();
        $this->assertSame('cancelled', DB::table('withdrawal_requests')->where('id', $id)->value('status'));
    }

    public function test_withdrawn_is_unreachable_by_direct_write(): void
    {
        $scope = app(\App\Services\Authz\ScopeContext::class);
        foreach ([$this->guardian, $this->ops] as $user) {
            $scope->set($user);
            try {
                $updated = DB::table('enrolments')->where('id', $this->enrolmentId)->update(['status' => 'withdrawn']);
                $this->assertSame(0, $updated, "{$user->role}-context UPDATE must touch zero rows (BI-7)");
            } finally {
                $scope->setSystem();
            }
        }
        $this->assertNotSame('withdrawn', DB::table('enrolments')->where('id', $this->enrolmentId)->value('status'));
    }

    public function test_duplicate_request_is_idempotent_and_decided_request_final(): void
    {
        $id = $this->pendingRequest();
        $this->assertSame($id, $this->postJson("/api/my/enrolments/{$this->enrolmentId}/withdrawal", [
            'reason' => 'second submit',
        ])->json('id'));

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/withdrawal-requests/{$id}/decide", ['approve' => false, 'reason' => 'resolved with family'])->assertOk();
        $this->postJson("/api/admin/withdrawal-requests/{$id}/decide", ['approve' => true])->assertStatus(409);
        $this->assertSame('pending_consent', DB::table('enrolments')->where('id', $this->enrolmentId)->value('status'));
    }

    public function test_five_branch_isolation_on_withdrawal_requests(): void
    {
        $school = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $otherSchoolAdmin = User::factory()->create(['role' => 'school_admin']);
        $otherSchool = School::query()->create(['name_en' => 'School B', 'name_tc' => '乙校', 'name_sc' => '乙校']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $otherSchoolAdmin->id, 'school_id' => $otherSchool->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->pendingRequest();
        $branches = [
            [$this->guardian, 1], [$this->coGuardian, 1], [$this->student, 1],
            [$schoolAdmin, 1], [$otherSchoolAdmin, 0],
            [User::factory()->create(['role' => 'guardian']), 0],
        ];
        foreach ($branches as [$user, $expected]) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user);
            $this->assertCount($expected, $this->getJson('/api/withdrawal-requests')->json('data'), "role {$user->role} #{$user->id}");
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->assertCount(0, $this->getJson('/api/withdrawal-requests')->json('data'));
    }
}
