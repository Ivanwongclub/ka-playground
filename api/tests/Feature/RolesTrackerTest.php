<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Teams\TrackerService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class RolesTrackerTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private School $school;

    private User $schoolAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $c, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        $this->school = School::query()->create(['name_en' => 'St Test', 'name_tc' => '測', 'name_sc' => '测']);
        $this->schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $this->schoolAdmin->id, 'school_id' => $this->school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
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

    /** @return array{0: Programme, 1: string, 2: string} programme, openLobby, boundLobby */
    private function publishedProgramme(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => 'RT-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'],
                'eligibility' => ['capacity' => 20],
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'team_rules' => ['formation_deadline_on' => '2026-06-20', 'min_team_size' => 2],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$k}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/fee-items", ['name_en' => 'Fee', 'name_tc' => '費', 'name_sc' => '费', 'amount_minor' => 250000, 'currency' => 'HKD'])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $open = $this->postJson("/api/admin/programmes/{$programme->id}/team-categories", ['name_en' => 'Open', 'name_tc' => '開', 'name_sc' => '开', 'assignment_rule' => 'open', 'is_default' => true])->json('id');
        $bound = $this->postJson("/api/admin/programmes/{$programme->id}/team-categories", ['name_en' => 'St Test', 'name_tc' => '測', 'name_sc' => '测', 'assignment_rule' => 'auto_by_school', 'school_id' => $this->school->id])->json('id');
        $this->app['auth']->forgetGuards();

        return [$programme, $open, $bound];
    }

    private function ceoRole(Programme $programme): string
    {
        return $this->sys(function () use ($programme) {
            $id = (string) Str::uuid7();
            DB::table('role_library')->insert([
                'id' => $id, 'programme_id' => $programme->id, 'name_en' => 'CEO', 'name_tc' => '執行長', 'name_sc' => '执行长',
                'min_holders' => 1, 'max_holders' => 1, 'mandatory' => false, 'in_team_permissions' => json_encode([]),
                'rotation_cadence' => 'manual', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    /** @return User the guardian (so tests can act as a member's guardian) */
    private function pooledStudent(Programme $programme, ?School $school, ?User &$guardianOut = null): User
    {
        app(ScopeContext::class)->setSystem();
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        if ($school) {
            DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $this->postJson('/api/my/enrolments', ['programme_id' => $programme->id, 'student_id' => $student->id]);
        $req = DB::table('consent_requests')->where('student_id', $student->id)->where('signer_id', $guardian->id)->whereIn('status', ['sent', 'viewed'])->first();
        $this->getJson("/api/consent-requests/{$req->id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/sign", ['affirmed' => true, 'method' => 'typed', 'typed_name' => 'G'])->assertStatus(201);
        app(EnrolmentService::class)->evaluateConsentGate($programme->id, $student->id, $guardian);
        $this->app['auth']->forgetGuards();
        $guardianOut = $guardian;

        return $student;
    }

    /** A confirmed team in the given lobby. @return array{0:string,1:list<User>,2:list<User>} teamId, students, guardians */
    private function confirmedTeam(Programme $programme, string $lobby, int $size, ?School $school): array
    {
        $students = [];
        $guardians = [];
        $creator = $this->pooledStudent($programme, $school, $g);
        $students[] = $creator;
        $guardians[] = $g;
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $programme->id, 'category_id' => $lobby, 'name' => 'Team'.Str::random(4)])->json('id');
        $this->app['auth']->forgetGuards();
        for ($i = 1; $i < $size; $i++) {
            $m = $this->pooledStudent($programme, $school, $g2);
            $students[] = $m;
            $guardians[] = $g2;
            Sanctum::actingAs($m);
            $this->postJson("/api/teams/{$teamId}/join")->assertOk();
            $this->app['auth']->forgetGuards();
        }
        Sanctum::actingAs($creator);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $this->app['auth']->forgetGuards();
        // S07 STEP 1: the Plan gate now requires an active team budget (Spec:210) —
        // seed one so the existing tracker-gate tests still exercise Plan approval.
        $this->seedActiveBudget($teamId);

        return [$teamId, $students, $guardians];
    }

    private function seedActiveBudget(string $teamId): void
    {
        $this->sys(function () use ($teamId) {
            $bid = (string) Str::uuid7();
            DB::table('team_budgets')->insert(['id' => $bid, 'team_id' => $teamId, 'status' => 'active', 'currency' => 'HKD', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('budget_lines')->insert(['id' => (string) Str::uuid7(), 'budget_id' => $bid, 'team_id' => $teamId, 'category' => 'other', 'name' => 'seed', 'planned_amount_minor' => 0, 'currency' => 'HKD', 'created_at' => now(), 'updated_at' => now()]);
            app(\App\Services\Audit\AuditService::class)->record('team_budget', $bid, 'team_budget.approved', toState: 'active', payloadAfter: ['team_id' => $teamId]);
        });
    }

    private function enrolmentId(Programme $programme, User $student): string
    {
        return $this->sys(fn () => DB::table('enrolments')->where('programme_id', $programme->id)->where('student_id', $student->id)->value('id'));
    }

    private function linkTeacher(string $teamId, User $teacher): void
    {
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/teams/{$teamId}/teacher-link", ['teacher_id' => $teacher->id])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    public function test_gate_approval_five_branch(): void
    {
        [$programme, $open, $bound] = $this->publishedProgramme();
        [$teamId, $students, $guardians] = $this->confirmedTeam($programme, $bound, 2, $this->school);

        // team-linked teacher (this team) vs a teacher linked to a DIFFERENT team
        $teamTeacher = User::factory()->create(['role' => 'teacher']);
        $this->linkTeacher($teamId, $teamTeacher);
        $otherTeam = $this->confirmedTeam($programme, $open, 2, null)[0];
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $this->linkTeacher($otherTeam, $otherTeacher);

        // (1) team-linked teacher approves THEIR team's gate
        Sanctum::actingAs($teamTeacher);
        $r = $this->postJson("/api/teams/{$teamId}/gates/Plan/approve")->assertOk()->json();
        $this->assertSame('teacher', $r['approver_kind']);
        $this->app['auth']->forgetGuards();

        // (2) the lobby's school admin approves (a different stage)
        Sanctum::actingAs($this->schoolAdmin);
        $this->postJson("/api/teams/{$teamId}/gates/Design/approve")->assertOk()->assertJsonPath('approver_kind', 'school_admin');
        $this->app['auth']->forgetGuards();

        // (3) a teacher linked to a DIFFERENT team is refused (OD-61: team-linked, not role-gated)
        Sanctum::actingAs($otherTeacher);
        $this->postJson("/api/teams/{$teamId}/gates/Learn/approve")->assertStatus(403);
        $this->app['auth']->forgetGuards();

        // (4) a guardian of a member is refused
        Sanctum::actingAs($guardians[0]);
        $this->postJson("/api/teams/{$teamId}/gates/Learn/approve")->assertStatus(403);
        $this->app['auth']->forgetGuards();

        // (5) a member student is refused
        Sanctum::actingAs($students[0]);
        $this->postJson("/api/teams/{$teamId}/gates/Learn/approve")->assertStatus(403);
        $this->app['auth']->forgetGuards();

        // only the two authorised passes were recorded
        $this->sys(function () use ($teamId) {
            $this->assertSame(2, DB::table('stage_gates')->where('team_id', $teamId)->count());
            $this->assertDatabaseHas('stage_gates', ['team_id' => $teamId, 'stage' => 'Plan', 'approver_kind' => 'teacher']);
            $this->assertDatabaseHas('stage_gates', ['team_id' => $teamId, 'stage' => 'Design', 'approver_kind' => 'school_admin']);
        });
    }

    public function test_ceo_role_rotates_not_stacks(): void
    {
        [$programme, $open, ] = $this->publishedProgramme();
        [$teamId, $students, ] = $this->confirmedTeam($programme, $open, 2, null);
        $roleId = $this->ceoRole($programme);
        $eA = $this->enrolmentId($programme, $students[0]);
        $eB = $this->enrolmentId($programme, $students[1]);

        // assign CEO to A
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/roles", ['enrolment_id' => $eA, 'role_id' => $roleId])->assertOk();
        $this->app['auth']->forgetGuards();
        // rotate CEO to B — must REMOVE it from A (no two live CEOs)
        Sanctum::actingAs($this->ops);
        $rot = $this->postJson("/api/teams/{$teamId}/roles", ['enrolment_id' => $eB, 'role_id' => $roleId])->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->assertSame((int) $students[0]->id, $rot['rotated_from']);

        $this->sys(function () use ($teamId, $roleId, $students) {
            // exactly ONE active tenure for this role — B — and A's is completed (the history)
            $active = DB::table('tenures')->where('team_id', $teamId)->where('role_id', $roleId)->where('state', 'active')->get();
            $this->assertCount(1, $active);
            $this->assertSame((int) $students[1]->id, (int) $active->first()->student_id);
            $completed = DB::table('tenures')->where('team_id', $teamId)->where('role_id', $roleId)->where('state', 'completed')->first();
            $this->assertNotNull($completed);
            $this->assertSame((int) $students[0]->id, (int) $completed->student_id);
            $this->assertNotNull($completed->ended_at);
            $this->assertSame('rotated', $completed->ended_reason);
            $this->assertDatabaseHas('audit_events', ['entity_type' => 'tenure', 'entity_id' => $completed->id, 'action' => 'tenure.completed']);
        });

        // re-assigning to the CURRENT holder is a no-op refusal
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/roles", ['enrolment_id' => $eB, 'role_id' => $roleId])->assertStatus(409);
        $this->app['auth']->forgetGuards();
    }

    public function test_the_five_stages_are_fixed_not_configurable(): void
    {
        [$programme, $open, ] = $this->publishedProgramme();
        [$teamId, , ] = $this->confirmedTeam($programme, $open, 2, null);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->linkTeacher($teamId, $teacher);

        // the constant IS the five stages, in order
        $this->assertSame(['Plan', 'Design', 'Learn', 'Pitch', 'Launch'], TrackerService::STAGES);

        Sanctum::actingAs($teacher);
        // an off-list stage is rejected — there is no per-programme stage config
        $this->postJson("/api/teams/{$teamId}/gates/Prototype/approve")->assertStatus(422);
        // the four non-Learn fixed stages approve manually (uniform OD-61 authority)
        foreach (['Plan', 'Design', 'Pitch', 'Launch'] as $stage) {
            $this->postJson("/api/teams/{$teamId}/gates/{$stage}/approve")->assertOk();
        }
        // Learn is the ONE computed gate (S06-4, Option B): with no attendance it is not yet
        // assessable → refused even for an authorised approver (its own precondition, not a stage-config)
        $this->postJson("/api/teams/{$teamId}/gates/Learn/approve")->assertStatus(422);
        // a repeat of an already-passed gate is refused
        $this->postJson("/api/teams/{$teamId}/gates/Plan/approve")->assertStatus(409);
        $this->app['auth']->forgetGuards();

        $this->sys(fn () => $this->assertSame(4, DB::table('stage_gates')->where('team_id', $teamId)->count()));
    }

    // ══════════════════════════════════════════════════════════════════════════════════════════════
    // S-TRACKER-1 — the READ WIDEN on GET /teams/{team}/tracker (passed_at + approver_kind).
    //
    // RIDER-1: the widen adds FIELDS to rows the caller could already read; it must add no READER. These
    // tests pin the whole wall per seeded role, not just the happy path — a family member and their
    // guardian see the pass fact; every role that could not read the team before still 404s.
    // ══════════════════════════════════════════════════════════════════════════════════════════════

    /** RIDER-1 — the pass fact reaches the family; the wall around it is unchanged, role by role. */
    public function test_tracker_pass_fact_reaches_family_and_the_wall_is_unchanged(): void
    {
        [$programme, $open, $bound] = $this->publishedProgramme();
        [$teamId, $students, $guardians] = $this->confirmedTeam($programme, $bound, 2, $this->school);

        $teamTeacher = User::factory()->create(['role' => 'teacher']);
        $this->linkTeacher($teamId, $teamTeacher);

        // two real passes, by two DIFFERENT kinds of authority — so approver_kind is proven to vary
        Sanctum::actingAs($teamTeacher);
        $this->postJson("/api/teams/{$teamId}/gates/Plan/approve")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->schoolAdmin);
        $this->postJson("/api/teams/{$teamId}/gates/Design/approve")->assertOk();
        $this->app['auth']->forgetGuards();

        // ── (1) a MEMBER student: the five fixed stages, two carrying the pass fact ──
        Sanctum::actingAs($students[0]);
        $memberBody = $this->getJson("/api/teams/{$teamId}/tracker")->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $this->assertSame(['Plan', 'Design', 'Learn', 'Pitch', 'Launch'], array_column($memberBody['stages'], 'stage'));
        $this->assertSame([true, true, false, false, false], array_column($memberBody['stages'], 'passed'));
        $this->assertSame('teacher', $memberBody['stages'][0]['approver_kind']);
        $this->assertSame('school_admin', $memberBody['stages'][1]['approver_kind']);
        $this->assertNotNull($memberBody['stages'][0]['passed_at']);
        $this->assertNotNull($memberBody['stages'][1]['passed_at']);
        // an UNPASSED stage carries null, never a placeholder date or a guessed kind
        foreach ([2, 3, 4] as $i) {
            $this->assertNull($memberBody['stages'][$i]['passed_at']);
            $this->assertNull($memberBody['stages'][$i]['approver_kind']);
        }

        // ── (2) the GUARDIAN of a member mirrors the child's read EXACTLY (byte-identical body) ──
        Sanctum::actingAs($guardians[0]);
        $guardianBody = $this->getJson("/api/teams/{$teamId}/tracker")->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->assertSame($memberBody, $guardianBody);

        // ── (3) staff who could already read the team still read it — now with the pass fact ──
        foreach ([[$this->schoolAdmin, 'lobby school admin'], [$this->ops, 'academy operations']] as [$staff, $who]) {
            Sanctum::actingAs($staff);
            $body = $this->getJson("/api/teams/{$teamId}/tracker")->assertOk($who)->json();
            $this->assertSame($memberBody, $body, "{$who} sees the same five stages");
            $this->app['auth']->forgetGuards();
        }

        // ── (4) every role OUTSIDE the wall still 404s — the widen opened no reader ──
        $outsider = $this->pooledStudent($programme, $this->school, $outsiderGuardian); // same programme, no team
        $otherSchool = School::query()->create(['name_en' => 'Other', 'name_tc' => '他', 'name_sc' => '他']);
        $otherSchoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $otherSchoolAdmin->id, 'school_id' => $otherSchool->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $financeOnly = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $financeOnly->id, 'capability' => 'finance', 'granted_by' => $this->ops->id, 'granted_at' => now()]);

        $denied = [
            'non-member student' => $outsider,
            'guardian of a non-member' => $outsiderGuardian,
            'school admin of another school' => $otherSchoolAdmin,
            'finance-only academy admin' => $financeOnly,
            'unlinked teacher' => User::factory()->create(['role' => 'teacher']),
            'Kings Network member' => User::factory()->create(['role' => 'member']),
        ];
        foreach ($denied as $who => $actor) {
            Sanctum::actingAs($actor);
            $this->getJson("/api/teams/{$teamId}/tracker")->assertStatus(404, "{$who} must not reach the tracker");
            $this->app['auth']->forgetGuards();
        }

        // ── (5) the team-linked TEACHER rides the S-MENTOR-1 mentor arm, exactly as before the widen. ──
        // (a) mentor_team_access OFF → 404, the team itself is invisible.
        Sanctum::actingAs($teamTeacher);
        $this->getJson("/api/teams/{$teamId}/tracker")->assertStatus(404, 'linked teacher, mentor_team_access OFF');
        $this->app['auth']->forgetGuards();

        $this->sys(fn () => DB::table('programmes')->where('id', $programme->id)->update(['mentor_team_access' => true]));

        // (b) FLAG ON, but the mentor holds no link to the LOBBY'S SCHOOL → the team is readable while every
        //     gate row is filtered out: teams_read's mentor arm tests `programmes.mentor_team_access` directly,
        //     whereas stage_gates_read's tests it THROUGH `team_categories`, which is itself school-scoped for
        //     a teacher. So the two arms disagree and the mentor gets an all-false tracker.
        //     PRE-EXISTING (S-MENTOR-1), NOT introduced by this widen — the widen only adds fields to rows the
        //     caller already reads, and here there are none. FLAGGED for its own ruling; not fixed in this card.
        Sanctum::actingAs($teamTeacher);
        $mentorNoSchool = $this->getJson("/api/teams/{$teamId}/tracker")->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->assertSame([false, false, false, false, false], array_column($mentorNoSchool['stages'], 'passed'));
        $this->assertNull($mentorNoSchool['stages'][0]['passed_at']);
        $this->assertNull($mentorNoSchool['stages'][0]['approver_kind']);

        // (c) the same mentor, linked to the lobby's school (the ordinary case): the gate rows resolve and the
        //     pass fact rides the UNCHANGED mentor arm — same five stages the family sees.
        $this->sys(fn () => DB::table('teacher_links')->insert([
            'id' => (string) Str::uuid7(), 'teacher_id' => $teamTeacher->id, 'school_id' => $this->school->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]));
        Sanctum::actingAs($teamTeacher);
        $this->assertSame($memberBody, $this->getJson("/api/teams/{$teamId}/tracker")->assertOk()->json());
        $this->app['auth']->forgetGuards();
    }

    /** The line drawn on purpose: approver IDENTITY and notes must NOT leave this read. */
    public function test_tracker_read_never_leaks_approver_identity_or_notes(): void
    {
        [$programme, $open, ] = $this->publishedProgramme();
        [$teamId, $students, $guardians] = $this->confirmedTeam($programme, $open, 2, null);
        $teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Mentor Wong Kai-lam']);
        $this->linkTeacher($teamId, $teacher);

        $notes = 'INTERNAL charter is thin, the team needs pushing';
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teams/{$teamId}/gates/Plan/approve", ['notes' => $notes])->assertOk();
        $this->app['auth']->forgetGuards();
        // the note and the identity ARE stored — this test is about what LEAVES the read, not what is kept
        $this->sys(function () use ($teamId, $teacher, $notes) {
            $row = DB::table('stage_gates')->where('team_id', $teamId)->where('stage', 'Plan')->first();
            $this->assertSame($notes, $row->notes);
            $this->assertSame((int) $teacher->id, (int) $row->approved_by);
        });

        foreach ([[$students[0], 'member'], [$guardians[0], 'guardian']] as [$actor, $who]) {
            Sanctum::actingAs($actor);
            $res = $this->getJson("/api/teams/{$teamId}/tracker")->assertOk();
            $this->app['auth']->forgetGuards();

            // the EXACT key set per stage — a new field cannot appear here without failing this test
            foreach ($res->json('stages') as $stage) {
                $this->assertSame(['stage', 'passed', 'passed_at', 'approver_kind'], array_keys($stage), "{$who}: stage key set");
            }
            $raw = $res->getContent();
            $this->assertStringNotContainsString($notes, $raw, "{$who}: staff notes must not leave the read");
            $this->assertStringNotContainsString('charter', $raw, "{$who}: no fragment of the notes");
            $this->assertStringNotContainsString('approved_by', $raw, "{$who}: no approver identity key");
            $this->assertStringNotContainsString('Mentor Wong Kai-lam', $raw, "{$who}: no approver name");
            $this->assertStringNotContainsString('"'.$teacher->id.'"', $raw, "{$who}: no approver id");
            // approver KIND is the class of authority, and it is what we do serve
            $this->assertSame('teacher', $res->json('stages.0.approver_kind'));
        }
    }

    /**
     * BEHAVIOUR-SHA — S-TRACKER-1 is a CONTROLLER-only widen: it edits no policy. This pins the live
     * USING expression of the two policies the read passes through (`teams_read`, `stage_gates_read`), so
     * any future edit to either arm fails here and must be ruled rather than slipped in behind a widen.
     */
    public function test_tracker_read_policies_are_untouched_by_the_widen(): void
    {
        $sha = fn (string $table, string $policy): string => hash('sha256', (string) $this->sys(
            fn () => DB::table('pg_policies')->where('tablename', $table)->where('policyname', $policy)->value('qual')
        ));

        $this->assertSame('f28e2e86d6c86c42f7a9b91e2c94e8c899ea0517b388b0caf44374186b9468a3', $sha('teams', 'teams_read'), 'teams_read USING changed');
        $this->assertSame('81e135f34f0715b2fb119f8c665447d2c5a20eac4f4d830673246fbc2a0454be', $sha('stage_gates', 'stage_gates_read'), 'stage_gates_read USING changed');
    }

    public function test_gate_and_tenure_writes_carry_the_acting_human_not_system(): void
    {
        // S05 has no job-driven tracker transition — every gate/tenure write is a
        // human act (staff/teacher/admin), attributed to that actor, never 'system'.
        [$programme, $open, ] = $this->publishedProgramme();
        [$teamId, $students, ] = $this->confirmedTeam($programme, $open, 2, null);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->linkTeacher($teamId, $teacher);
        $roleId = $this->ceoRole($programme);

        Sanctum::actingAs($teacher);
        $gate = $this->postJson("/api/teams/{$teamId}/gates/Plan/approve")->json();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/roles", ['enrolment_id' => $this->enrolmentId($programme, $students[0]), 'role_id' => $roleId])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->sys(function () use ($gate, $teacher) {
            $ev = DB::table('audit_events')->where('entity_type', 'stage_gate')->where('entity_id', $gate['gate_id'])->where('action', 'stage_gate.passed')->first();
            $this->assertSame((int) $teacher->id, (int) $ev->actor_id);
            $this->assertSame('teacher', $ev->actor_role);
            $this->assertNotSame('system', $ev->actor_role);
            $tenureEv = DB::table('audit_events')->where('entity_type', 'tenure')->where('action', 'tenure.assigned')->first();
            $this->assertSame((int) $this->ops->id, (int) $tenureEv->actor_id);
        });
    }
}
