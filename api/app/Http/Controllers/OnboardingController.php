<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuthEventType;
use App\Services\Identity\GuardianStudentService;
use App\Services\Identity\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly GuardianStudentService $guardianStudents,
        private readonly AuditService $audit,
    ) {}

    /** Academy issues an invitation (L4). Permission: operations.manage. */
    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', 'string'],
        ]);

        $result = $this->invitations->issue($request->user(), $validated['email'], $validated['role']);

        // The plain token is returned exactly once (delivery via the notification
        // engine when it lands in S09; locally the admin conveys the link)
        return response()->json([
            'invitation_id' => $result['invitation']->id,
            'token' => $result['plain_token'],
            'expires_at' => $result['invitation']->expires_at->toIso8601String(),
        ], 201);
    }

    /** Recipient sets a password through the single-use link (2.11). */
    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        $user = $this->invitations->accept($validated['token'], $validated['password']);

        return response()->json([
            'user_id' => $user->id,
            'verification_required' => true,
        ], 201);
    }

    /** Signed email-verification link — mandatory before first login (2.11). */
    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            $this->audit->record(
                entityType: 'user',
                entityId: (string) $user->id,
                action: AuthEventType::EmailVerified->value,
                toState: 'verified',
                actor: $user,
            );
        }

        return response()->json(['verified' => true]);
    }

    /** Guardian creates the student account (L4 — guardian is the anchor). */
    public function createStudent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        $student = $this->guardianStudents->createStudent(
            $request->user(),
            $validated['name'],
            $validated['email'],
            $validated['password'],
        );

        return response()->json(['student_id' => $student->id], 201);
    }
}
