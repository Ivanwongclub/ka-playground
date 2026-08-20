<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Reconciliation\Assertions\CapacityClaimsWholeAssertion;
use App\Services\Reconciliation\Assertions\CapacityConservationAssertion;
use App\Services\Reconciliation\Assertions\ConsentCompleteAtConfirmAssertion;
use App\Services\Reconciliation\Assertions\PoolNoExpiredParkingAssertion;
use App\Services\Reconciliation\Assertions\TeamSizeOrWaiverAssertion;
use App\Services\Teams\ParkingBackstopService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

/** S05-6 gate: each new S05 assertion has real red-then-green teeth. */
class S05GateAssertionsTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $c, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
    }

    private function sys(callable $fn): mixed
    {
        $scope = app(ScopeContext::class);
        $scope->setSystem();
        try {
            return $fn();
        } finally {
            $scope->reset();
        }
    }

    /** @return array{0: Programme, 1: string} programme, lobby */
    private function publishedProgramme(int $minTeam = 2, int $backstopDays = 90, int $capacity = 10): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => 'GA-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'],
                'eligibility' => ['capacity' => $capacity],
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'team_rules' => ['formation_deadline_on' => '2026-06-20', 'min_team_size' => $minTeam, 'parking_backstop_days' => $backstopDays],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$k}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/fee-items", ['name_en' => 'Fee', 'name_tc' => '費', 'name_sc' => '费', 'amount_minor' => 250000, 'currency' => 'HKD'])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $lobby = $this->postJson("/api/admin/programmes/{$programme->id}/team-categories", ['name_en' => 'Open', 'name_tc' => '開', 'name_sc' => '开', 'assignment_rule' => 'open', 'is_default' => true])->json('id');
        $this->app['auth']->forgetGuards();

        return [$programme, $lobby];
    }

    private function pooledStudent(Programme $programme): User
    {
        app(ScopeContext::class)->setSystem();
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
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

    /** @return array{0:string,1:list<User>} teamId, members */
    private function confirmedTeam(Programme $programme, string $lobby, int $size): array
    {
        $creator = $this->pooledStudent($programme);
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $programme->id, 'category_id' => $lobby, 'name' => 'Team'.Str::random(4)])->json('id');
        $this->app['auth']->forgetGuards();
        $members = [$creator];
        for ($i = 1; $i < $size; $i++) {
            $m = $this->pooledStudent($programme);
            Sanctum::actingAs($m);
            $this->postJson("/api/teams/{$teamId}/join")->assertOk();
            $this->app['auth']->forgetGuards();
            $members[] = $m;
        }
        Sanctum::actingAs($creator);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $this->app['auth']->forgetGuards();

        return [$teamId, $members];
    }

    private function enrolmentId(Programme $programme, User $student): string
    {
        return $this->sys(fn () => DB::table('enrolments')->where('programme_id', $programme->id)->where('student_id', $student->id)->value('id'));
    }

    public function test_capacity_conservation_reds_on_a_planted_overbook(): void
    {
        // capacity 2, a confirmed team of 2 → 2 held ≤ 2 → green
        [$programme, $lobby] = $this->publishedProgramme(minTeam: 2, capacity: 2);
        [$teamId, ] = $this->confirmedTeam($programme, $lobby, 2);
        $this->assertTrue($this->sys(fn () => (new CapacityConservationAssertion)->check()->passed));

        // plant an overbook: a 3rd active member in the confirmed team (3 held > 2) — savepoint so it un-plants
        $extra = $this->pooledStudent($programme);
        $extraEnrolment = $this->enrolmentId($programme, $extra);
        DB::beginTransaction();
        $this->sys(fn () => DB::table('team_members')->insert([
            'id' => (string) Str::uuid7(), 'team_id' => $teamId, 'programme_id' => $programme->id, 'enrolment_id' => $extraEnrolment,
            'category_id' => $lobby, 'student_id' => $extra->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $red = $this->sys(fn () => (new CapacityConservationAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('OVERBOOK', $red->details);
        DB::rollBack();

        $this->assertTrue($this->sys(fn () => (new CapacityConservationAssertion)->check()->passed), 'removing the overbook restores green');
    }

    public function test_claims_are_whole_reds_on_a_partial_claim_record(): void
    {
        [$programme, $lobby] = $this->publishedProgramme(minTeam: 2);
        $this->confirmedTeam($programme, $lobby, 2); // a whole claim (seats = members) → green
        $this->assertTrue($this->sys(fn () => (new CapacityClaimsWholeAssertion)->check()->passed));

        // plant a team.confirmed audit whose claim was PARTIAL (seats ≠ members)
        DB::beginTransaction();
        $this->sys(fn () => DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'team', 'entity_id' => (string) Str::uuid7(),
            'action' => 'team.confirmed', 'actor_role' => 'academy_admin', 'programme_id' => $programme->id,
            'payload_after' => json_encode(['seats_claimed' => 3, 'member_count' => 2]), 'request_id' => (string) Str::uuid7(),
        ]));
        $red = $this->sys(fn () => (new CapacityClaimsWholeAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('partial claim', $red->details);
        DB::rollBack();

        $this->assertTrue($this->sys(fn () => (new CapacityClaimsWholeAssertion)->check()->passed));
    }

    public function test_consent_complete_at_confirm_reds_on_a_confirm_without_consent(): void
    {
        [$programme, $lobby] = $this->publishedProgramme(minTeam: 2);
        $this->confirmedTeam($programme, $lobby, 2); // members consented before confirm → green
        $this->assertTrue($this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check()->passed));

        // plant a confirm event for an enrolment whose student never signed consent
        $orphan = User::factory()->create(['role' => 'student']);
        DB::beginTransaction();
        $this->sys(function () use ($programme, $orphan) {
            $enrolId = (string) Str::uuid7();
            DB::table('enrolments')->insert([
                'id' => $enrolId, 'programme_id' => $programme->id, 'student_id' => $orphan->id,
                'acting_guardian_id' => $orphan->id, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'enrolment', 'entity_id' => $enrolId,
                'action' => 'enrolment.confirmed', 'actor_role' => 'academy_admin', 'programme_id' => $programme->id,
                'request_id' => (string) Str::uuid7(),
            ]);
        });
        $red = $this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('consent signature', $red->details);
        DB::rollBack();

        $this->assertTrue($this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check()->passed));
    }

    public function test_consent_complete_at_confirm_reds_on_a_signature_superseded_before_confirm(): void
    {
        // The STALE case: a member's ONLY signature had its request SUPERSEDED
        // before the confirm event — signed, but not valid at Team Formation.
        [$programme, $lobby] = $this->publishedProgramme(minTeam: 2);
        [, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $this->assertTrue($this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check()->passed));

        $member = $members[0];
        $enrolmentId = $this->enrolmentId($programme, $member);
        [$requestId, $confirmedAt] = $this->sys(fn () => [
            DB::table('consent_requests')->where('student_id', $member->id)->where('programme_id', $programme->id)->where('status', 'signed')->value('id'),
            DB::table('audit_events')->where('entity_type', 'enrolment')->where('entity_id', $enrolmentId)->where('action', 'enrolment.confirmed')->value('occurred_at'),
        ]);
        $this->assertNotNull($requestId);

        // plant a supersede audit for that request, dated ONE SECOND BEFORE the confirm → stale at confirm
        DB::beginTransaction();
        $this->sys(fn () => DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid7(), 'occurred_at' => \Illuminate\Support\Carbon::parse($confirmedAt)->subSecond(),
            'entity_type' => 'consent_request', 'entity_id' => $requestId, 'action' => 'consent_request.superseded',
            'from_state' => 'signed', 'to_state' => 'superseded', 'actor_role' => 'academy_admin',
            'programme_id' => $programme->id, 'request_id' => (string) Str::uuid7(),
        ]));
        $red = $this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check());
        $this->assertFalse($red->passed, 'a signature superseded before its confirm event must red (stale at 成團)');
        $this->assertStringContainsString('consent signature', $red->details);
        DB::rollBack();

        // a supersede AFTER confirm (the normal re-consent case) must NOT red
        $this->sys(fn () => DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid7(), 'occurred_at' => \Illuminate\Support\Carbon::parse($confirmedAt)->addDay(),
            'entity_type' => 'consent_request', 'entity_id' => $requestId, 'action' => 'consent_request.superseded',
            'from_state' => 'signed', 'to_state' => 'superseded', 'actor_role' => 'academy_admin',
            'programme_id' => $programme->id, 'request_id' => (string) Str::uuid7(),
        ]));
        $this->assertTrue($this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check()->passed), 'a supersede AFTER confirm does not red a correctly-confirmed team');
    }

    public function test_size_or_waiver_reds_below_min_then_greens_on_waiver(): void
    {
        [$programme, $lobby] = $this->publishedProgramme(minTeam: 2);
        [$teamId, $members] = $this->confirmedTeam($programme, $lobby, 2); // 2 members, min 2 → green
        $this->assertTrue($this->sys(fn () => (new TeamSizeOrWaiverAssertion)->check()->passed));

        // drop a member below the minimum, no waiver → RED
        $victim = $this->enrolmentId($programme, $members[1]);
        $this->sys(fn () => DB::table('team_members')->where('team_id', $teamId)->where('enrolment_id', $victim)->update(['status' => 'removed']));
        $red = $this->sys(fn () => (new TeamSizeOrWaiverAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('below minimum', $red->details);

        // record a waiver → GREEN ("meets rules OR waiver")
        $this->sys(fn () => DB::table('teams')->where('id', $teamId)->update(['waiver_reason' => 'proceed under-strength']));
        $this->assertTrue($this->sys(fn () => (new TeamSizeOrWaiverAssertion)->check()->passed), 'a waiver restores green');
    }

    public function test_team_capacity_report_reflects_state(): void
    {
        [$programme, $lobby] = $this->publishedProgramme(minTeam: 2, capacity: 5);
        [$teamId, ] = $this->confirmedTeam($programme, $lobby, 2);
        // audit element reader: an academy admin with operations + audit_read + finance
        $auditor = User::factory()->create(['role' => 'academy_admin']);
        $this->sys(function () use ($auditor) {
            foreach (['operations', 'audit_read', 'finance'] as $c) {
                DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $auditor->id, 'capability' => $c, 'granted_by' => $auditor->id, 'granted_at' => now()]);
            }
        });

        Sanctum::actingAs($auditor);
        $r = $this->getJson("/api/admin/programmes/{$programme->id}/team-capacity-report")->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $this->assertSame(5, $r['capacity']['capacity']);
        $this->assertSame(2, $r['capacity']['claimed']);
        $this->assertSame(3, $r['capacity']['free']);
        $this->assertSame('2026-06-20', $r['capacity']['formation_deadline_on']);
        $this->assertCount(1, $r['confirm_log']);
        $this->assertSame($teamId, $r['confirm_log'][0]['team_id']);
        $this->assertSame(2, $r['confirm_log'][0]['seats_claimed']);
        $this->assertSame(2, $r['confirm_log'][0]['member_count']);
    }

    public function test_no_expired_parking_reds_until_the_backstop_sweeps(): void
    {
        // backstopDays -1 → roll parks with backstop_at already in the past
        [$programme, $lobby] = $this->publishedProgramme(minTeam: 2, backstopDays: -1);
        $student = $this->pooledStudent($programme);
        $enrolmentId = $this->enrolmentId($programme, $student);
        $this->assertTrue($this->sys(fn () => (new PoolNoExpiredParkingAssertion)->check()->passed));

        // an OPEN parked roll-forward past its backstop, not yet swept → RED
        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/matching/roll', ['enrolment_id' => $enrolmentId])->assertOk();
        $this->app['auth']->forgetGuards();
        $red = $this->sys(fn () => (new PoolNoExpiredParkingAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('past their backstop', $red->details);

        // the sweep runs → auto_released → GREEN
        app(ParkingBackstopService::class)->run();
        $this->assertTrue($this->sys(fn () => (new PoolNoExpiredParkingAssertion)->check()->passed), 'the backstop sweep restores green');
    }
}
