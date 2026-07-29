<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * S06-7 audit element — the Attendance & Session Report (client-facing product
 * screen, distinct from AUDIT.md). Per programme: each session's booked/attended/
 * no-show with recorder identity, the reschedule/cancellation history (immutable
 * session_versions), the waitlist-promotion log, and the Learn threshold. RLS-shaped.
 */
class SessionAttendanceReportController extends Controller
{
    public function show(string $programmeId): JsonResponse
    {
        $sessions = DB::table('programme_sessions')->where('programme_id', $programmeId)
            ->orderBy('starts_at')
            ->get(['id', 'title', 'starts_at', 'ends_at', 'status', 'capacity', 'mentor_id'])
            ->map(fn ($s) => (array) $s + [
                'booked' => DB::table('session_bookings')->where('session_id', $s->id)->where('status', 'booked')->count(),
                'waitlisted' => DB::table('session_bookings')->where('session_id', $s->id)->where('status', 'waitlisted')->count(),
                'attended' => DB::table('session_bookings')->where('session_id', $s->id)->where('status', 'attended')->count(),
                'no_show' => DB::table('session_bookings')->where('session_id', $s->id)->where('status', 'no_show')->count(),
            ]);

        $attendance = DB::table('session_bookings')
            ->join('programme_sessions', 'programme_sessions.id', '=', 'session_bookings.session_id')
            ->where('programme_sessions.programme_id', $programmeId)
            ->whereIn('session_bookings.status', ['attended', 'no_show'])
            ->get(['session_bookings.session_id', 'session_bookings.student_id', 'session_bookings.status', 'session_bookings.recorded_by', 'session_bookings.recorded_at']);

        $rescheduleHistory = DB::table('session_versions')
            ->join('programme_sessions', 'programme_sessions.id', '=', 'session_versions.session_id')
            ->where('programme_sessions.programme_id', $programmeId)
            ->orderByDesc('session_versions.created_at')
            ->get(['session_versions.session_id', 'session_versions.version', 'session_versions.payload', 'session_versions.reason', 'session_versions.created_at']);

        $waitlistPromotions = DB::table('audit_events')->where('programme_id', $programmeId)
            ->where('action', 'session_booking.promoted')->orderByDesc('occurred_at')
            ->get(['entity_id as booking_id', 'payload_after', 'occurred_at']);

        $learnThreshold = DB::table('certification_rules')->where('programme_id', $programmeId)
            ->value('team_gate_pass_pct');

        return response()->json([
            'sessions' => $sessions,
            'attendance' => $attendance,
            'reschedule_history' => $rescheduleHistory,
            'waitlist_promotions' => $waitlistPromotions,
            'learn_team_gate_pass_pct' => $learnThreshold,
        ]);
    }
}
