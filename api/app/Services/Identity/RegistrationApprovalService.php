<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registration review (S04C STEP 2, OD-23/OD-27/OD-29). The routed reviewer's
 * decision is where an account first comes into being — approval CREATES it.
 *
 * Two deliberate properties:
 *  - The account is born UNVERIFIED (OD-29, model B): approval mints a single-
 *    use activation token but sets no usable password; the applicant sets their
 *    password AND verifies their address in one act (AccountActivationService).
 *    No secret is ever collected on the anonymous surface.
 *  - Authorisation is RLS visibility: a request the reviewer cannot SELECT is
 *    not theirs to decide (school admins see only their routed requests; academy
 *    ops sees direct + all). Creating the account elevates (the new person is
 *    outside the reviewer's scope until the account exists) — the one sanctioned
 *    escalation, allowlisted and audited to the reviewer.
 */
class RegistrationApprovalService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly LinkageService $linkage,
        private readonly AccountMintingService $minting,
    ) {}

    public function approve(string $requestId, User $approver): User
    {
        // Authorise by RLS visibility — invisible ⇒ not this reviewer's to decide.
        $req = DB::table('registration_requests')->where('id', $requestId)->first();
        if ($req === null) {
            abort(404);
        }
        if ($req->status !== 'submitted') {
            throw ValidationException::withMessages(['request' => ['This registration has already been decided']]);
        }

        return $this->scope->asSystem(
            'Registration approval (OD-23/OD-29): the reviewer creates an account for a person who is BY DEFINITION outside the reviewer\'s scope until it exists (INSERT..RETURNING checks the new row against SELECT policies). Creates exactly one UNVERIFIED account + one single-use activation token, updates the request, all audited to the reviewer.',
            function () use ($req, $approver): User {
                $token = null; // set by mintPendingActivation on a fresh approval
                $fresh = false;

                $user = DB::transaction(function () use ($req, $approver, &$token, &$fresh): User {
                    // Idempotency + race guard (BI-4): lock the row, re-check under the lock.
                    $locked = DB::table('registration_requests')->where('id', $req->id)->lockForUpdate()->first();
                    if ($locked->status === 'approved' && $locked->created_account_id !== null) {
                        return User::findOrFail($locked->created_account_id); // the original, never a second account
                    }
                    if ($locked->status !== 'submitted') {
                        throw ValidationException::withMessages(['request' => ['This registration has already been decided']]);
                    }

                    // Model B account minting lives in ONE place (D-i-2): born
                    // UNVERIFIED, activation token, no usable password.
                    $minted = $this->minting->mintPendingActivation($locked->applicant_name, $locked->applicant_email, $locked->kind);
                    $user = $minted['user'];
                    $token = $minted['token'];

                    DB::table('registration_requests')->where('id', $locked->id)->update([
                        'status' => 'approved',
                        'reviewed_by' => $approver->id,
                        'reviewed_at' => now(),
                        'created_account_id' => $user->id,
                        'updated_at' => now(),
                    ]);

                    $this->audit->record('registration_request', $locked->id, 'registration.approved',
                        fromState: 'submitted', toState: 'approved',
                        payloadAfter: ['user_id' => $user->id, 'role' => $locked->kind, 'routing' => $locked->routing],
                        actor: $approver);
                    $this->audit->record('user', $user->id, 'user.created',
                        toState: 'registered',
                        payloadAfter: ['origin' => 'registration_approval', 'role' => $locked->kind, 'verified' => false],
                        actor: $approver);

                    // D-i (STEP 4): a school-routed student reaches ACTIVE at approval —
                    // the reviewing school affiliating them IS the active link (OD-28).
                    // A direct (academy-routed) student stays Registered until a link is
                    // approved. The richer school-link state machine is S04D; this is the
                    // minimal affiliation. Audited to_state='active' (BI-8).
                    if ($locked->kind === 'student' && $locked->routing === 'school' && $locked->school_id !== null) {
                        $linkId = (string) Str::uuid7();
                        DB::table('school_links')->insert([
                            'id' => $linkId, 'student_id' => $user->id, 'school_id' => $locked->school_id,
                            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $this->audit->record('school_link', $linkId, 'school_link.created',
                            toState: 'active',
                            payloadAfter: ['student_id' => $user->id, 'school_id' => $locked->school_id, 'origin' => 'registration_approval'],
                            actor: $approver);
                    }

                    // Orphan pairs (STEP 3): if the request named a counterpart, either a
                    // pending link (existing verified account) or a held link (not yet
                    // registered). Runs inside this elevation — creates pending/held only,
                    // never active. (approve is idempotent above, so this fires once.)
                    $this->linkage->linkCounterpartAtApproval($user, $locked, $approver);

                    $fresh = true;

                    return $user;
                });

                // Deliver the activation link only for a genuine first approval —
                // an idempotent replay must not re-issue a token. After commit.
                if ($fresh) {
                    $user->notify(new AccountActivationNotification($token));
                }

                return $user;
            },
        );
    }

    public function decline(string $requestId, User $approver, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required to decline a registration']]);
        }
        $req = DB::table('registration_requests')->where('id', $requestId)->first();
        if ($req === null) {
            abort(404);
        }
        if ($req->status !== 'submitted') {
            throw ValidationException::withMessages(['request' => ['This registration has already been decided']]);
        }

        // The reviewer holds UPDATE rights on their own routed request (RLS) — no elevation.
        $updated = DB::table('registration_requests')->where('id', $req->id)->where('status', 'submitted')->update([
            'status' => 'declined',
            'reviewed_by' => $approver->id,
            'reviewed_at' => now(),
            'decline_reason' => $reason,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw ValidationException::withMessages(['request' => ['This registration has already been decided']]);
        }

        $this->audit->record('registration_request', $req->id, 'registration.declined',
            fromState: 'submitted', toState: 'declined', reason: $reason, actor: $approver);
    }
}
