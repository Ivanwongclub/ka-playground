<?php

namespace App\Http\Controllers;

use App\Services\Enrolments\EnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrolmentController extends Controller
{
    public function __construct(private readonly EnrolmentService $enrolments) {}

    /** Guardian creates the INTENT (2.22). Response carries NO consent data. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'programme_id' => ['required', 'integer'],
            'student_id' => ['required', 'integer'],
        ]);
        $enrolment = $this->enrolments->create(
            (int) $data['programme_id'], (int) $data['student_id'], $request->user(),
        );

        return response()->json([
            'id' => $enrolment->id, 'programme_id' => $enrolment->programme_id,
            'student_id' => $enrolment->student_id, 'status' => $enrolment->status,
        ], 201);
    }

    /**
     * RLS-shaped list: each session sees exactly its branch of the read set.
     * S-UX2b: additive display names. LEFT JOINs only — a name-join must never drop a row.
     * Names are further gated by each joined table's own RLS (users_read, programmes): a name
     * resolves iff the caller could already SELECT that row, else NULL. So a student who sees
     * their own enrolment but cannot read the guardian's user row gets acting_guardian = NULL.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('enrolments as e')
            ->leftJoin('programmes as p', 'p.id', '=', 'e.programme_id')
            ->leftJoin('users as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as g', 'g.id', '=', 'e.acting_guardian_id')
            ->orderBy('e.created_at')
            ->get([
                'e.id', 'e.programme_id', 'e.student_id', 'e.acting_guardian_id', 'e.status', 'e.created_at',
                'p.name_en as programme_name_en', 'p.name_tc as programme_name_tc', 'p.name_sc as programme_name_sc',
                's.name as student_name', 'g.name as acting_guardian',
            ])]);
    }

    /**
     * S-READ-1 — enrolment DETAIL (audit C4 "the pivot"). The index() query NARROWED BY id, under the SAME
     * enr_read RLS + permission:enrolment.view — no new arm, no relaxed policy. A row outside the viewer's scope
     * is not returned by RLS → 404 (never 403, never a partial body; Proposal A1 deep-link corollary).
     * Read-only, additive: no migration, no policy, no elevation.
     *
     * Every ADDED field comes from a row the viewer ALREADY reaches under their own RLS — nothing widens:
     *   • banner_url  ← programmes.banner_upload_id → uploads(status='clean'). `uploads` is global, no PII; the
     *     URL is the same "/api/programmes/{id}/banner" the marketplace catalogue builds (clean ⇒ URL, else null).
     *   • team_id / team_name ← the viewer's OWN (or child's) active team_members row (tm_read scope) → teams
     *     (teams_read memberOf), matched to THIS enrolment's programme. team_members + teams are FORCE RLS, so the
     *     join CANNOT return a row the viewer's own policies would not — a non-member (or any out-of-scope student)
     *     simply yields null. No filter substitutes for the policy here.
     * DROPPED, each with a named blocker — a dropped field beats a widened read (Step 6 §6.1):
     *   • member_count — tm_read admits only the viewer's own/child row; counting teammates is a NEW visibility
     *     path. Needs an elevation (different review tier) or a server-computed aggregate returning a NUMBER, not
     *     rows — a separate card, a separate ruling.
     *   • per-transition timestamps (stepper dated knots, Kit §3.4) — live only in audit_events (audit_read-gated).
     *     NOT a never: a purpose-built enrolment transition-log read returning {state, at} and nothing else (no
     *     actor, no reason, no before/after) is a designed card with its own review.
     */
    public function show(string $id): JsonResponse
    {
        $e = DB::table('enrolments as e')
            ->leftJoin('programmes as p', 'p.id', '=', 'e.programme_id')
            ->leftJoin('users as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as g', 'g.id', '=', 'e.acting_guardian_id')
            ->where('e.id', $id)
            ->first([
                'e.id', 'e.programme_id', 'e.student_id', 'e.acting_guardian_id', 'e.status', 'e.created_at',
                'p.name_en as programme_name_en', 'p.name_tc as programme_name_tc', 'p.name_sc as programme_name_sc',
                'p.banner_upload_id', 's.name as student_name', 'g.name as acting_guardian',
            ]);

        if ($e === null) {
            abort(404); // absent from the viewer's RLS-scoped enrolments ⇒ not entitled (A1: 404, never 403)
        }

        $bannerUrl = ($e->banner_upload_id !== null
            && DB::table('uploads')->where('id', $e->banner_upload_id)->where('status', 'clean')->exists())
            ? "/api/programmes/{$e->programme_id}/banner" : null;

        // The enrolment's team: the viewer's own/child active membership (tm_read) in a team of THIS programme
        // (teams_read). Both FORCE RLS → a non-member, or a student outside the viewer's scope, resolves to null.
        $team = DB::table('teams as t')
            ->join('team_members as tm', 'tm.team_id', '=', 't.id')
            ->where('tm.student_id', $e->student_id)
            ->where('tm.status', 'active')
            ->where('t.programme_id', $e->programme_id)
            ->first(['t.id as team_id', 't.name as team_name']);

        return response()->json([
            'id' => $e->id,
            'programme_id' => $e->programme_id,
            'student_id' => $e->student_id,
            'acting_guardian_id' => $e->acting_guardian_id,
            'status' => $e->status,
            'created_at' => $e->created_at,
            'programme_name_en' => $e->programme_name_en,
            'programme_name_tc' => $e->programme_name_tc,
            'programme_name_sc' => $e->programme_name_sc,
            'student_name' => $e->student_name,
            'acting_guardian' => $e->acting_guardian,
            'banner_url' => $bannerUrl,
            'team_id' => $team->team_id ?? null,
            'team_name' => $team->team_name ?? null,
        ]);
    }
}
