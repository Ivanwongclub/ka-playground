<?php

namespace App\Services\Sessions;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;

/**
 * S06-3 — attendance capture. Attendance is recorded ON the booking (booked →
 * attended | no_show) with the RECORDER's identity and time, and only while the
 * session is In Progress or Completed (never on a Draft/Published session, 2.3).
 * The recorder is the session's mentor or academy operations. Attendance ties
 * booking → enrolment → team_members by construction (the booking carries both).
 */
class AttendanceService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    public function mark(string $sessionId, int $studentId, string $status, User $recorder): void
    {
        if (! in_array($status, ['attended', 'no_show'], true)) {
            abort(422, 'Attendance status must be attended or no_show');
        }

        $this->scope->asSystem(
            'Attendance capture (S06-3): the mentor or academy records a booked student attended/no_show on an in-progress/completed session, stamping the recorder\'s identity. The recorder authority (session mentor, or academy operations) is resolved WITHIN the elevation via explicit actor-id filters — a recorder may not read the session through RLS, but the authority is a policy call, not a visibility one. session_bookings is a system-only write.',
            function () use ($sessionId, $studentId, $status, $recorder): void {
                $session = DB::table('programme_sessions')->where('id', $sessionId)->first() ?? abort(404);
                $this->assertRecorder($recorder, $session);
                if (! in_array($session->status, ['in_progress', 'completed'], true)) {
                    abort(409, "Attendance can only be taken on an In Progress or Completed session (is {$session->status})");
                }
                $booking = DB::table('session_bookings')->where('session_id', $sessionId)->where('student_id', $studentId)
                    ->whereIn('status', ['booked', 'attended', 'no_show'])->first();
                if ($booking === null) {
                    abort(404, 'No booked seat for this student to mark');
                }
                DB::table('session_bookings')->where('id', $booking->id)->update([
                    'status' => $status, 'recorded_by' => $recorder->id, 'recorded_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record('session_booking', $booking->id, "session_booking.{$status}",
                    fromState: $booking->status, toState: $status, programmeId: (int) $session->programme_id,
                    payloadAfter: ['session_id' => $sessionId, 'student_id' => $studentId, 'recorded_by' => $recorder->id], actor: $recorder);
            },
        );
    }

    private function assertRecorder(User $recorder, object $session): void
    {
        if ($session->mentor_id !== null && (int) $session->mentor_id === (int) $recorder->id) {
            return; // the session's mentor
        }
        if ($recorder->role === 'academy_admin') {
            $caps = DB::table('admin_capabilities')->where('user_id', $recorder->id)->pluck('capability');
            if ($caps->contains('operations') || $caps->contains('super_admin')) {
                return;
            }
        }
        abort(403, 'Only the session mentor or academy operations may record attendance');
    }
}
