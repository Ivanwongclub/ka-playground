<?php

namespace App\Http\Controllers;

use App\Services\Identity\RegistrationApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The registration review surface (S04C STEP 2). Authenticated reviewers only;
 * a family/member role that reaches here cannot SEE any registration_request
 * (RLS) so the service 404s before any decision. Which requests a reviewer may
 * act on is enforced by RLS, not by this controller: a school admin decides only
 * their routed requests, academy ops decides direct + all. The account queue
 * (listing) is STEP 4; these are the two decision endpoints.
 */
class RegistrationReviewController extends Controller
{
    public function __construct(private readonly RegistrationApprovalService $approvals) {}

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->guardReviewer($request);
        $user = $this->approvals->approve($id, $request->user());

        return response()->json([
            'account_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'verified' => $user->hasVerifiedEmail(), // false — activation pending
        ], 201);
    }

    public function decline(Request $request, string $id): JsonResponse
    {
        $this->guardReviewer($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->approvals->decline($id, $request->user(), $validated['reason']);

        return response()->json(['status' => 'declined']);
    }

    /** Defense in depth: only reviewer roles reach the service (RLS scopes the rows). */
    private function guardReviewer(Request $request): void
    {
        if (! in_array($request->user()->role, ['school_admin', 'academy_admin'], true)) {
            abort(403);
        }
    }
}
