<?php

namespace Tests\Feature;

use App\Events\BookingPromoted;
use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => 'operations', 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        $this->programme = Programme::query()->create(['code' => 'BK-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
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

    /** A student with a live (active) enrolment in the programme. */
    private function participant(): User
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->sys(fn () => DB::table('enrolments')->insert([
            'id' => (string) Str::uuid7(), 'programme_id' => $this->programme->id, 'student_id' => $student->id,
            'acting_guardian_id' => $student->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $student;
    }

    private function book(string $sessionId, User $student): array
    {
        Sanctum::actingAs($student);
        $r = $this->postJson("/api/my/sessions/{$sessionId}/book")->assertOk()->json();
        $this->app['auth']->forgetGuards();

        return $r;
    }

    private function statusOf(string $sessionId): string
    {
        return $this->sys(fn () => DB::table('programme_sessions')->where('id', $sessionId)->value('status'));
    }

    public function test_booking_fills_to_capacity_then_waitlists(): void
    {
        $session = $this->publishedSession(2);
        $s1 = $this->participant();
        $s2 = $this->participant();
        $s3 = $this->participant();

        $this->assertSame('booked', $this->book($session, $s1)['status']);
        $this->assertSame('booked', $this->book($session, $s2)['status']);
        $this->assertSame('full', $this->statusOf($session)); // capacity reached
        $this->assertSame('waitlisted', $this->book($session, $s3)['status']);

        // idempotent: re-booking returns the same live booking
        $this->assertSame('waitlisted', $this->book($session, $s3)['status']);
    }

    public function test_cancel_auto_promotes_earliest_waitlisted(): void
    {
        Event::fake([BookingPromoted::class]);
        $session = $this->publishedSession(2);
        $s1 = $this->participant();
        $s2 = $this->participant();
        $s3 = $this->participant();
        $this->book($session, $s1);
        $this->book($session, $s2);
        $this->book($session, $s3); // waitlisted

        // s1 (booked) cancels → s3 (earliest waitlisted) is promoted
        Sanctum::actingAs($s1);
        $result = $this->postJson("/api/my/sessions/{$session}/cancel")->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $this->assertSame($s3->id, $result['promoted_student_id']);
        $this->sys(function () use ($session, $s3) {
            $this->assertSame('booked', DB::table('session_bookings')->where('session_id', $session)->where('student_id', $s3->id)->value('status'));
        });
        $this->assertSame('full', $this->statusOf($session)); // still full (promotion kept it full)
        Event::assertDispatched(BookingPromoted::class);
    }

    public function test_cancel_with_no_waitlist_reopens_a_full_session(): void
    {
        $session = $this->publishedSession(2);
        $s1 = $this->participant();
        $s2 = $this->participant();
        $this->book($session, $s1);
        $this->book($session, $s2);
        $this->assertSame('full', $this->statusOf($session));

        Sanctum::actingAs($s1);
        $result = $this->postJson("/api/my/sessions/{$session}/cancel")->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $this->assertNull($result['promoted_student_id']);
        $this->assertSame('published', $this->statusOf($session)); // a slot re-opened
    }

    public function test_attendance_rejected_before_in_progress_recorded_with_identity_after(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $session = $this->publishedSession(5, $mentor->id);
        $s1 = $this->participant();
        $this->book($session, $s1);

        // attendance on a published (not in-progress) session → rejected
        Sanctum::actingAs($mentor);
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $s1->id, 'status' => 'attended'])->assertStatus(409);
        $this->app['auth']->forgetGuards();

        // move the session In Progress, then the mentor records attendance with their identity
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/sessions/{$session}/transition", ['to' => 'in_progress'])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($mentor);
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $s1->id, 'status' => 'attended'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->sys(function () use ($session, $s1, $mentor) {
            $booking = DB::table('session_bookings')->where('session_id', $session)->where('student_id', $s1->id)->first();
            $this->assertSame('attended', $booking->status);
            $this->assertSame($mentor->id, (int) $booking->recorded_by); // recorder identity
            $this->assertNotNull($booking->recorded_at);
        });
    }

    public function test_attendance_requires_mentor_or_operations(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $session = $this->publishedSession(5, $mentor->id);
        $s1 = $this->participant();
        $this->book($session, $s1);
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/sessions/{$session}/transition", ['to' => 'in_progress'])->assertOk();
        $this->app['auth']->forgetGuards();

        // a teacher who is NOT this session's mentor cannot record
        Sanctum::actingAs($otherTeacher);
        $this->postJson("/api/admin/sessions/{$session}/attendance", ['student_id' => $s1->id, 'status' => 'attended'])->assertStatus(403);
        $this->app['auth']->forgetGuards();
    }

    public function test_capacity_serializes_booking_claims_across_connections(): void
    {
        // BI-3 shape: the session row is the lock the booking claim takes FOR UPDATE.
        // Committed synthetic session (outside RefreshDatabase's tx) so two raw
        // connections can contend, then cleaned up.
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '54329'), env('DB_DATABASE', 'kap_test'));
        $a = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $b = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach ([$a, $b] as $c) {
            $c->exec("SELECT set_config('app.context','system',false)");
        }
        // self-contained committed fixture: synthetic user + programme + session (the
        // test's own rows are in the uncommitted RefreshDatabase tx, invisible to raw PDO)
        $a->exec("DELETE FROM programme_sessions WHERE title = 'RACE'");
        $a->exec("DELETE FROM programmes WHERE code = 'BKRACE'");
        $a->exec("DELETE FROM users WHERE email = 'bkrace@test.local'");
        $uid = (int) $a->query("INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES ('BKRACE','bkrace@test.local','x','academy_admin',now(),now()) RETURNING id")->fetchColumn();
        $pid = (int) $a->query("INSERT INTO programmes (code, name_en, name_tc, name_sc, status) VALUES ('BKRACE','R','R','R','published') RETURNING id")->fetchColumn();
        $sid = (string) Str::uuid7();
        $a->exec("INSERT INTO programme_sessions (id, programme_id, title, starts_at, ends_at, capacity, status, created_by, created_at, updated_at)
                  VALUES ('{$sid}', {$pid}, 'RACE', now()+interval '1 day', now()+interval '1 day 1 hour', 1, 'published', {$uid}, now(), now())");
        try {
            $a->exec('BEGIN');
            $a->query("SELECT capacity FROM programme_sessions WHERE id = '{$sid}' FOR UPDATE");
            $b->exec("SET lock_timeout = '400ms'");
            $b->exec('BEGIN');
            $blocked = false;
            try {
                $b->query("SELECT capacity FROM programme_sessions WHERE id = '{$sid}' FOR UPDATE");
            } catch (\PDOException $e) {
                $blocked = str_contains($e->getMessage(), 'lock timeout');
            }
            $b->exec('ROLLBACK');
            $a->exec('COMMIT');
        } finally {
            $a->exec("DELETE FROM programme_sessions WHERE id = '{$sid}'");
            $a->exec("DELETE FROM programmes WHERE id = {$pid}");
            $a->exec("DELETE FROM users WHERE id = {$uid}");
        }
        $this->assertTrue($blocked, 'B must block on the session row while A holds the booking-claim FOR UPDATE');
    }
}
