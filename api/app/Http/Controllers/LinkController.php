<?php

namespace App\Http\Controllers;

use App\Events\GuardianLinkActivated;
use App\Models\GuardianLink;
use App\Models\User;
use App\Services\Identity\LinkRevocationService;
use App\Services\Identity\PairingService;
use App\Services\Authz\ScopeContext;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LinkController extends Controller
{
    public function __construct(
        private readonly PairingService $pairing,
        private readonly LinkRevocationService $revocation,
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly \App\Services\Identity\LinkageService $linkage,
    ) {}

    public function generateCode(Request $request): JsonResponse
    {
        $code = $this->pairing->generate($request->user());

        return response()->json([
            'code' => $code->code,
            'expires_at' => $code->expires_at->toIso8601String(),
        ], 201);
    }

    public function redeemCode(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $link = $this->pairing->redeem($request->user(), $validated['code']);

        return response()->json(['link_id' => $link->id, 'status' => $link->status], 201);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['accept' => ['required', 'boolean']]);
        $link = $this->pairing->confirm(
            $request->user(),
            GuardianLink::query()->findOrFail($id),
            $validated['accept'],
        );

        return response()->json(['status' => $link->status]);
    }

    /** Parent-initiated request by student email (B4 alternative flow). */
    public function requestByEmail(Request $request): JsonResponse
    {
        $validated = $request->validate(['student_email' => ['required', 'email']]);
        $requesterId = (int) $request->user()->id;
        [$student, $isSecond] = $this->scope->asSystem(
            'B4 parent-initiated flow: pre-link student lookup by exact email — the target is by definition outside the guardian\'s scope until the link exists. Response is identical whether or not the account exists; only a pending link (student-confirmable) results. Also counts the student\'s existing guardians (OD-24 second-guardian check) which are likewise outside scope.',
            function () use ($validated, $requesterId): array {
                $student = User::query()->where('email', $validated['student_email'])->where('role', 'student')->first();
                $isSecond = $student !== null && $this->linkage->isUninitiatedSecondGuardian((int) $student->id, $requesterId);

                return [$student, $isSecond];
            },
        );
        // Identical 202 either way — never confirm account existence, AND never leak
        // (via a different response) that the student already has a guardian (OD-24):
        // a non-vouch second-guardian self-add silently produces no link.
        if ($student === null || $isSecond) {
            return response()->json(['status' => 'processed'], 202);
        }
        $link = GuardianLink::query()->create([
            'id' => (string) Str::uuid7(),
            'student_id' => $student->id,
            'guardian_id' => $request->user()->id,
            'status' => 'pending_confirmation',
            'origin' => 'parent_initiated',
        ]);
        $this->audit->record(
            'guardian_link', $link->id, 'guardian_link.requested',
            toState: 'pending_confirmation',
            payloadAfter: ['origin' => 'parent_initiated'],
            actor: $request->user(),
        );

        return response()->json(['status' => 'processed'], 202);
    }

    /** School-mediated vouched link (B4): auto-activates. Bulk rosters arrive with H. */
    public function schoolVouch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer'],
            'guardian_email' => ['required', 'email'],
        ]);

        // Scoped visibility check: under the admin's RLS context this row only
        // exists if the student is in THEIR school. Absent → audited 404 that
        // does not leak whether the student exists elsewhere (FR006).
        $inSchool = \Illuminate\Support\Facades\DB::table('school_links')
            ->where('student_id', $validated['student_id'])
            ->where('status', 'active')->exists();
        if (! $inSchool) {
            $this->audit->record(
                'user', (string) $validated['student_id'], 'scope.denied',
                reason: 'school-mediated vouch attempted for a student outside the acting school (FR006)',
                actor: $request->user(),
            );
            abort(404);
        }

        // OD-30: the vouch is the school admin's single audited act. The active
        // write now runs INSIDE the elevation (system-context) so it passes the
        // S04D hardening (only system writes status='active') — the roll authority
        // is the $inSchool check above, no longer an RLS backstop. Writes the link
        // + its activation audit + OD-24 visibility to every existing guardian.
        $actor = $request->user();
        $link = $this->scope->asSystem(
            'School vouch (OD-30): the vouching school admin\'s single audited act creates an ACTIVE guardian-student link for a student verified ON THEIR ROLL (the in-school check precedes this). The guardian and the activation are outside the admin\'s derived scope; the active write is system-context by construction. Writes the link, its to_state=active audit, and OD-24 visibility records to every existing guardian (never silent).',
            function () use ($validated, $actor): GuardianLink {
                $guardian = User::query()->where('email', $validated['guardian_email'])
                    ->where('role', 'guardian')->firstOrFail();
                $link = GuardianLink::query()->create([
                    'id' => (string) Str::uuid7(),
                    'student_id' => $validated['student_id'],
                    'guardian_id' => $guardian->id,
                    'status' => 'active', // the school vouched (OD-30)
                    'verified_at' => now(),
                    'origin' => 'school_mediated',
                ]);
                $this->audit->record(
                    'guardian_link', $link->id, 'guardian_link.created',
                    toState: 'active',
                    payloadAfter: ['origin' => 'school_mediated', 'vouched_by' => $actor->id],
                    actor: $actor,
                );
                // OD-24 — never silent, vouched additions included.
                $this->linkage->recordGuardianAdditionVisibility((int) $validated['student_id'], (int) $guardian->id, $link->id, 'school_mediated');

                return $link;
            },
        );

        // S-FIX-consent-reissue (D1): the vouch is a direct activation — re-issue consent to the
        // newly-active guardian for the student's pre-confirm enrolments (the OD-24 second-guardian case).
        GuardianLinkActivated::dispatch((int) $link->student_id, (int) $link->guardian_id, $link->id, (string) $link->origin, (int) $actor->id);

        return response()->json(['link_id' => $link->id], 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $link = $this->revocation->revoke(
            $request->user(),
            GuardianLink::query()->findOrFail($id),
            $validated['reason'] ?? null,
        );

        return response()->json(['status' => $link->status]);
    }
}
