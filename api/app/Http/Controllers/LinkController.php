<?php

namespace App\Http\Controllers;

use App\Models\GuardianLink;
use App\Models\User;
use App\Services\Identity\LinkRevocationService;
use App\Services\Identity\PairingService;
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
        $student = User::query()->where('email', $validated['student_email'])
            ->where('role', 'student')->first();
        if ($student === null) {
            // Identical response either way — never confirm account existence
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

        $guardian = User::query()->where('email', $validated['guardian_email'])
            ->where('role', 'guardian')->firstOrFail();
        try {
            // Savepoint: if the RLS backstop refuses, the connection stays
            // healthy and the refusal becomes an audited 403, never a 500
            $link = \Illuminate\Support\Facades\DB::transaction(fn () => GuardianLink::query()->create([
                'id' => (string) Str::uuid7(),
                'student_id' => $validated['student_id'],
                'guardian_id' => $guardian->id,
                'status' => 'active', // the school vouched (B4)
                'verified_at' => now(),
                'origin' => 'school_mediated',
            ]));
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '42501') { // row-level security violation
                $this->audit->record(
                    'user', (string) $validated['student_id'], 'scope.denied',
                    reason: 'RLS refused a cross-scope guardian-link write (FR006 backstop)',
                    actor: $request->user(),
                );
                abort(403);
            }
            throw $e;
        }
        $this->audit->record(
            'guardian_link', $link->id, 'guardian_link.created',
            toState: 'active',
            payloadAfter: ['origin' => 'school_mediated', 'vouched_by' => $request->user()->id],
            actor: $request->user(),
        );

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
