<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Reconciliation\Assertions\RefundBackstopProvenanceAssertion;
use App\Services\Teams\FormationDeadlineService;
use App\Services\Teams\ParkingBackstopService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class DeadlineMatchingTest extends TestCase
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

    /** Run a closure with a direct SYSTEM DB context (for fixture writes + scoped reads in assertions). */
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

    /** @return array{0: Programme, 1: string, 2: string} programme, templateId, lobbyId */
    private function publishedProgramme(int $minTeam = 2, int $backstopDays = 90, int $capacity = 10): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => 'DM-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
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

    /** A consented, unteamed (in_pool) student in the programme. */
    private function pooledStudent(Programme $programme): User
    {
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

    /** A FORMING team of $size consented members in the lobby (never submitted). */
    private function formingTeam(Programme $programme, string $lobby, int $size): array
    {
        $creator = $this->pooledStudent($programme);
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $programme->id, 'category_id' => $lobby, 'name' => 'Team'.Str::random(4)])->json('id');
        $this->app['auth']->forgetGuards();
        for ($i = 1; $i < $size; $i++) {
            $m = $this->pooledStudent($programme);
            Sanctum::actingAs($m);
            $this->postJson("/api/teams/{$teamId}/join")->assertOk();
            $this->app['auth']->forgetGuards();
        }

        return [$teamId, $creator];
    }

    private function enrolmentId(Programme $programme, User $student): string
    {
        return $this->sys(fn () => DB::table('enrolments')->where('programme_id', $programme->id)->where('student_id', $student->id)->value('id'));
    }

    public function test_deadline_auto_submits_compliant_and_flags_noncompliant(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$compliant] = $this->formingTeam($programme, $lobby, 2);   // meets min
        [$short] = $this->formingTeam($programme, $lobby, 1);       // below min

        $result = app(FormationDeadlineService::class)->run();

        $this->assertSame(1, $result['auto_submitted']);
        $this->assertSame(1, $result['flagged']);
        $this->assertSame('submitted', DB::table('teams')->where('id', $compliant)->value('status'));
        $this->assertSame('forming', DB::table('teams')->where('id', $short)->value('status'));
        $this->sys(function () use ($programme, $short, $compliant) {
            $this->assertDatabaseHas('team_exceptions', ['team_id' => $short, 'type' => 'deadline_noncompliant', 'status' => 'open']);
            $this->assertDatabaseHas('audit_events', ['entity_type' => 'team', 'entity_id' => $compliant, 'action' => 'team.auto_submitted', 'actor_role' => 'system']);
            $this->assertSame(0, DB::table('team_exceptions')->where('team_id', $compliant)->count());
        });
        // idempotent: a second run neither re-submits nor duplicates the exception
        $again = app(FormationDeadlineService::class)->run();
        $this->assertSame(0, $again['auto_submitted']);
        $this->sys(fn () => $this->assertSame(1, DB::table('team_exceptions')->where('team_id', $short)->count()));
    }

    public function test_match_places_unplaced_student_and_makes_team_ready(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        [$short] = $this->formingTeam($programme, $lobby, 1); // below min
        app(FormationDeadlineService::class)->run(); // raises the deadline exception
        $unplaced = $this->pooledStudent($programme);
        $enrolmentId = $this->enrolmentId($programme, $unplaced);

        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/matching/match', ['enrolment_id' => $enrolmentId, 'team_id' => $short])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame('teamed', DB::table('enrolments')->where('id', $enrolmentId)->value('status'));
        $this->assertDatabaseHas('team_members', ['team_id' => $short, 'student_id' => $unplaced->id, 'status' => 'active']);
        // reached minimum → auto-submitted and the deadline exception resolved
        $this->assertSame('submitted', DB::table('teams')->where('id', $short)->value('status'));
        $this->sys(fn () => $this->assertDatabaseHas('team_exceptions', ['team_id' => $short, 'type' => 'deadline_noncompliant', 'status' => 'resolved', 'resolution' => 'matched']));
    }

    public function test_roll_parks_with_backstop_and_refuses_double_park(): void
    {
        [$programme, , ] = $this->publishedProgramme(minTeam: 2, backstopDays: 90);
        $unplaced = $this->pooledStudent($programme);
        $enrolmentId = $this->enrolmentId($programme, $unplaced);

        Sanctum::actingAs($this->ops);
        $exId = $this->postJson('/api/admin/matching/roll', ['enrolment_id' => $enrolmentId])->assertOk()->json('exception_id');

        $this->assertSame('in_pool', DB::table('enrolments')->where('id', $enrolmentId)->value('status')); // parked, still pooled
        $this->sys(function () use ($exId, $enrolmentId) {
            $row = DB::table('team_exceptions')->where('id', $exId)->first();
            $this->assertSame('parked_rollforward', $row->type);
            $this->assertSame('open', $row->status);
            $this->assertNotNull($row->backstop_at);
            $this->assertSame($enrolmentId, $row->enrolment_id);
        });
        // a second roll refuses (already parked)
        $this->postJson('/api/admin/matching/roll', ['enrolment_id' => $enrolmentId])->assertStatus(409);
        $this->app['auth']->forgetGuards();
    }

    public function test_release_releases_unplaced_and_refuses_paid(): void
    {
        [$programme, , ] = $this->publishedProgramme();
        $unplaced = $this->pooledStudent($programme);
        $enrolmentId = $this->enrolmentId($programme, $unplaced);

        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/matching/release', ['enrolment_id' => $enrolmentId])->assertOk();
        $this->assertSame('released', DB::table('enrolments')->where('id', $enrolmentId)->value('status'));
        $this->app['auth']->forgetGuards();

        // a paid, still-pooled enrolment cannot be silently released
        $paidStudent = $this->pooledStudent($programme);
        $paidEnrolment = $this->enrolmentId($programme, $paidStudent);
        $this->sys(fn () => DB::table('orders')->insert([
            'id' => (string) Str::uuid7(), 'enrolment_id' => $paidEnrolment, 'programme_id' => $programme->id,
            'student_id' => $paidStudent->id, 'payer_party' => 'guardian', 'total_amount_minor' => 250000,
            'currency' => 'HKD', 'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]));
        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/matching/release', ['enrolment_id' => $paidEnrolment])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->assertSame('in_pool', DB::table('enrolments')->where('id', $paidEnrolment)->value('status'));
    }

    public function test_backstop_fires_full_refund_and_release_for_paid(): void
    {
        // backstopDays = -1 → roll parks with backstop_at in the past (already due)
        [$programme, , ] = $this->publishedProgramme(backstopDays: -1);
        $student = $this->pooledStudent($programme);
        $enrolmentId = $this->enrolmentId($programme, $student);
        // a paid, re-pooled member (the STEP-4 dissolution outcome, constructed here as a fixture)
        $this->sys(fn () => DB::table('orders')->insert([
            'id' => (string) Str::uuid7(), 'enrolment_id' => $enrolmentId, 'programme_id' => $programme->id,
            'student_id' => $student->id, 'payer_party' => 'guardian', 'total_amount_minor' => 250000,
            'currency' => 'HKD', 'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]));
        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/matching/roll', ['enrolment_id' => $enrolmentId])->assertOk(); // backstop_at = yesterday
        $this->app['auth']->forgetGuards();

        $result = app(ParkingBackstopService::class)->run();

        $this->assertSame(1, $result['refunded']);
        $this->assertSame(1, $result['released']);
        $this->assertSame('released', DB::table('enrolments')->where('id', $enrolmentId)->value('status'));
        $this->sys(function () use ($enrolmentId) {
            $order = DB::table('orders')->where('enrolment_id', $enrolmentId)->first();
            $this->assertSame('refunded', $order->status);
            $refund = DB::table('refunds')->where('order_id', $order->id)->first();
            $this->assertNotNull($refund, 'a backstop refund must exist');
            $this->assertSame('backstop_auto', $refund->origin);
            $this->assertSame(250000, (int) $refund->amount_minor); // FULL (OD-48)
            $this->assertSame('confirmed', $refund->status);
            $this->assertNull($refund->withdrawal_request_id);
            $this->assertNull($refund->requested_by);
            $this->assertNull($refund->approved_by);
            $this->assertNull($refund->confirmed_by); // no human recorder/confirmer — out of BI-9 (OD-47)
            $this->assertDatabaseHas('audit_events', ['entity_type' => 'refund', 'entity_id' => $refund->id, 'action' => 'refund.auto_confirmed', 'actor_role' => 'system']);
            $this->assertDatabaseHas('team_exceptions', ['enrolment_id' => $enrolmentId, 'status' => 'auto_released', 'resolution' => 'auto_refund_release']);
        });
    }

    public function test_backstop_releases_unpaid_without_refund(): void
    {
        [$programme, , ] = $this->publishedProgramme(backstopDays: -1);
        $student = $this->pooledStudent($programme);
        $enrolmentId = $this->enrolmentId($programme, $student);
        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/matching/roll', ['enrolment_id' => $enrolmentId])->assertOk();
        $this->app['auth']->forgetGuards();

        $result = app(ParkingBackstopService::class)->run();

        $this->assertSame(0, $result['refunded']);
        $this->assertSame(1, $result['released']);
        $this->assertSame('released', DB::table('enrolments')->where('id', $enrolmentId)->value('status'));
        $this->sys(function () use ($enrolmentId) {
            $this->assertSame(0, DB::table('refunds')->count());
            $this->assertDatabaseHas('team_exceptions', ['enrolment_id' => $enrolmentId, 'status' => 'auto_released', 'resolution' => 'auto_release']);
        });
    }

    public function test_backstop_provenance_assertion_has_teeth_green_red_green(): void
    {
        // both pooled students created up front (while the context still admits the bare guardian_link insert)
        [$programme, , ] = $this->publishedProgramme(backstopDays: -1);
        $student = $this->pooledStudent($programme);
        $enrolmentId = $this->enrolmentId($programme, $student);
        app(ScopeContext::class)->setSystem(); // an academy-admin context (as publishedProgramme leaves) admits the bare guardian_link insert
        $orphan = $this->pooledStudent($programme);          // pooled but NEVER parked
        $orphanEnrolment = $this->enrolmentId($programme, $orphan);

        // healthy: a real backstop refund traces to its lapsed parking → green
        $this->sys(fn () => DB::table('orders')->insert([
            'id' => (string) Str::uuid7(), 'enrolment_id' => $enrolmentId, 'programme_id' => $programme->id,
            'student_id' => $student->id, 'payer_party' => 'guardian', 'total_amount_minor' => 250000,
            'currency' => 'HKD', 'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]));
        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/matching/roll', ['enrolment_id' => $enrolmentId])->assertOk();
        $this->app['auth']->forgetGuards();
        app(ParkingBackstopService::class)->run();
        $this->assertTrue($this->sys(fn () => (new RefundBackstopProvenanceAssertion)->check()->passed), 'healthy backstop refund must pass provenance');

        // plant a backstop_auto refund with NO parked cause → RED. Refunds have no
        // DELETE policy (financial rows are non-deletable), so "remove" is a savepoint
        // rollback — it un-persists the planted row exactly, RLS notwithstanding.
        DB::beginTransaction();
        $this->sys(function () use ($programme, $orphanEnrolment, $orphan) {
            $orderId = (string) Str::uuid7();
            DB::table('orders')->insert([
                'id' => $orderId, 'enrolment_id' => $orphanEnrolment, 'programme_id' => $programme->id,
                'student_id' => $orphan->id, 'payer_party' => 'guardian', 'total_amount_minor' => 250000,
                'currency' => 'HKD', 'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('refunds')->insert([
                'id' => (string) Str::uuid7(), 'order_id' => $orderId, 'withdrawal_request_id' => null, 'origin' => 'backstop_auto',
                'amount_minor' => 250000, 'currency' => 'HKD', 'destination_party' => 'guardian', 'status' => 'confirmed',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $red = $this->sys(fn () => (new RefundBackstopProvenanceAssertion)->check());
        $this->assertFalse($red->passed, 'a backstop_auto refund with no parked cause must fail the assertion');
        $this->assertStringContainsString('provenance', $red->details);

        // remove the planted rows → GREEN again
        DB::rollBack();
        $this->assertTrue($this->sys(fn () => (new RefundBackstopProvenanceAssertion)->check()->passed), 'removing the orphan restores green');
    }

    public function test_matching_requires_operations_capability(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme(minTeam: 2);
        $unplaced = $this->pooledStudent($programme);
        $enrolmentId = $this->enrolmentId($programme, $unplaced);
        // a config-only academy admin cannot act on the matching screen
        $configOnly = User::factory()->create(['role' => 'academy_admin']);
        $this->sys(fn () => DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $configOnly->id, 'capability' => 'configuration', 'granted_by' => $configOnly->id, 'granted_at' => now()]));

        Sanctum::actingAs($configOnly);
        $this->postJson('/api/admin/matching/roll', ['enrolment_id' => $enrolmentId])->assertStatus(403);
        $this->postJson('/api/admin/matching/release', ['enrolment_id' => $enrolmentId])->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->assertSame('in_pool', DB::table('enrolments')->where('id', $enrolmentId)->value('status'));
    }
}
