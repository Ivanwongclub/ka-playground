<?php

namespace Tests\Feature;

use App\Events\BookingPromoted;
use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Sessions\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** S06-6 (2.21 extension) — a Withdrawn enrolment's future bookings are cancelled and waitlist slots released. */
class WithdrawalBookingCascadeTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => 'operations', 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        $this->programme = Programme::query()->create(['code' => 'WC-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
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

    private function publishedSession(int $capacity): string
    {
        Sanctum::actingAs($this->ops);
        $id = $this->postJson("/api/admin/programmes/{$this->programme->id}/sessions", [
            'title' => 'S', 'starts_at' => '2026-12-01 10:00:00', 'ends_at' => '2026-12-01 11:00:00', 'capacity' => $capacity,
        ])->json('id');
        $this->postJson("/api/admin/sessions/{$id}/transition", ['to' => 'published'])->assertOk();
        $this->app['auth']->forgetGuards();

        return $id;
    }

    /** @return array{0: User, 1: string} student, enrolmentId */
    private function participant(): array
    {
        $student = User::factory()->create(['role' => 'student']);
        $enrolmentId = (string) Str::uuid7();
        $this->sys(fn () => DB::table('enrolments')->insert([
            'id' => $enrolmentId, 'programme_id' => $this->programme->id, 'student_id' => $student->id,
            'acting_guardian_id' => $student->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return [$student, $enrolmentId];
    }

    private function book(string $sessionId, User $student): string
    {
        Sanctum::actingAs($student);
        $status = $this->postJson("/api/my/sessions/{$sessionId}/book")->assertOk()->json('status');
        $this->app['auth']->forgetGuards();

        return $status;
    }

    public function test_withdrawal_cancels_future_bookings_and_promotes_the_waitlist(): void
    {
        Event::fake([BookingPromoted::class]);
        $session = $this->publishedSession(1);
        [$s1, $e1] = $this->participant();
        [$s2, ] = $this->participant();
        $this->assertSame('booked', $this->book($session, $s1));
        $this->assertSame('waitlisted', $this->book($session, $s2)); // capacity 1 → s2 waits

        // s1 is withdrawn → the cascade cancels their future booking and promotes s2
        app(BookingService::class)->cascadeWithdrawal($e1, $this->ops);

        $this->sys(function () use ($session, $s1, $s2) {
            $this->assertSame('cancelled', DB::table('session_bookings')->where('session_id', $session)->where('student_id', $s1->id)->value('status'));
            $this->assertSame('booked', DB::table('session_bookings')->where('session_id', $session)->where('student_id', $s2->id)->value('status'));
        });
        Event::assertDispatched(BookingPromoted::class);
    }

    public function test_withdrawal_with_no_waitlist_reopens_a_full_session(): void
    {
        $session = $this->publishedSession(1);
        [$s1, $e1] = $this->participant();
        $this->book($session, $s1);
        $this->assertSame('full', $this->sys(fn () => DB::table('programme_sessions')->where('id', $session)->value('status')));

        $count = app(BookingService::class)->cascadeWithdrawal($e1, $this->ops);

        $this->assertSame(1, $count);
        $this->assertSame('published', $this->sys(fn () => DB::table('programme_sessions')->where('id', $session)->value('status')));
    }

    public function test_cascade_leaves_bookings_on_past_sessions_alone(): void
    {
        $session = $this->publishedSession(5);
        [$s1, $e1] = $this->participant();
        $this->book($session, $s1);
        // the session has already run (starts_at moved into the past)
        $this->sys(fn () => DB::table('programme_sessions')->where('id', $session)->update(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()]));

        $count = app(BookingService::class)->cascadeWithdrawal($e1, $this->ops);

        $this->assertSame(0, $count); // only FUTURE bookings cascade; the past booking stands
        $this->assertSame('booked', $this->sys(fn () => DB::table('session_bookings')->where('session_id', $session)->where('student_id', $s1->id)->value('status')));
    }
}
