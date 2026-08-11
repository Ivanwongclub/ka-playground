<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-UX3-3a STEP 4 — below-min / matching + resolution.
 * Backend deltas B4 (matching screen: additive student names + min) and B5 (capacity report: additive
 * approver/student/waived_by names) — S-UX2b LEFT joins, count-preserving, double-gated by users_read,
 * resolved WITHIN the caller's RLS (NO elevation). Plus the write authority (OD-37 academy operations —
 * NARROWER than Team Formation's OD-39) and each terminal action's representative refusal.
 */
class TeamResolutionScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $guardian;      // non-academy-operator (for the 403 authority branch)

    private int $programmeId;

    private string $confTeam;    // a confirmed team (waiver target)

    private string $formTeam;    // a forming team (wrong-state target)

    private string $unplacedEnrol;

    private string $memberEnrol;

    protected function setUp(): void
    {
        parent::setUp();
        // operations = the OD-37 write authority; audit_read so the RLS-shaped confirm_log (which reads
        // audit_events) is visible — without it that branch is legitimately empty for a pure-ops admin.
        $this->ops = User::factory()->create(['role' => 'academy_admin', 'name' => 'Otis Operator']);
        foreach (['operations', 'audit_read'] as $cap) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        $this->guardian = User::factory()->create(['role' => 'guardian', 'name' => 'Gwen Guardian']);

        [$this->programmeId, $this->confTeam, $this->formTeam, $this->unplacedEnrol, $this->memberEnrol] = $this->sys(function () {
            $pid = DB::table('programmes')->insertGetId(['code' => 'RS'.Str::random(4), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('wizard_sections')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $pid, 'section_key' => 'team_rules', 'status' => 'complete', 'data' => json_encode(['min_team_size' => 3, 'formation_deadline_on' => now()->addDays(10)->toDateString()]), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('programme_capacity')->insert(['programme_id' => $pid, 'capacity' => 30, 'claimed' => 2, 'created_at' => now(), 'updated_at' => now()]);
            $lobby = (string) Str::uuid7();
            DB::table('team_categories')->insert(['id' => $lobby, 'programme_id' => $pid, 'name_en' => 'Lobby', 'name_tc' => 'Lobby', 'name_sc' => 'Lobby', 'assignment_rule' => 'open', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

            $mkEnrol = function (string $name, string $status) use ($pid) {
                $s = User::factory()->create(['role' => 'student', 'name' => $name]);
                $g = User::factory()->create(['role' => 'guardian']);
                $e = (string) Str::uuid7();
                DB::table('enrolments')->insert(['id' => $e, 'programme_id' => $pid, 'student_id' => $s->id, 'acting_guardian_id' => $g->id, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

                return [$e, $s->id];
            };

            // confirmed team with a waiver, recorded by ops
            $conf = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $conf, 'programme_id' => $pid, 'category_id' => $lobby, 'name' => 'Confirmed Crew', 'status' => 'confirmed', 'created_by' => $this->ops->id, 'waiver_reason' => 'below minimum accepted', 'waived_by' => $this->ops->id, 'waived_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            [$memberEnrol, $memberStu] = $mkEnrol('Mia Member', 'confirmed');
            DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'team_id' => $conf, 'enrolment_id' => $memberEnrol, 'category_id' => $lobby, 'student_id' => $memberStu, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            // the immutable Team Formation audit event backing the confirm log (approver = ops)
            DB::table('audit_events')->insert(['event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'actor_id' => $this->ops->id, 'actor_role' => 'academy_admin', 'entity_type' => 'team', 'entity_id' => $conf, 'action' => 'team.confirmed', 'to_state' => 'confirmed', 'programme_id' => $pid, 'payload_after' => json_encode(['seats_claimed' => 1, 'member_count' => 1])]);

            // forming under-strength team (1 member vs min 3)
            $form = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $form, 'programme_id' => $pid, 'category_id' => $lobby, 'name' => 'Forming Few', 'status' => 'forming', 'created_by' => $this->ops->id, 'created_at' => now(), 'updated_at' => now()]);
            [$fEnrol, $fStu] = $mkEnrol('Fred Forming', 'teamed');
            DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'team_id' => $form, 'enrolment_id' => $fEnrol, 'category_id' => $lobby, 'student_id' => $fStu, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

            // an unplaced (in_pool, not teamed) student
            [$unplaced] = $mkEnrol('Uma Unplaced', 'in_pool');

            // a parked_rollforward exception (student-scoped) + a below_min exception (team-scoped, NO enrolment)
            [$parkedEnrol] = $mkEnrol('Pete Parked', 'in_pool');
            DB::table('team_exceptions')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $pid, 'type' => 'parked_rollforward', 'status' => 'open', 'enrolment_id' => $parkedEnrol, 'backstop_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_exceptions')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $pid, 'type' => 'below_min', 'status' => 'open', 'team_id' => $conf, 'enrolment_id' => null, 'created_at' => now(), 'updated_at' => now()]);

            return [$pid, $conf, $form, $unplaced, $memberEnrol];
        });
    }

    // ── B4: matching screen names + min + count-preservation ────────────────────────────────────────
    public function test_b4_matching_carries_names_and_min_and_is_count_preserving(): void
    {
        $this->act($this->ops);
        $body = $this->getJson("/api/admin/programmes/{$this->programmeId}/matching")->assertOk()->json();

        $this->assertSame(3, $body['min_team_size']);

        $unplaced = collect($body['unplaced_students']);
        $this->assertCount(2, $unplaced); // Uma + Pete are in_pool & unteamed — LEFT join added names, dropped none
        $uma = $unplaced->firstWhere('student_name', 'Uma Unplaced');
        $this->assertArrayHasKey('id', $uma);        // prior keys intact
        $this->assertArrayHasKey('student_id', $uma);

        $parked = collect($body['parked']);
        $this->assertSame('Pete Parked', $parked->first()['student_name']);
        $this->assertNotNull($parked->first()['backstop_at']);
    }

    // ── B5: capacity report names + the double-gated NULL-name count-preservation tooth ──────────────
    public function test_b5_capacity_report_carries_names_and_left_join_is_count_preserving(): void
    {
        $this->act($this->ops);
        $body = $this->getJson("/api/admin/programmes/{$this->programmeId}/team-capacity-report")->assertOk()->json();

        // approver name on the confirm log
        $this->assertSame('Otis Operator', collect($body['confirm_log'])->firstWhere('team_id', $this->confTeam)['approver_name']);
        // waived_by name on the waiver register
        $this->assertSame('Otis Operator', collect($body['waiver_register'])->firstWhere('team_id', $this->confTeam)['waived_by_name']);

        $ledger = collect($body['exception_ledger']);
        // parked row: student named + a backstop countdown
        $parked = $ledger->firstWhere('type', 'parked_rollforward');
        $this->assertSame('Pete Parked', $parked['student_name']);
        $this->assertNotNull($parked['days_to_backstop']);
        // the team-scoped below_min row has NO enrolment → student_name is NULL, but the row SURVIVES
        // (the LEFT join is count-preserving, and the raw id is never surfaced as a name).
        $belowMin = $ledger->firstWhere('type', 'below_min');
        $this->assertNotNull($belowMin, 'a team-scoped exception is not dropped by the name join');
        $this->assertNull($belowMin['student_name']);
    }

    // ── write authority: OD-37 academy operations (narrower than Team Formation's OD-39) ────────────────────────
    public function test_resolution_writes_require_an_academy_operator(): void
    {
        $this->act($this->guardian);
        $this->postJson("/api/admin/teams/{$this->confTeam}/assign", ['enrolment_id' => $this->unplacedEnrol])->assertForbidden();
        $this->postJson("/api/admin/teams/{$this->confTeam}/extend-grace", ['enrolment_id' => $this->memberEnrol])->assertForbidden();
        $this->postJson("/api/admin/teams/{$this->confTeam}/waive", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/admin/teams/{$this->confTeam}/dissolve")->assertForbidden();
        $this->postJson("/api/admin/team-members/{$this->unplacedEnrol}/school-leave")->assertForbidden();
    }

    // ── each terminal action renders its representative refusal ──────────────────────────────────────
    public function test_each_action_renders_its_representative_refusal(): void
    {
        $this->act($this->ops);
        // dissolve on a FORMING team → 409 (only a confirmed team is dissolved)
        $this->postJson("/api/admin/teams/{$this->formTeam}/dissolve")->assertStatus(409);
        // waive with NO reason → 422 (reason required, OD-40)
        $this->postJson("/api/admin/teams/{$this->confTeam}/waive", [])->assertStatus(422);
        // waive on a FORMING team → 409 (a waiver applies to a confirmed team)
        $this->postJson("/api/admin/teams/{$this->formTeam}/waive", ['reason' => 'x'])->assertStatus(409);
        // extend-grace on an ACTIVE (not suspended) member → 409 (nothing to extend)
        $this->postJson("/api/admin/teams/{$this->confTeam}/extend-grace", ['enrolment_id' => $this->memberEnrol])->assertStatus(409);
        // school-leave on a NON-member enrolment → 404
        $this->postJson("/api/admin/team-members/{$this->unplacedEnrol}/school-leave")->assertStatus(404);
        // assign into a FORMING team → 409 (assign resolves a below-min CONFIRMED team)
        $this->postJson("/api/admin/teams/{$this->formTeam}/assign", ['enrolment_id' => $this->unplacedEnrol])->assertStatus(409);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────
    private function act(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
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
}
