<?php

namespace App\Http\Controllers;

use App\Services\Teams\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S05-3 deadline matching screen (OD-35): under-strength teams beside the
 * unplaced pool, with the three admin actions. Screen reads are shaped by RLS
 * (team_exceptions/enrolments/teams are all scoped); the action authority is
 * enforced in MatchingService.
 */
class MatchingController extends Controller
{
    public function __construct(private readonly MatchingService $matching) {}

    /** Screen data: under-strength forming teams, the unplaced (in_pool) pool, and open parked exceptions. */
    public function screen(string $programmeId): JsonResponse
    {
        // Additive programme min-team-size so the display can render "member_count vs min" (S-UX3-3a
        // STEP 4). Programme config (team_rules), not RLS-scoped.
        $min = json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $programmeId)->where('section_key', 'team_rules')->value('data'), true)['min_team_size'] ?? null;

        $underStrength = DB::table('teams')->where('programme_id', $programmeId)->where('status', 'forming')
            ->get()->map(fn ($t) => [
                'team_id' => $t->id, 'name' => $t->name, 'category_id' => $t->category_id,
                'member_count' => DB::table('team_members')->where('team_id', $t->id)->where('status', 'active')->count(),
            ]);
        $teamedEnrolmentIds = DB::table('team_members')->where('status', 'active')->pluck('enrolment_id');
        // Backend delta B4 — ADDITIVE names (S-UX2b): the bare student_id gains a student_name via a
        // double-gated LEFT join (users_read; NULL when the caller may not see that user, never the raw
        // id). Count-preserving — the unplaced row survives even when the name is hidden. The read runs
        // under the caller's RLS: NO elevation.
        $unplaced = DB::table('enrolments as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.student_id')
            ->where('e.programme_id', $programmeId)->where('e.status', 'in_pool')
            ->whereNotIn('e.id', $teamedEnrolmentIds)
            ->get(['e.id', 'e.student_id', 'u.name as student_name']);
        // B4 — parked rows gain the parked student's name (exception → enrolment → user).
        $parked = DB::table('team_exceptions as x')
            ->leftJoin('enrolments as e', 'e.id', '=', 'x.enrolment_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.student_id')
            ->where('x.programme_id', $programmeId)->where('x.type', 'parked_rollforward')->where('x.status', 'open')
            ->get(['x.id', 'x.enrolment_id', 'u.name as student_name', 'x.backstop_at', 'x.created_at']);

        return response()->json([
            'min_team_size' => $min,
            'under_strength_teams' => $underStrength,
            'unplaced_students' => $unplaced,
            'parked' => $parked,
        ]);
    }

    public function match(Request $request): JsonResponse
    {
        $data = $request->validate(['enrolment_id' => 'required|uuid', 'team_id' => 'required|uuid']);
        $this->matching->match($data['enrolment_id'], $data['team_id'], $request->user());

        return response()->json(['status' => 'matched']);
    }

    public function roll(Request $request): JsonResponse
    {
        $data = $request->validate(['enrolment_id' => 'required|uuid']);
        $id = $this->matching->roll($data['enrolment_id'], $request->user());

        return response()->json(['status' => 'rolled', 'exception_id' => $id]);
    }

    public function release(Request $request): JsonResponse
    {
        $data = $request->validate(['enrolment_id' => 'required|uuid']);
        $this->matching->release($data['enrolment_id'], $request->user());

        return response()->json(['status' => 'released']);
    }
}
