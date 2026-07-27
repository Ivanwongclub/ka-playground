<?php

namespace App\Http\Controllers;

use App\Jobs\ConsumePaymentObligations;
use App\Services\Teams\TeamConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamConfirmationController extends Controller
{
    public function __construct(private readonly TeamConfirmationService $confirmation) {}

    /** Submitter (student) sends a forming team for approval. */
    public function submit(Request $request, string $id): JsonResponse
    {
        $this->confirmation->submit($id, $request->user());

        return response()->json(['status' => 'submitted']);
    }

    /** 成團 — an approver confirms; the outbox consumer is dispatched AFTER the tx commits. */
    public function confirm(Request $request, string $id): JsonResponse
    {
        $result = $this->confirmation->confirm($id, $request->user());
        ConsumePaymentObligations::dispatch(); // after commit: issue orders + fire PaymentRequested

        return response()->json($result);
    }
}
