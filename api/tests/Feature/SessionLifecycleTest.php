<?php

namespace Tests\Feature;

use App\Events\SessionRescheduled;
use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => 'operations', 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        $this->programme = Programme::query()->create(['code' => 'SS-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
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

    private function createSession(array $attrs = []): string
    {
        Sanctum::actingAs($this->ops);
        $id = $this->postJson("/api/admin/programmes/{$this->programme->id}/sessions", array_merge([
            'title' => 'Workshop', 'starts_at' => '2026-12-01 10:00:00', 'ends_at' => '2026-12-01 11:00:00', 'capacity' => 20,
        ], $attrs))->assertStatus(201)->json('id');
        $this->app['auth']->forgetGuards();

        return $id;
    }

    private function transition(string $sessionId, string $to): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->ops);
        $r = $this->postJson("/api/admin/sessions/{$sessionId}/transition", ['to' => $to]);
        $this->app['auth']->forgetGuards();

        return $r;
    }

    private function book(string $sessionId, User $student, ?string $enrolmentId = null): void
    {
        $this->sys(fn () => DB::table('session_bookings')->insert([
            'id' => (string) Str::uuid7(), 'session_id' => $sessionId, 'enrolment_id' => $enrolmentId ?? (string) Str::uuid7(),
            'student_id' => $student->id, 'status' => 'booked', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_state_machine_advances_and_blocks_illegal_transitions(): void
    {
        $id = $this->createSession();
        $this->assertSame('draft', DB::table('programme_sessions')->where('id', $id)->value('status'));

        // an illegal jump is refused
        $this->transition($id, 'in_progress')->assertStatus(409); // draft → in_progress not allowed

        // the legal path
        $this->transition($id, 'published')->assertOk();
        $this->transition($id, 'in_progress')->assertOk();
        $this->transition($id, 'completed')->assertOk();
        $this->assertSame('completed', DB::table('programme_sessions')->where('id', $id)->value('status'));

        // completed is terminal
        $this->transition($id, 'published')->assertStatus(409);
    }

    public function test_reschedule_keeps_bookings_writes_version_and_reopens_on_capacity_growth(): void
    {
        Event::fake([SessionRescheduled::class]);
        $id = $this->createSession(['capacity' => 2]);
        $this->transition($id, 'published')->assertOk();
        $this->transition($id, 'full')->assertOk(); // capacity reached
        $s1 = User::factory()->create(['role' => 'student']);
        $s2 = User::factory()->create(['role' => 'student']);
        $this->book($id, $s1);
        $this->book($id, $s2);

        Sanctum::actingAs($this->ops);
        $result = $this->postJson("/api/admin/sessions/{$id}/reschedule", [
            'starts_at' => '2026-12-05 14:00:00', 'ends_at' => '2026-12-05 15:00:00', 'capacity' => 3,
        ])->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $this->assertTrue($result['reopened']);
        $this->sys(function () use ($id) {
            $session = DB::table('programme_sessions')->where('id', $id)->first();
            $this->assertSame('published', $session->status);        // full + capacity grew → re-opened
            $this->assertSame(3, (int) $session->capacity);
            $this->assertStringStartsWith('2026-12-05', (string) $session->starts_at); // moved
            $this->assertSame(2, DB::table('session_bookings')->where('session_id', $id)->where('status', 'booked')->count()); // bookings KEPT
            $v = DB::table('session_versions')->where('session_id', $id)->first();
            $this->assertSame(1, (int) $v->version);
            $this->assertSame(2, json_decode((string) $v->payload, true)['capacity']); // pre-change snapshot
        });
        Event::assertDispatched(SessionRescheduled::class);
    }

    public function test_reschedule_clash_check_flags_double_booked_students(): void
    {
        $enrolment = (string) Str::uuid7();
        $student = User::factory()->create(['role' => 'student']);
        // session A (to be moved) and session B (the other commitment)
        $a = $this->createSession(['starts_at' => '2026-12-01 10:00:00', 'ends_at' => '2026-12-01 11:00:00']);
        $b = $this->createSession(['starts_at' => '2026-12-01 14:00:00', 'ends_at' => '2026-12-01 15:00:00']);
        foreach ([$a, $b] as $s) {
            $this->transition($s, 'published')->assertOk();
        }
        $this->book($a, $student, $enrolment);
        $this->book($b, $student, $enrolment);

        // pre-confirm preview: moving A onto B's time clashes for this student
        Sanctum::actingAs($this->ops);
        $preview = $this->postJson("/api/admin/sessions/{$a}/clash-preview", ['starts_at' => '2026-12-01 14:30:00', 'ends_at' => '2026-12-01 15:30:00'])->assertOk()->json();
        $this->assertSame(1, $preview['clash_count']);
        $this->assertSame([$student->id], $preview['clashing_students']);

        // the reschedule reports the clash for re-notification
        $result = $this->postJson("/api/admin/sessions/{$a}/reschedule", ['starts_at' => '2026-12-01 14:30:00', 'ends_at' => '2026-12-01 15:30:00'])->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->assertSame(1, $result['clash_count']);
        $this->assertSame([$student->id], $result['clashing_students']);
    }

    public function test_no_clash_when_new_time_does_not_overlap(): void
    {
        $enrolment = (string) Str::uuid7();
        $student = User::factory()->create(['role' => 'student']);
        $a = $this->createSession(['starts_at' => '2026-12-01 10:00:00', 'ends_at' => '2026-12-01 11:00:00']);
        $b = $this->createSession(['starts_at' => '2026-12-01 14:00:00', 'ends_at' => '2026-12-01 15:00:00']);
        foreach ([$a, $b] as $s) {
            $this->transition($s, 'published')->assertOk();
        }
        $this->book($a, $student, $enrolment);
        $this->book($b, $student, $enrolment);

        Sanctum::actingAs($this->ops);
        $preview = $this->postJson("/api/admin/sessions/{$a}/clash-preview", ['starts_at' => '2026-12-01 16:00:00', 'ends_at' => '2026-12-01 17:00:00'])->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->assertSame(0, $preview['clash_count']); // 16:00–17:00 does not overlap B (14:00–15:00)
    }

    public function test_mentor_departed_blocked_while_future_sessions_exist(): void
    {
        $mentor = User::factory()->create(['role' => 'teacher']);
        $id = $this->createSession(['mentor_id' => $mentor->id, 'starts_at' => '2026-12-01 10:00:00', 'ends_at' => '2026-12-01 11:00:00']);
        $this->transition($id, 'published')->assertOk();

        // Departed is blocked — a future, non-terminal session still points at this mentor (2.6)
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/mentors/{$mentor->id}/status", ['status' => 'departed'])->assertStatus(409);
        $this->app['auth']->forgetGuards();

        // reassign by cancelling the session → the block clears
        $this->transition($id, 'cancelled')->assertOk();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/mentors/{$mentor->id}/status", ['status' => 'departed'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertSame('departed', DB::table('mentors')->where('user_id', $mentor->id)->value('status'));
    }

    public function test_session_management_requires_operations(): void
    {
        $configOnly = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $configOnly->id, 'capability' => 'configuration', 'granted_by' => $configOnly->id, 'granted_at' => now()]);
        Sanctum::actingAs($configOnly);
        $this->postJson("/api/admin/programmes/{$this->programme->id}/sessions", [
            'title' => 'X', 'starts_at' => '2026-12-01 10:00:00', 'ends_at' => '2026-12-01 11:00:00', 'capacity' => 5,
        ])->assertStatus(403);
        $this->app['auth']->forgetGuards();
    }
}
