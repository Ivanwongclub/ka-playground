<?php

namespace App\Services\Sessions;

use App\Events\BookingPromoted;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S06-3 — the booking workflow with a session waitlist and auto-promotion. A
 * student books a session in a programme they are live in (confirmed/active).
 * Capacity is serialised with SELECT … FOR UPDATE on the session row (BI-3 shape):
 * under capacity → booked (session flips to full at capacity); at capacity →
 * waitlisted. Cancelling a BOOKED slot auto-promotes the earliest waitlisted
 * booking; if none waits, a full session re-opens.
 */
class BookingService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    /** A student books a session in their programme. Idempotent per live booking. */
    public function book(string $sessionId, User $student): array
    {
        return $this->scope->asSystem(
            'Session booking (S06-3): a student books a session in a programme they are live in; capacity is claimed under FOR UPDATE on the session row (BI-3), over-capacity waitlists. The booking student\'s own enrolment is the only one touched.',
            function () use ($sessionId, $student): array {
                return DB::transaction(function () use ($sessionId, $student): array {
                    $session = DB::selectOne('SELECT id, programme_id, team_id, capacity, status FROM programme_sessions WHERE id = ? FOR UPDATE', [$sessionId]);
                    if ($session === null) {
                        abort(404);
                    }
                    if (! in_array($session->status, ['published', 'full'], true)) {
                        abort(409, "Session is {$session->status}; only a published/full session accepts bookings");
                    }
                    $enrolment = DB::table('enrolments')->where('programme_id', $session->programme_id)
                        ->where('student_id', $student->id)->whereIn('status', ['confirmed', 'active'])->first();
                    if ($enrolment === null) {
                        abort(403, 'You are not a live participant in this programme');
                    }
                    if ($session->team_id !== null && ! DB::table('team_members')->where('team_id', $session->team_id)
                        ->where('student_id', $student->id)->where('status', 'active')->exists()) {
                        abort(403, 'This session is for a team you are not an active member of');
                    }
                    $existing = DB::table('session_bookings')->where('session_id', $sessionId)
                        ->where('enrolment_id', $enrolment->id)->where('status', '<>', 'cancelled')->first();
                    if ($existing !== null) {
                        return ['booking_id' => $existing->id, 'status' => $existing->status]; // idempotent
                    }

                    $booked = (int) DB::table('session_bookings')->where('session_id', $sessionId)->where('status', 'booked')->count();
                    $status = $booked < (int) $session->capacity ? 'booked' : 'waitlisted';
                    $id = (string) Str::uuid7();
                    DB::table('session_bookings')->insert([
                        'id' => $id, 'session_id' => $sessionId, 'enrolment_id' => $enrolment->id,
                        'student_id' => $student->id, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($status === 'booked' && $booked + 1 >= (int) $session->capacity && $session->status === 'published') {
                        DB::table('programme_sessions')->where('id', $sessionId)->update(['status' => 'full', 'updated_at' => now()]);
                    }
                    $this->audit->record('session_booking', $id, "session_booking.{$status}",
                        toState: $status, programmeId: (int) $session->programme_id,
                        payloadAfter: ['session_id' => $sessionId, 'student_id' => $student->id], actor: $student);

                    return ['booking_id' => $id, 'status' => $status];
                });
            },
        );
    }

    /** A student cancels their booking; a freed booked slot auto-promotes the earliest waitlisted. */
    public function cancel(string $sessionId, User $student): array
    {
        return $this->scope->asSystem(
            'Session booking cancel (S06-3): a student cancels their booking under FOR UPDATE on the session row; a freed booked slot auto-promotes the earliest waitlisted booking (or the full session re-opens). Only this student\'s booking and at most one promoted booking are touched.',
            function () use ($sessionId, $student): array {
                return DB::transaction(function () use ($sessionId, $student): array {
                    $session = DB::selectOne('SELECT id, programme_id, capacity, status FROM programme_sessions WHERE id = ? FOR UPDATE', [$sessionId]);
                    if ($session === null) {
                        abort(404);
                    }
                    $booking = DB::table('session_bookings')->where('session_id', $sessionId)
                        ->where('student_id', $student->id)->whereIn('status', ['booked', 'waitlisted'])->first();
                    if ($booking === null) {
                        abort(404, 'No live booking to cancel');
                    }
                    $wasBooked = $booking->status === 'booked';
                    DB::table('session_bookings')->where('id', $booking->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                    $this->audit->record('session_booking', $booking->id, 'session_booking.cancelled',
                        fromState: $booking->status, toState: 'cancelled', programmeId: (int) $session->programme_id, actor: $student);

                    $promoted = null;
                    if ($wasBooked) {
                        $next = DB::table('session_bookings')->where('session_id', $sessionId)->where('status', 'waitlisted')
                            ->orderBy('created_at')->orderBy('id')->first();
                        if ($next !== null) {
                            DB::table('session_bookings')->where('id', $next->id)->update(['status' => 'booked', 'updated_at' => now()]);
                            $this->audit->record('session_booking', $next->id, 'session_booking.promoted',
                                fromState: 'waitlisted', toState: 'booked', programmeId: (int) $session->programme_id,
                                payloadAfter: ['session_id' => $sessionId, 'student_id' => $next->student_id]);
                            BookingPromoted::dispatch($sessionId, $next->id, (int) $next->student_id);
                            $promoted = (int) $next->student_id;
                        } elseif ($session->status === 'full') {
                            DB::table('programme_sessions')->where('id', $sessionId)->update(['status' => 'published', 'updated_at' => now()]);
                        }
                    }

                    return ['cancelled' => $booking->id, 'promoted_student_id' => $promoted];
                });
            },
        );
    }
}
