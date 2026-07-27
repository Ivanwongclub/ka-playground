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
        $underStrength = DB::table('teams')->where('programme_id', $programmeId)->where('status', 'forming')
            ->get()->map(fn ($t) => [
                'team_id' => $t->id, 'name' => $t->name, 'category_id' => $t->category_id,
                'member_count' => DB::table('team_members')->where('team_id', $t->id)->where('status', 'active')->count(),
            ]);
        $teamedEnrolmentIds = DB::table('team_members')->where('status', 'active')->pluck('enrolment_id');
        $unplaced = DB::table('enrolments')->where('programme_id', $programmeId)->where('status', 'in_pool')
            ->whereNotIn('id', $teamedEnrolmentIds)
            ->get(['id', 'student_id']);
        $parked = DB::table('team_exceptions')->where('programme_id', $programmeId)
            ->where('type', 'parked_rollforward')->where('status', 'open')
            ->get(['id', 'enrolment_id', 'backstop_at', 'created_at']);

        return response()->json([
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
