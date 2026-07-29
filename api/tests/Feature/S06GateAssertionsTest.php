<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Reconciliation\Assertions\AttendanceIntegrityAssertion;
use App\Services\Reconciliation\Assertions\BookingCascadeLiveAssertion;
use App\Services\Reconciliation\Assertions\LearnGateIntegrityAssertion;
use App\Services\Reconciliation\Assertions\NoStalePublishedSessionAssertion;
use App\Services\Sessions\BookingService;
use App\Services\Sessions\SessionAdvancementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** S06-7 gate: each new S06 assertion has real red-then-green teeth. */
class S06GateAssertionsTest extends TestCase
{
    use RefreshDatabase;

    private Programme $programme;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creator = User::factory()->create(['role' => 'academy_admin']);
        $this->programme = Programme::query()->create(['code' => 'S6-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
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

    private function mkSession(string $status, string $startsAt, string $endsAt, int $capacity = 10): string
    {
        $id = (string) Str::uuid7();
        $this->sys(fn () => DB::table('programme_sessions')->insert([
            'id' => $id, 'programme_id' => $this->programme->id, 'title' => 'S', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
            'capacity' => $capacity, 'status' => $status, 'created_by' => $this->creator->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function enrolment(int $studentId, string $status): string
    {
        $id = (string) Str::uuid7();
        $this->sys(fn () => DB::table('enrolments')->insert([
            'id' => $id, 'programme_id' => $this->programme->id, 'student_id' => $studentId,
            'acting_guardian_id' => $studentId, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function booking(string $sessionId, int $studentId, string $enrolmentId, string $status): void
    {
        $this->sys(fn () => DB::table('session_bookings')->insert([
            'id' => (string) Str::uuid7(), 'session_id' => $sessionId, 'enrolment_id' => $enrolmentId,
            'student_id' => $studentId, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_no_stale_published_reds_until_advancement_runs(): void
    {
        // a published session whose time has fully passed → stale until advanced
        $this->mkSession('published', now()->subDays(2)->toDateTimeString(), now()->subDay()->toDateTimeString());
        $red = $this->sys(fn () => (new NoStalePublishedSessionAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('past their end', $red->details);

        // the advancement job moves it published → in_progress → completed → GREEN
        app(SessionAdvancementService::class)->run();
        $this->assertTrue($this->sys(fn () => (new NoStalePublishedSessionAssertion)->check()->passed));
    }

    public function test_session_advancement_moves_through_lifecycle(): void
    {
        $future = $this->mkSession('published', now()->addDay()->toDateTimeString(), now()->addDay()->addHour()->toDateTimeString());
        $running = $this->mkSession('published', now()->subMinutes(30)->toDateTimeString(), now()->addMinutes(30)->toDateTimeString());
        $ended = $this->mkSession('published', now()->subDays(1)->toDateTimeString(), now()->subDays(1)->addHour()->toDateTimeString());

        app(SessionAdvancementService::class)->run();

        $this->sys(function () use ($future, $running, $ended) {
            $this->assertSame('published', DB::table('programme_sessions')->where('id', $future)->value('status')); // not yet started
            $this->assertSame('in_progress', DB::table('programme_sessions')->where('id', $running)->value('status'));
            $this->assertSame('completed', DB::table('programme_sessions')->where('id', $ended)->value('status'));
        });
    }

    public function test_attendance_integrity_reds_on_a_mark_on_a_never_run_session(): void
    {
        $this->assertTrue($this->sys(fn () => (new AttendanceIntegrityAssertion)->check()->passed)); // vacuous
        $s = $this->mkSession('published', now()->addDay()->toDateTimeString(), now()->addDay()->addHour()->toDateTimeString());
        $student = User::factory()->create(['role' => 'student']);

        // plant an 'attended' booking on a session that never reached in_progress → RED
        DB::beginTransaction();
        $this->booking($s, $student->id, (string) Str::uuid7(), 'attended');
        $red = $this->sys(fn () => (new AttendanceIntegrityAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('never ran', $red->details);
        DB::rollBack();

        $this->assertTrue($this->sys(fn () => (new AttendanceIntegrityAssertion)->check()->passed));
    }

    public function test_learn_gate_integrity_reds_on_below_threshold_snapshot(): void
    {
        $this->assertTrue($this->sys(fn () => (new LearnGateIntegrityAssertion)->check()->passed)); // vacuous

        // plant a Learn gate pass whose recorded snapshot did NOT meet the threshold → RED
        DB::beginTransaction();
        $this->sys(fn () => DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'stage_gate', 'entity_id' => (string) Str::uuid7(),
            'action' => 'stage_gate.passed', 'actor_role' => 'teacher', 'programme_id' => $this->programme->id, 'request_id' => (string) Str::uuid7(),
            'payload_after' => json_encode(['stage' => 'Learn', 'learn_eligibility' => ['qualifying' => 0, 'active_members' => 2, 'team_gate_pass_pct' => 60]]),
        ]));
        $red = $this->sys(fn () => (new LearnGateIntegrityAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('below threshold', $red->details);
        DB::rollBack();

        // a snapshot that MET the threshold is green
        $this->sys(fn () => DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'stage_gate', 'entity_id' => (string) Str::uuid7(),
            'action' => 'stage_gate.passed', 'actor_role' => 'teacher', 'programme_id' => $this->programme->id, 'request_id' => (string) Str::uuid7(),
            'payload_after' => json_encode(['stage' => 'Learn', 'learn_eligibility' => ['qualifying' => 2, 'active_members' => 2, 'team_gate_pass_pct' => 60]]),
        ]));
        $this->assertTrue($this->sys(fn () => (new LearnGateIntegrityAssertion)->check()->passed));
    }

    public function test_cascade_live_reds_when_a_withdrawn_enrolment_holds_a_future_booking(): void
    {
        $s = $this->mkSession('published', now()->addDay()->toDateTimeString(), now()->addDay()->addHour()->toDateTimeString());
        $student = User::factory()->create(['role' => 'student']);
        $e = $this->enrolment($student->id, 'withdrawn'); // already withdrawn
        $this->booking($s, $student->id, $e, 'booked'); // …still holding a future booking → RED

        $red = $this->sys(fn () => (new BookingCascadeLiveAssertion)->check());
        $this->assertFalse($red->passed);
        $this->assertStringContainsString('Withdrawn', $red->details);

        // running the cascade cancels it → GREEN
        app(BookingService::class)->cascadeWithdrawal($e, null);
        $this->assertTrue($this->sys(fn () => (new BookingCascadeLiveAssertion)->check()->passed));
    }

    public function test_attendance_report_reflects_state(): void
    {
        $s = $this->mkSession('completed', now()->subDay()->toDateTimeString(), now()->subDay()->addHour()->toDateTimeString());
        $student = User::factory()->create(['role' => 'student']);
        $e = $this->enrolment($student->id, 'active');
        $this->booking($s, $student->id, $e, 'attended');

        $auditor = User::factory()->create(['role' => 'academy_admin']);
        $this->sys(function () use ($auditor) {
            foreach (['operations', 'audit_read'] as $c) {
                DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $auditor->id, 'capability' => $c, 'granted_by' => $auditor->id, 'granted_at' => now()]);
            }
        });
        \Laravel\Sanctum\Sanctum::actingAs($auditor);
        $report = $this->getJson("/api/admin/programmes/{$this->programme->id}/attendance-report")->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $this->assertCount(1, $report['sessions']);
        $this->assertSame(1, $report['sessions'][0]['attended']);
        $this->assertCount(1, $report['attendance']);
        $this->assertSame($student->id, (int) $report['attendance'][0]['student_id']);
    }
}
