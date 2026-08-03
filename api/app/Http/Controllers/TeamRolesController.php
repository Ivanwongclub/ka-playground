<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S-UX3-3a STEP 3 — Backend delta B3: the team roles & tenure-ledger read (GET /teams/{team}/roles).
 *
 * AUTHORITY (stated explicitly, NOT inherited from B0's consent-status gate):
 *  (a) MEMBER-READABLE, resolved WITHIN the caller's RLS — there is NO assertApprover / OD-39 gate. The
 *      existing `tenures_read` policy admits: system · academy ops/audit/super · the holder
 *      (student_id = actor) · the holder's active guardian · the lobby school-admin. So a holder sees
 *      their own tenure and a guardian their child's, while ops / lobby-admin see the full team's
 *      tenures — a DIFFERENT five-branch shape than B0 (which is ops-only and 403s a guardian).
 *  (b) NO elevation — this read never calls asSystem. Role DEFINITIONS come from `role_library`
 *      (programme config, not RLS-scoped, so the role list itself is visible); only the HOLDERS
 *      (tenures) are RLS-scoped, and each holder name rides `users_read` through a double-gated LEFT
 *      join (NULL when the caller may not see that user — never the raw id).
 *
 * ONE-ACTIVE-HOLDER INVARIANT: `current` is the single state='active' tenure per role — the DB partial
 * unique index `tenures_one_active_role (team_id, role_id) WHERE state='active'` guarantees at most one,
 * so the read can never surface two open holders for a role. `past` are ended tenures (completed /
 * terminated, each carrying an ended_at). An active tenure has no ended_at; an ended one always does.
 */
class TeamRolesController extends Controller
{
    public function show(Request $request, string $team): JsonResponse
    {
        // RLS-shaped: a team the caller cannot see is absent → 404, never a 403 existence leak.
        $t = DB::table('teams')->where('id', $team)->first() ?? abort(404);

        // Role definitions for the programme (all defined roles, incl. vacant ones) — role_library is
        // programme config, not RLS-scoped.
        $roles = DB::table('role_library')->where('programme_id', $t->programme_id)
            ->orderBy('name_en')->get(['id', 'name_en', 'name_tc', 'name_sc', 'mandatory']);

        // Tenures visible to THIS caller under tenures_read; holder name double-gated by users_read.
        $tenures = DB::table('tenures as tn')
            ->leftJoin('users as u', 'u.id', '=', 'tn.student_id')
            ->where('tn.team_id', $t->id)
            ->orderBy('tn.started_at')
            ->get(['tn.role_id', 'tn.student_id', 'u.name as student_name', 'tn.state', 'tn.started_at', 'tn.ended_at', 'tn.ended_reason']);
        $byRole = $tenures->groupBy('role_id');

        $out = $roles->map(function ($r) use ($byRole) {
            $ts = $byRole->get($r->id, collect());
            $active = $ts->firstWhere('state', 'active'); // <= 1 by the DB partial-unique invariant
            $past = $ts->filter(fn ($x) => in_array($x->state, ['completed', 'terminated'], true))
                ->sortByDesc('ended_at')->values();

            return [
                'role_id' => $r->id,
                'name_en' => $r->name_en, 'name_tc' => $r->name_tc, 'name_sc' => $r->name_sc,
                'mandatory' => (bool) $r->mandatory,
                'current' => $active ? [
                    'student_id' => (int) $active->student_id,
                    'student_name' => $active->student_name,
                    'started_at' => $active->started_at,
                ] : null,
                'past' => $past->map(fn ($x) => [
                    'student_id' => (int) $x->student_id,
                    'student_name' => $x->student_name,
                    'started_at' => $x->started_at,
                    'ended_at' => $x->ended_at,
                    'ended_reason' => $x->ended_reason,
                ])->all(),
            ];
        });

        // Active members for the assign/rotate picker (enrolment_id needed by POST /teams/{id}/roles),
        // resolved under the caller's RLS — no elevation. For ops/lobby-admin this is the full roster.
        $members = DB::table('team_members as tm')
            ->leftJoin('users as u', 'u.id', '=', 'tm.student_id')
            ->where('tm.team_id', $t->id)->where('tm.status', 'active')
            ->orderBy('u.name')
            ->get(['tm.enrolment_id', 'tm.student_id', 'u.name as student_name']);

        return response()->json([
            'team_id' => $t->id,
            'roles' => $out->values()->all(),
            'members' => $members->map(fn ($m) => [
                'enrolment_id' => $m->enrolment_id,
                'student_id' => (int) $m->student_id,
                'student_name' => $m->student_name,
            ])->all(),
        ]);
    }
}
