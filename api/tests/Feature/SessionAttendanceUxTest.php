<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-UX3-4 STEP 1 — the attendance READ surface (child-safety-grade: minor presence data).
 * Proves the five-branch, the tight roster allowlist (no consent/guardian/other-session leak),
 * that only the roster added a new elevation, and that the existing mark write stays clean.
 */
class SessionAttendanceUxTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => 'operations', 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        $this->programme = Programme::query()->create(['code' => 'AT-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
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

    private function publishedSession(int $capacity, ?int $mentorId = null): string
    {
        Sanctum::actingAs($this->ops);
        $id = $this->postJson("/api/admin/programmes/{$this->programme->id}/sessions", [
            'title' => 'S', 'starts_at' => '2026-12-01 10:00:00', 'ends_at' => '2026-12-01 11:00:00',
            'capacity' => $capacity, 'mentor_id' => $mentorId,
        ])->json('id');
        $this->postJson("/api/admin/sessions/{$id}/transition", ['to' => 'published'])->assertOk();
        $this->app['auth']->forgetGuards();

        return $id;
    }

    private function transition(string $sessionId, string $to): void
    {
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/sessions/{$sessionId}/transition", ['to' => $to])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    /** A student with a live (active) enrolment in the programme; distinctive name for the leak sweep. */
    private function participant(string $name): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);
        $this->sys(fn () => DB::table('enrolments')->insert([
            'id' => (string) Str::uuid7(), 'programme_id' => $this->programme->id, 'student_id' => $student->id,
            'acting_guardian_id' => $student->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $student;
    }

    private function book(string $sessionId, User $student): void
    {
        Sanctum::actingAs($student);
        $this->postJson("/api/my/sessions/{$sessionId}/book")->assertOk();
        $this->app['auth']->forgetGuards();
    }

    private function link(User $guardian, User $student): void
    {
        $this->sys(fn () => DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'guardian_id' => $guardian->id, 'student_id' => $student->id,
            'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    // ── Test 1 — privacy tooth (child data): the roster allowlist ───────────────────────────────────────
    public function test_roster_returns_only_the_attendance_fact_and_name_no_pii_leak(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $session = $this->publishedSession(5, $mentor->id);
        $s1 = $this->participant('Ada Attendee');
        $this->book($session, $s1);
        $this->transition($session, 'in_progress');
        Sanctum::actingAs($mentor);
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $s1->id, 'status' => 'attended'])->assertOk();

        $body = $this->getJson("/api/admin/sessions/{$session}/roster")->assertOk()->json();
        $this->app['auth']->forgetGuards();

        // exact key-allowlist on each roster row
        $this->assertCount(1, $body['roster']);
        $this->assertEqualsCanonicalizing(['student_id', 'student_name', 'status'], array_keys($body['roster'][0]));
        $this->assertSame('Ada Attendee', $body['roster'][0]['student_name']);
        $this->assertSame('attended', $body['roster'][0]['status']);

        // red-green forbidden-field sweep across the WHOLE response — no email/guardian/consent/enrolment/other-session
        $blob = json_encode($body);
        $this->assertStringNotContainsStringIgnoringCase($s1->email, $blob, 'student email must never appear');
        foreach (['guardian', 'consent', 'enrolment', 'acting_guardian', 'recorded_by', 'recorded_at', 'programme_id'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $blob, "roster leaked: {$forbidden}");
        }
    }

    // ── Test 2 — five-branch ────────────────────────────────────────────────────────────────────────────
    public function test_student_sees_only_their_own_sessions_and_attendance(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $session = $this->publishedSession(5, $mentor->id);
        $me = $this->participant('Me Student');
        $other = $this->participant('Other Student');
        $this->book($session, $me);
        $this->book($session, $other);
        $this->transition($session, 'in_progress');
        Sanctum::actingAs($mentor);
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $me->id, 'status' => 'attended'])->assertOk();
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $other->id, 'status' => 'no_show'])->assertOk();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($me);
        $body = $this->getJson('/api/my/sessions')->assertOk()->json();
        $this->app['auth']->forgetGuards();

        // one session, my own booking = attended; the other student's presence never appears
        $mine = collect($body['sessions'])->firstWhere('id', $session);
        $this->assertNotNull($mine);
        $this->assertSame('attended', $mine['booking_status']);
        $this->assertStringNotContainsStringIgnoringCase('Other Student', json_encode($body));
        $this->assertStringNotContainsStringIgnoringCase('no_show', json_encode($body)); // that is the other student's mark
    }

    public function test_student_is_denied_another_students_attendance_via_the_roster(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $session = $this->publishedSession(5, $mentor->id);
        $me = $this->participant('Nosy Student');
        $this->book($session, $me);

        // an enrolled student CAN see the session row (ps_read roster) but must NOT see the roster of minors
        Sanctum::actingAs($me);
        $this->getJson("/api/admin/sessions/{$session}/roster")->assertStatus(403);
        $this->app['auth']->forgetGuards();
    }

    public function test_guardian_sees_their_child_and_is_denied_another_child(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $session = $this->publishedSession(5, $mentor->id);
        $mine = $this->participant('My Child');
        $notMine = $this->participant('Not My Child');
        $this->book($session, $mine);
        $this->book($session, $notMine);
        $this->transition($session, 'in_progress');
        Sanctum::actingAs($mentor);
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $mine->id, 'status' => 'attended'])->assertOk();
        $this->app['auth']->forgetGuards();

        $guardian = User::factory()->create(['role' => 'guardian']);
        $this->link($guardian, $mine);

        // THEIR child → the child's attendance
        Sanctum::actingAs($guardian);
        $body = $this->getJson("/api/my/students/{$mine->id}/sessions")->assertOk()->json();
        $childSession = collect($body['sessions'])->firstWhere('id', $session);
        $this->assertNotNull($childSession);
        $this->assertSame('attended', $childSession['booking_status']);

        // ANOTHER child → 403 (the critical child-privacy assertion), no attendance leaked
        $this->getJson("/api/my/students/{$notMine->id}/sessions")->assertStatus(403);
        $this->app['auth']->forgetGuards();
    }

    public function test_mentor_sees_their_assigned_sessions_roster_only(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $session = $this->publishedSession(5, $mentor->id);
        $s1 = $this->participant('Rostered One');
        $this->book($session, $s1);

        // the assigned mentor → the roster
        Sanctum::actingAs($mentor);
        $this->getJson("/api/admin/sessions/{$session}/roster")->assertOk()
            ->assertJsonPath('roster.0.student_name', 'Rostered One');
        $this->app['auth']->forgetGuards();

        // a teacher UNRELATED to this session cannot even see it exists → 404 (no existence leak).
        // The roster fetches the session under the CALLER'S RLS first; ps_read does not admit an
        // unrelated teacher, so it 404s before the authority check. (Contrast: an enrolled student
        // CAN see the session row but is denied the roster with 403 — test_student_is_denied_*.)
        Sanctum::actingAs($otherTeacher);
        $this->getJson("/api/admin/sessions/{$session}/roster")->assertStatus(404);
        $this->app['auth']->forgetGuards();
    }

    public function test_ops_sees_the_roster_and_unauth_is_rejected(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $session = $this->publishedSession(5, $mentor->id);
        $s1 = $this->participant('Ops Visible');
        $this->book($session, $s1);

        Sanctum::actingAs($this->ops);
        $this->getJson("/api/admin/sessions/{$session}/roster")->assertOk()
            ->assertJsonPath('roster.0.student_name', 'Ops Visible');
        $this->app['auth']->forgetGuards();

        // unauthenticated → 401
        $this->getJson("/api/admin/sessions/{$session}/roster")->assertStatus(401);
        $this->getJson('/api/my/sessions')->assertStatus(401);
    }

    // ── Test 3 — elevation discipline: exactly one new site, and only for the roster ────────────────────
    public function test_only_the_roster_read_added_an_elevation(): void
    {
        $allow = array_keys(config('scope-elevations'));
        $this->assertContains('App\Http\Controllers\SessionReadController::roster', $allow);
        // the self/child reads are pure RLS — no controller-scoped elevation for them
        $this->assertNotContains('App\Http\Controllers\SessionReadController::mySessions', $allow);
        $this->assertNotContains('App\Http\Controllers\SessionReadController::childSessions', $allow);
    }

    // ── Test 4 — the mark write stays clean (authority + session-state; no Learn/enrolment precondition) ──
    public function test_mark_authority_and_session_state(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $session = $this->publishedSession(5, $mentor->id);
        $s1 = $this->participant('Marked Student');
        $this->book($session, $s1);

        // wrong session-state: still published (not in_progress) → 409
        Sanctum::actingAs($mentor);
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $s1->id, 'status' => 'attended'])->assertStatus(409);
        $this->app['auth']->forgetGuards();

        $this->transition($session, 'in_progress');

        // not this session's mentor → 403
        Sanctum::actingAs($otherTeacher);
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $s1->id, 'status' => 'attended'])->assertStatus(403);
        $this->app['auth']->forgetGuards();

        // the session's mentor, in_progress → 200 (no Learn/enrolment precondition blocks it)
        Sanctum::actingAs($mentor);
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $s1->id, 'status' => 'attended'])->assertOk();
        $this->app['auth']->forgetGuards();
    }
}
