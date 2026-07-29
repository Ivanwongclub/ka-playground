<?php

namespace Tests\Feature;

use App\Events\PaymentRequested;
use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Reconciliation\Assertions\NoSilentLapseAssertion;
use App\Services\Teams\LapseDetectionService;
use App\Services\Teams\ParkingBackstopService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class TeamResilienceTest extends TestCase
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

    /** @return array{0: Programme, 1: string, 2: string} */
    private function publishedProgramme(int $minTeam = 2, int $backstopDays = 90, int $capacity = 10): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => 'TR-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
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

        return [$programme, $templateId, $lobby];
    }

    private function pooledStudent(Programme $programme): User
    {
        app(ScopeContext::class)->set($this->ops); // an academy-admin context admits the bare guardian_link insert
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

    /** A CONFIRMED team of $size (成團'd; orders issued by the consumer). @return array{0:string,1:list<User>} */
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
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk(); // consumer (sync) issues orders
        $this->app['auth']->forgetGuards();

        return [$teamId, $members];
    }

    private function enrolmentId(Programme $programme, User $student): string
    {
        return $this->sys(fn () => DB::table('enrolments')->where('programme_id', $programme->id)->where('student_id', $student->id)->value('id'));
    }

    /** Push a member's order into the past so the lapse job/assertion see it as overdue. */
    private function makeOverdue(string $enrolmentId): void
    {
        $this->sys(fn () => DB::table('orders')->where('enrolment_id', $enrolmentId)->where('status', 'issued')
            ->update(['payment_due_at' => now()->subDays(30)]));
    }

    private function markPaid(string $enrolmentId): void
    {
        $this->sys(fn () => DB::table('orders')->where('enrolment_id', $enrolmentId)->update(['status' => 'paid']));
    }

    public function test_lapse_suspends_on_team_members_keeps_enrolment_confirmed_as_system(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$teamId, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $victim = $this->enrolmentId($programme, $members[0]);
        $this->makeOverdue($victim);

        $result = app(LapseDetectionService::class)->run();

        $this->assertSame(1, $result['lapsed']);
        $this->assertSame(1, $result['below_min']); // 2→1 active, min 2
        $this->sys(function () use ($victim, $teamId) {
            // suspension is on team_members, NOT an enrolment state
            $this->assertSame('suspended', DB::table('team_members')->where('team_id', $teamId)->where('enrolment_id', $victim)->value('status'));
            $this->assertSame('confirmed', DB::table('enrolments')->where('id', $victim)->value('status'));
            $this->assertDatabaseHas('audit_events', ['entity_type' => 'order', 'action' => 'order.lapsed', 'actor_role' => 'system']);
            $this->assertDatabaseHas('team_exceptions', ['enrolment_id' => $victim, 'type' => 'lapse', 'status' => 'open']);
            $this->assertDatabaseHas('team_exceptions', ['team_id' => $teamId, 'type' => 'below_min', 'status' => 'open']);
        });
    }

    public function test_grace_extendable_once_then_a_second_is_refused(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$teamId, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $victim = $this->enrolmentId($programme, $members[0]);
        $this->makeOverdue($victim);
        app(LapseDetectionService::class)->run(); // suspends victim → below_min

        // first grace: un-suspends, resolves the exceptions (terminal)
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/teams/{$teamId}/extend-grace", ['enrolment_id' => $victim])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertSame('active', DB::table('team_members')->where('enrolment_id', $victim)->value('status'));
        $this->assertTrue((bool) DB::table('team_members')->where('enrolment_id', $victim)->value('grace_extended'));
        $this->sys(fn () => $this->assertDatabaseHas('team_exceptions', ['enrolment_id' => $victim, 'type' => 'lapse', 'status' => 'resolved', 'resolution' => 'grace_extended']));

        // it lapses again after the extended grace also passes → re-suspend
        $this->sys(fn () => DB::table('team_members')->where('enrolment_id', $victim)->update(['grace_until' => now()->subDay()]));
        app(LapseDetectionService::class)->run();
        $this->assertSame('suspended', $this->sys(fn () => DB::table('team_members')->where('enrolment_id', $victim)->value('status')));

        // a SECOND grace is refused — grace is not a loop (OD-37)
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/teams/{$teamId}/extend-grace", ['enrolment_id' => $victim])->assertStatus(409);
        $this->app['auth']->forgetGuards();
    }

    public function test_assign_resolves_below_min_terminally(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$teamId, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $this->makeOverdue($this->enrolmentId($programme, $members[0]));
        app(LapseDetectionService::class)->run(); // below-min
        $replacement = $this->enrolmentId($programme, $this->pooledStudent($programme));

        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/teams/{$teamId}/assign", ['enrolment_id' => $replacement])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame('confirmed', DB::table('enrolments')->where('id', $replacement)->value('status'));
        $this->assertSame(2, DB::table('team_members')->where('team_id', $teamId)->where('status', 'active')->count());
        $this->sys(function () use ($teamId, $replacement) {
            $this->assertDatabaseHas('team_exceptions', ['team_id' => $teamId, 'type' => 'below_min', 'status' => 'resolved', 'resolution' => 'assigned']);
            $this->assertDatabaseHas('payment_obligations', ['enrolment_id' => $replacement, 'payer_party' => 'guardian']);
            $this->assertSame(0, DB::table('team_exceptions')->where('team_id', $teamId)->where('type', 'below_min')->where('status', 'open')->count());
        });
    }

    public function test_waive_stores_reason_and_resolves_terminally(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$teamId, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $this->makeOverdue($this->enrolmentId($programme, $members[0]));
        app(LapseDetectionService::class)->run();

        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/teams/{$teamId}/waive", ['reason' => 'Strong duo, proceed under-strength'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame('Strong duo, proceed under-strength', DB::table('teams')->where('id', $teamId)->value('waiver_reason'));
        $this->sys(fn () => $this->assertDatabaseHas('team_exceptions', ['team_id' => $teamId, 'type' => 'below_min', 'status' => 'resolved', 'resolution' => 'waived']));
    }

    public function test_dissolve_repools_paid_members_keeps_paid_and_backstop_fires_naturally(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2, backstopDays: -1);
        [$teamId, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $e0 = $this->enrolmentId($programme, $members[0]);
        $e1 = $this->enrolmentId($programme, $members[1]);
        $this->markPaid($e0);
        $this->markPaid($e1);
        // a below-min situation is not required to dissolve, but dissolve requires a confirmed team
        $claimedBefore = $this->sys(fn () => (int) DB::table('programme_capacity')->where('programme_id', $programme->id)->value('claimed'));
        $this->assertSame(2, $claimedBefore);

        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/teams/{$teamId}/dissolve")->assertOk();
        $this->app['auth']->forgetGuards();

        // both re-pooled, PAID status retained (no refund yet), seats released
        $this->assertSame('disbanded', DB::table('teams')->where('id', $teamId)->value('status'));
        foreach ([$e0, $e1] as $e) {
            $this->assertSame('in_pool', DB::table('enrolments')->where('id', $e)->value('status'));
            $this->assertSame('paid', DB::table('orders')->where('enrolment_id', $e)->value('status')); // retained, no re-charge
        }
        $this->assertSame(0, $this->sys(fn () => (int) DB::table('programme_capacity')->where('programme_id', $programme->id)->value('claimed')));

        // NOW the backstop path runs for real: roll a re-pooled PAID member → parked → backstop refunds
        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/matching/roll', ['enrolment_id' => $e0])->assertOk(); // backstop_at yesterday (backstopDays -1)
        $this->app['auth']->forgetGuards();
        $result = app(ParkingBackstopService::class)->run();

        $this->assertSame(1, $result['refunded']);
        $this->assertSame('released', DB::table('enrolments')->where('id', $e0)->value('status'));
        $this->sys(function () use ($e0) {
            $order = DB::table('orders')->where('enrolment_id', $e0)->first();
            $this->assertSame('refunded', $order->status);
            $this->assertDatabaseHas('refunds', ['order_id' => $order->id, 'origin' => 'backstop_auto', 'status' => 'confirmed']);
        });
    }

    public function test_repooled_paid_member_re_teams_without_recharge_or_crash(): void
    {
        // The path dissolution creates: a PAID member re-pooled to in_pool, who then
        // joins a NEW team and 成團s. It must NOT crash on orders_one_live_per_enrolment
        // and must NOT re-charge — seat claimed + confirmed, but no second order (OD-38).
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$teamA, $membersA] = $this->confirmedTeam($programme, $lobby, 2);
        $paidEnrolment = $this->enrolmentId($programme, $membersA[0]);
        $this->markPaid($paidEnrolment);
        $this->markPaid($this->enrolmentId($programme, $membersA[1]));
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/teams/{$teamA}/dissolve")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertSame('in_pool', DB::table('enrolments')->where('id', $paidEnrolment)->value('status'));
        $this->assertSame('paid', DB::table('orders')->where('enrolment_id', $paidEnrolment)->value('status'));

        // the re-pooled PAID member joins a NEW team B (fresh member fills min 2)
        Event::fake([PaymentRequested::class]); // count only team B's requests
        $fresh = $this->pooledStudent($programme);
        Sanctum::actingAs($fresh);
        $teamB = $this->postJson('/api/my/teams', ['programme_id' => $programme->id, 'category_id' => $lobby, 'name' => 'TeamB'.Str::random(3)])->json('id');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($membersA[0]);
        $this->postJson("/api/teams/{$teamB}/join")->assertOk(); // in_pool → teamed (re-team)
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($fresh);
        $this->postJson("/api/teams/{$teamB}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamB}/confirm")->assertOk(); // MUST NOT crash
        $this->app['auth']->forgetGuards();

        // paid member: confirmed into team B, seat claimed, still exactly ONE (paid) order — no re-charge
        $this->assertSame('confirmed', DB::table('enrolments')->where('id', $paidEnrolment)->value('status'));
        $this->assertSame(1, DB::table('orders')->where('enrolment_id', $paidEnrolment)->count());
        $this->assertSame('paid', DB::table('orders')->where('enrolment_id', $paidEnrolment)->value('status'));
        $this->assertDatabaseHas('team_members', ['team_id' => $teamB, 'enrolment_id' => $paidEnrolment, 'status' => 'active']);
        // fresh member DID get an order + exactly one payment request fired (fresh only, not the paid re-teamed one)
        $freshEnrolment = $this->enrolmentId($programme, $fresh);
        $this->assertSame(1, $this->sys(fn () => DB::table('orders')->where('enrolment_id', $freshEnrolment)->count()));
        Event::assertDispatchedTimes(PaymentRequested::class, 1);
    }

    public function test_dissolve_cancels_unpaid_orders(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$teamId, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $paid = $this->enrolmentId($programme, $members[0]);
        $unpaid = $this->enrolmentId($programme, $members[1]);
        $this->markPaid($paid); // members[1] stays issued (unpaid)

        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/teams/{$teamId}/dissolve")->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame('paid', DB::table('orders')->where('enrolment_id', $paid)->value('status'));
        $this->assertSame('cancelled', DB::table('orders')->where('enrolment_id', $unpaid)->value('status'));
    }

    public function test_no_silent_lapse_assertion_red_when_unrecorded_green_when_job_runs(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $victim = $this->enrolmentId($programme, $members[0]);

        // healthy (no overdue order) → vacuous green
        $this->assertTrue($this->sys(fn () => (new NoSilentLapseAssertion)->check()->passed));

        // overdue but the lapse job has NOT run → SILENT lapse → RED
        $this->makeOverdue($victim);
        $red = $this->sys(fn () => (new NoSilentLapseAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('SILENT', $red->details);

        // run the lapse job → suspension + audit + exception → GREEN
        app(LapseDetectionService::class)->run();
        $this->assertTrue($this->sys(fn () => (new NoSilentLapseAssertion)->check()->passed), 'recording the lapse restores green');
    }

    public function test_school_leave_records_exception_and_team_stands(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$teamId, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $leaver = $this->enrolmentId($programme, $members[0]);

        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/team-members/{$leaver}/school-leave")->assertOk();
        $this->app['auth']->forgetGuards();

        // team STANDS — no cascade (OD-62)
        $this->assertSame('confirmed', DB::table('teams')->where('id', $teamId)->value('status'));
        $this->assertSame('active', DB::table('team_members')->where('enrolment_id', $leaver)->value('status'));
        $this->assertSame('confirmed', DB::table('enrolments')->where('id', $leaver)->value('status'));
        $this->sys(fn () => $this->assertDatabaseHas('team_exceptions', ['enrolment_id' => $leaver, 'type' => 'school_leave', 'status' => 'open']));
    }

    public function test_resolution_actions_require_operations_capability(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$teamId, $members] = $this->confirmedTeam($programme, $lobby, 2);
        $this->makeOverdue($this->enrolmentId($programme, $members[0]));
        app(LapseDetectionService::class)->run();

        $configOnly = User::factory()->create(['role' => 'academy_admin']);
        $this->sys(fn () => DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $configOnly->id, 'capability' => 'configuration', 'granted_by' => $configOnly->id, 'granted_at' => now()]));
        Sanctum::actingAs($configOnly);
        $this->postJson("/api/admin/teams/{$teamId}/waive", ['reason' => 'nope'])->assertStatus(403);
        $this->postJson("/api/admin/teams/{$teamId}/dissolve")->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->assertSame('confirmed', DB::table('teams')->where('id', $teamId)->value('status'));
    }
}
