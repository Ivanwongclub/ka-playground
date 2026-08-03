<?php

namespace App\Http\Controllers;

use App\Services\Authz\ScopeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S-UX3-4 STEP 1 — the attendance READ surface over the built S06 engine (S06 shipped
 * write + report only; these are the missing reads). Attendance is a MINOR's presence
 * record — child-safety-grade. Three reads, each resolving in the CALLER'S OWN RLS
 * except the mentor roster, which crosses the users wall exactly like B2's team roster:
 *
 *   GET /my/sessions                      — student self. NO elevation: programme_sessions
 *       roster clause + session_bookings self clause admit the student's own rows only.
 *   GET /my/students/{id}/sessions        — guardian → their OWN child. NO elevation:
 *       users_read (guardian AND id ∈ student_ids) + session_bookings guardian clause admit
 *       the linked child; a non-linked child is refused 403 BEFORE any read.
 *   GET /admin/sessions/{id}/roster       — the session's mentor OR academy ops/audit.
 *       NEW narrow elevation: a mentor's users_read admits only school-linked students, but
 *       a session's attendees are the D4 programme/team roster (spanning schools), so
 *       resolving roster NAMES crosses the users wall. Allowlist is TIGHT (see REASON).
 */
class SessionReadController extends Controller
{
    public function __construct(private readonly ScopeContext $scope) {}

    private const ROSTER_REASON = 'Session attendance roster read (S-UX3-4): the session mentor (or academy operations/audit) reads THIS session\'s roster. A mentor\'s users_read admits only students on the mentor\'s own school_links, but a session\'s attendees are the D4 programme/team roster which spans schools and includes direct-registered students, so resolving roster DISPLAY NAMES crosses the users wall for the NARROW purpose of returning, per booked student of THIS ONE session, the student_id, display name and the attendance fact (booked|waitlisted|attended|no_show). WITHHELD, never returned: consent status, guardian identity, enrolment detail, other teams, cross-session linking, money/obligations, and the recorder identity/time (those live in the ops audit report). Authority (this session\'s mentor, or academy operations/super_admin/audit_read) was established under the caller\'s own RLS before the elevation.';

    /** Student self: the sessions of programmes the student holds a live enrolment in, plus their OWN booking/attendance. */
    public function mySessions(Request $request): JsonResponse
    {
        $uid = $request->user()->id;

        // Under the student's RLS: programme_sessions.ps_read admits sessions of programmes they are
        // enrolled in; session_bookings self clause admits only their own booking. The explicit
        // student_id filter on the join is belt-and-suspenders alongside RLS — never another student.
        $rows = DB::table('programme_sessions as ps')
            ->leftJoin('session_bookings as sb', function ($j) use ($uid): void {
                $j->on('sb.session_id', '=', 'ps.id')->where('sb.student_id', '=', $uid);
            })
            ->orderBy('ps.starts_at')
            ->get(['ps.id', 'ps.title', 'ps.starts_at', 'ps.ends_at', 'ps.status', 'ps.team_id', 'sb.status as booking_status']);

        return response()->json(['sessions' => $rows->map(fn ($s): array => [
            'id' => $s->id,
            'title' => $s->title,
            'starts_at' => $s->starts_at,
            'ends_at' => $s->ends_at,
            'status' => $s->status,
            'team_id' => $s->team_id,
            'booking_status' => $s->booking_status, // null = not booked; booked|waitlisted|attended|no_show|cancelled
        ])->all()]);
    }

    /** Guardian → their OWN linked child's sessions + that child's booking/attendance. Non-linked child → 403. */
    public function childSessions(Request $request, int $studentId): JsonResponse
    {
        // Child-guard FIRST, under the guardian's own RLS (they see only their own active links). A studentId
        // that is not an active link of this guardian → 403 before any attendance is read.
        $linked = DB::table('guardian_links')
            ->where('guardian_id', $request->user()->id)->where('student_id', $studentId)
            ->where('status', 'active')->exists();
        if (! $linked) {
            abort(403, 'Not a guardian of this student');
        }

        // Under the guardian's RLS: ps_read roster (child ∈ student_ids) admits the child's programme sessions;
        // session_bookings guardian clause admits the child's bookings. Scoped to THIS child by the joins.
        $rows = DB::table('programme_sessions as ps')
            ->join('enrolments as e', function ($j) use ($studentId): void {
                $j->on('e.programme_id', '=', 'ps.programme_id')->where('e.student_id', '=', $studentId);
            })
            ->leftJoin('session_bookings as sb', function ($j) use ($studentId): void {
                $j->on('sb.session_id', '=', 'ps.id')->where('sb.student_id', '=', $studentId);
            })
            ->distinct()
            ->orderBy('ps.starts_at')
            ->get(['ps.id', 'ps.title', 'ps.starts_at', 'ps.ends_at', 'ps.status', 'ps.team_id', 'sb.status as booking_status']);

        return response()->json(['student_id' => $studentId, 'sessions' => $rows->map(fn ($s): array => [
            'id' => $s->id,
            'title' => $s->title,
            'starts_at' => $s->starts_at,
            'ends_at' => $s->ends_at,
            'status' => $s->status,
            'team_id' => $s->team_id,
            'booking_status' => $s->booking_status,
        ])->all()]);
    }

    /**
     * A mentor lists the sessions they are the assigned mentor of — METADATA ONLY (title/time/status/
     * capacity + attendance-count aggregates). NO minor identity leaves this list (that is the by-id
     * roster's job). NO elevation: ps_read admits mentor_id=actor, and the counts run over session_bookings
     * the mentor's own RLS already admits (mentor clause). Added S-UX3-4 STEP 2 to feed the mentor UI.
     */
    public function mentorSessions(Request $request): JsonResponse
    {
        $uid = $request->user()->id;

        $sessions = DB::table('programme_sessions as ps')
            ->where('ps.mentor_id', $uid) // belt-and-suspenders alongside ps_read's mentor clause
            ->orderBy('ps.starts_at')
            ->get(['ps.id', 'ps.title', 'ps.starts_at', 'ps.ends_at', 'ps.status', 'ps.capacity']);

        $counts = DB::table('session_bookings')
            ->whereIn('session_id', $sessions->pluck('id'))
            ->selectRaw("session_id,
                COUNT(*) FILTER (WHERE status IN ('booked','attended','no_show')) AS booked,
                COUNT(*) FILTER (WHERE status = 'attended') AS attended,
                COUNT(*) FILTER (WHERE status = 'no_show') AS no_show")
            ->groupBy('session_id')->get()->keyBy('session_id');

        return response()->json(['sessions' => $sessions->map(fn ($s): array => [
            'id' => $s->id,
            'title' => $s->title,
            'starts_at' => $s->starts_at,
            'ends_at' => $s->ends_at,
            'status' => $s->status,
            'capacity' => $s->capacity,
            'booked' => (int) ($counts[$s->id]->booked ?? 0),
            'attended' => (int) ($counts[$s->id]->attended ?? 0),
            'no_show' => (int) ($counts[$s->id]->no_show ?? 0),
        ])->all()]);
    }

    /** The session's mentor (or academy ops/audit) reads THIS session's attendance roster. */
    public function roster(Request $request, string $id): JsonResponse
    {
        // Fetch the session under the CALLER'S RLS. ps_read admits it to the mentor, to ops/audit, AND to an
        // enrolled student (roster clause) — so RLS visibility is NOT the authority here. The explicit
        // authority check below is what walls a student off from the roster (they may see the session row,
        // never the roster of minors on it).
        $session = DB::table('programme_sessions')->where('id', $id)
            ->first(['id', 'title', 'starts_at', 'ends_at', 'status', 'capacity', 'mentor_id', 'programme_id']) ?? abort(404);

        $user = $request->user();
        $isMentor = $session->mentor_id !== null && (int) $session->mentor_id === (int) $user->id;
        $isOps = false;
        if ($user->role === 'academy_admin') {
            $caps = DB::table('admin_capabilities')->where('user_id', $user->id)->pluck('capability');
            $isOps = $caps->contains('operations') || $caps->contains('super_admin') || $caps->contains('audit_read');
        }
        if (! $isMentor && ! $isOps) {
            abort(403, 'Only the session mentor or academy operations may view the attendance roster');
        }

        // Names cross the users wall for a mentor → the TIGHT allowlisted elevation. Only {student_id,
        // student_name, status} for live/marked bookings of THIS session leave the closure.
        $roster = $this->scope->asSystem(self::ROSTER_REASON, fn (): array => DB::table('session_bookings as sb')
            ->leftJoin('users as u', 'u.id', '=', 'sb.student_id')
            ->where('sb.session_id', $session->id)
            ->whereIn('sb.status', ['booked', 'waitlisted', 'attended', 'no_show'])
            ->orderBy('u.name')
            ->get(['sb.student_id', 'u.name as student_name', 'sb.status'])
            ->map(fn ($r): array => [
                'student_id' => (int) $r->student_id,
                'student_name' => $r->student_name,
                'status' => $r->status,
            ])->all());

        return response()->json([
            // The mentor's own session context (RLS-read above) — not child data.
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'starts_at' => $session->starts_at,
                'ends_at' => $session->ends_at,
                'status' => $session->status,
                'capacity' => $session->capacity,
            ],
            'roster' => $roster,
        ]);
    }
}
