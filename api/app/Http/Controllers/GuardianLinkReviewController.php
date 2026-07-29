<?php

namespace App\Http\Controllers;

use App\Services\Identity\LinkageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S04C STEP 3 — the guardian-link approval surface (FLAG #2). Separate decision
 * from approving a person: this activates a RELATIONSHIP. Reviewer roles only
 * (a guardian cannot self-activate their own pending link, enforced here — not by
 * UI), and RLS scopes which pending links each reviewer can act on. The queue
 * listing is STEP 4; these are the decision endpoints.
 */
class GuardianLinkReviewController extends Controller
{
    public function __construct(private readonly LinkageService $linkage) {}

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->guardReviewer($request);
        $this->linkage->approveLink($id, $request->user());

        return response()->json(['status' => 'active']);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $this->guardReviewer($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->linkage->rejectLink($id, $request->user(), $validated['reason']);

        return response()->json(['status' => 'rejected']);
    }

    private function guardReviewer(Request $request): void
    {
        if (! in_array($request->user()->role, ['school_admin', 'academy_admin'], true)) {
            abort(403); // a guardian/student cannot decide a relationship
        }
    }
}
