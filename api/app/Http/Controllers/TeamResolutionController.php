<?php

namespace App\Http\Controllers;

use App\Jobs\ConsumePaymentObligations;
use App\Services\Teams\TeamResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S05-4 — the four terminal actions on a below-min exception (OD-37). Authority
 * (academy operations/super) is enforced in TeamResolutionService.
 */
class TeamResolutionController extends Controller
{
    public function __construct(private readonly TeamResolutionService $resolution) {}

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['enrolment_id' => 'required|uuid']);
        $result = $this->resolution->assign($id, $data['enrolment_id'], $request->user());
        if (! empty($result['obligation_id'])) {
            ConsumePaymentObligations::dispatch(); // issue the assigned member's order after commit (skipped when already paid)
        }

        return response()->json($result);
    }

    public function extendGrace(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['enrolment_id' => 'required|uuid']);
        $this->resolution->extendGrace($id, $data['enrolment_id'], $request->user());

        return response()->json(['status' => 'grace_extended']);
    }

    public function waive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string']);
        $this->resolution->waive($id, $data['reason'], $request->user());

        return response()->json(['status' => 'waived']);
    }

    public function dissolve(Request $request, string $id): JsonResponse
    {
        $result = $this->resolution->dissolve($id, $request->user());

        return response()->json($result);
    }

    /** OD-62 — {id} is the member's enrolment id; the team stands. */
    public function schoolLeave(Request $request, string $id): JsonResponse
    {
        $exceptionId = $this->resolution->recordSchoolLeave($id, $request->user());

        return response()->json(['status' => 'school_leave_recorded', 'exception_id' => $exceptionId]);
    }
}
