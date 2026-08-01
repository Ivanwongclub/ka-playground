<?php

namespace App\Http\Controllers;

use App\Models\TeamFundraising;
use App\Services\Finance\FundraisingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S07 STEP 3 — a team declares its project type (sponsorship|charity) + funding
 * target (Pitch stage). Authority (team member) is enforced in the service.
 */
class FundraisingController extends Controller
{
    public function __construct(private readonly FundraisingService $fundraising) {}

    public function declare(Request $request, string $team): JsonResponse
    {
        $v = $request->validate([
            'project_type' => ['required', 'in:sponsorship,charity'],
            'funding_target_minor' => ['required', 'integer', 'min:0'],
        ]);
        $row = $this->fundraising->declareProject($team, $v['project_type'], (int) $v['funding_target_minor'], $request->user());

        return response()->json(['fundraising_id' => $row->id, 'project_type' => $row->project_type, 'funding_target_minor' => (int) $row->funding_target_minor], 201);
    }

    public function show(Request $request, string $team): JsonResponse
    {
        $row = TeamFundraising::query()->where('team_id', $team)->first();
        if ($row === null) {
            return response()->json(['message' => 'No fundraising declared'], 404);
        }

        return response()->json(['project_type' => $row->project_type, 'funding_target_minor' => (int) $row->funding_target_minor, 'currency' => $row->currency]);
    }
}
