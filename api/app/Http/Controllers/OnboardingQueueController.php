<?php

namespace App\Http\Controllers;

use App\Services\Identity\OnboardingQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S04C STEP 4 — the ONE queue (read surface). Reviewer roles only; RLS scopes
 * what each sees (school admin = own routed items, academy ops = all). The
 * decisions themselves are the STEP 2/3 endpoints — two decisions, two audit
 * rows; this is the single place the pending work is surfaced together.
 */
class OnboardingQueueController extends Controller
{
    public function __construct(private readonly OnboardingQueueService $queue) {}

    public function index(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['school_admin', 'academy_admin'], true)) {
            abort(403);
        }

        return response()->json($this->queue->queue());
    }
}
