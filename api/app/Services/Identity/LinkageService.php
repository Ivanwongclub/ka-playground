<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * S04C STEP 3 — orphan pairs, held links, and the ONE activation path (FLAG #2).
 *
 * The whole design serves one rule: "approving a PERSON is not approving a
 * RELATIONSHIP." So a claimed counterpart never becomes an active link on its
 * own. It travels one of two roads, and BOTH terminate at the SAME gate —
 * approveLink() — which is the ONLY place a guardian_link reaches 'active', and
 * the ONLY place the to_state='active' audit (FLAG #2, which S06's requires_all
 * consent hardening reads) is written:
 *
 *   road A (counterpart already a VERIFIED account) — a pending_approval link is
 *          created at the claimant's approval, straight into the queue.
 *   road B (counterpart not registered) — a held_link is recorded; it MATERIALISES
 *          into a pending_approval link ONLY when the counterpart's own address is
 *          later VERIFIED (materialiseFor), carrying the 'form_claimed' marker so
 *          the reviewer never mistakes a form claim for a confirmed relationship.
 *
 * Neither road activates anything. approveLink() does — once, structurally.
 */
class LinkageService
{
    /** Held-link time-to-live: default 90 days (configurable per OD; a platform default). */
    private const HELD_LINK_TTL_DAYS = 90;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    /**
     * At the claimant's approval (called INSIDE RegistrationApprovalService::approve's
     * elevation — already system context). If the request named a counterpart:
     * existing-verified → a pending link; otherwise → a held link.
     */
    public function linkCounterpartAtApproval(User $claimant, object $request, User $approver): void
    {
        $email = trim((string) ($request->counterpart_email ?? ''));
        if ($email === '') {
            return;
        }
        $counterpartRole = $this->opposite($claimant->role);
        $counterpart = User::query()
            ->where('email', $email)->where('role', $counterpartRole)
            ->whereNotNull('email_verified_at')->first();

        if ($counterpart !== null) {
            // road A — the counterpart is a real verified account: a pending link now
            $this->createPendingLink($claimant, $counterpart, $claimant->role, 'form_claimed', $approver);

            return;
        }

        // road B — no verified account yet: hold the claim (terminal: materialise or expire)
        $id = (string) Str::uuid7();
        DB::table('held_links')->insert([
            'id' => $id,
            'claimant_id' => $claimant->id,
            'claimant_role' => $claimant->role,
            'counterpart_email' => $email,
            'counterpart_name' => $request->counterpart_name ?? null,
            'school_id' => $request->school_id ?? null,
            'status' => 'held',
            'origin' => 'form_claimed',
            'expires_at' => now()->addDays(self::HELD_LINK_TTL_DAYS),
            'registration_request_id' => $request->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('held_link', $id, 'held_link.held',
            toState: 'held', payloadAfter: ['counterpart_email' => $email, 'claimant_role' => $claimant->role], actor: $approver);
    }

    /**
     * At the counterpart's ACTIVATION (verification). Any held link claiming THIS
     * now-verified address, in the matching direction, materialises into a
     * pending_approval link (form_claimed). This is the ONLY path a held link
     * becomes a pending link — and only because the address is now verified, which
     * is exactly what no_unverified_materialisation proves. Elevated: activation
     * runs in the guest context.
     */
    public function materialiseFor(User $verified): void
    {
        $this->scope->asSystem(
            'Held-link materialisation (OD-23, Leo 1a): a relationship CLAIMED against an address on a registration form materialises into a pending_approval link only once that address proves control of itself (verification). System-context: the claimant is outside the just-verified account\'s scope, and held_links are system-write by construction. Creates pending links only — never active.',
            function () use ($verified): void {
                $expectedClaimantRole = $this->opposite($verified->role);
                $held = DB::table('held_links')
                    ->where('counterpart_email', $verified->email)
                    ->where('claimant_role', $expectedClaimantRole)
                    ->where('status', 'held')->get();

                foreach ($held as $h) {
                    $claimant = User::find($h->claimant_id);
                    if ($claimant === null) {
                        continue;
                    }
                    $linkId = $this->createPendingLink($claimant, $verified, $h->claimant_role, 'form_claimed', null);
                    DB::table('held_links')->where('id', $h->id)
                        ->update(['status' => 'materialised', 'materialised_link_id' => $linkId, 'updated_at' => now()]);
                    $this->audit->record('held_link', $h->id, 'held_link.materialised',
                        fromState: 'held', toState: 'materialised',
                        payloadAfter: ['guardian_link_id' => $linkId, 'counterpart_verified' => true]);
                }
            },
        );
    }

    /**
     * THE ONE ACTIVATION PATH (FLAG #2). Every road — road A, road B, any future
     * onboarding path — ends here. Authorised by RLS visibility (a pending link
     * the reviewer cannot SEE is not theirs; ops sees orphan pairs, a school admin
     * sees links for its affiliated students). The reviewer role is guarded at the
     * controller so a guardian cannot self-activate. The write elevates so it works
     * for a not-yet-affiliated student, and it CANNOT proceed without writing the
     * to_state='active' audit — that is the point.
     */
    public function approveLink(string $linkId, User $approver): void
    {
        $link = DB::table('guardian_links')->where('id', $linkId)->first();
        if ($link === null) {
            abort(404); // invisible to this reviewer ⇒ not theirs to decide
        }
        if ($link->status === 'active') {
            return; // idempotent
        }
        if ($link->status !== 'pending_approval') {
            throw ValidationException::withMessages(['link' => ['This link is not awaiting approval']]);
        }

        $this->scope->asSystem(
            'Guardian-link approval (OD-23/2.30 · FLAG #2): the admin\'s decision — separate from approving either person — activates the relationship. CAS pending_approval→active; writes the to_state=\'active\' audit that S06 requires_all consent hardening depends on. Elevated because a not-yet-affiliated student is outside the reviewer\'s derived scope.',
            function () use ($link, $approver): void {
                // CAS: exactly one approval flips pending_approval → active
                $claimed = DB::update(
                    "UPDATE guardian_links SET status = 'active', verified_at = now(), updated_at = now()
                     WHERE id = ? AND status = 'pending_approval'",
                    [$link->id],
                );
                if ($claimed !== 1) {
                    $fresh = DB::table('guardian_links')->where('id', $link->id)->value('status');
                    if ($fresh === 'active') {
                        return; // a concurrent approval won — idempotent
                    }
                    throw ValidationException::withMessages(['link' => ['This link is not awaiting approval']]);
                }
                // FLAG #2 — the load-bearing audit. Never skipped on any path.
                $this->audit->record('guardian_link', $link->id, 'guardian_link.activated',
                    fromState: 'pending_approval', toState: 'active',
                    payloadAfter: ['student_id' => $link->student_id, 'guardian_id' => $link->guardian_id, 'origin' => $link->origin],
                    actor: $approver);
                // OD-24 — never silent: every existing guardian sees this addition.
                $this->recordGuardianAdditionVisibility((int) $link->student_id, (int) $link->guardian_id, $link->id, (string) $link->origin);
            },
        );
    }

    /** Reject a pending link (a form-claim the reviewer does not confirm). Terminal. */
    public function rejectLink(string $linkId, User $approver, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required to reject a link']]);
        }
        $link = DB::table('guardian_links')->where('id', $linkId)->first();
        if ($link === null) {
            abort(404);
        }
        if ($link->status !== 'pending_approval') {
            throw ValidationException::withMessages(['link' => ['This link is not awaiting approval']]);
        }
        // 2.30: the admin's refusal of a pending relationship is 'rejected'
        // (distinct from a student CANCELLING a ceremony at pending_confirmation).
        $updated = DB::table('guardian_links')->where('id', $link->id)->where('status', 'pending_approval')
            ->update(['status' => 'rejected', 'updated_at' => now()]);
        if ($updated !== 1) {
            throw ValidationException::withMessages(['link' => ['This link is not awaiting approval']]);
        }
        $this->audit->record('guardian_link', $link->id, 'guardian_link.rejected',
            fromState: 'pending_approval', toState: 'rejected', reason: $reason, actor: $approver);
    }

    /** The terminal exit for a held link that never materialised. Console (system). */
    public function expireHeldLinks(): int
    {
        $due = DB::table('held_links')->where('status', 'held')->where('expires_at', '<', now())->get();
        foreach ($due as $h) {
            DB::table('held_links')->where('id', $h->id)->where('status', 'held')
                ->update(['status' => 'expired', 'updated_at' => now()]);
            $this->audit->record('held_link', $h->id, 'held_link.expired',
                fromState: 'held', toState: 'expired', payloadAfter: ['counterpart_email' => $h->counterpart_email]);
        }

        return $due->count();
    }

    /** Create a pending_approval guardian_link in the correct direction. @return string link id */
    private function createPendingLink(User $claimant, User $counterpart, string $claimantRole, string $origin, ?User $actor): string
    {
        [$studentId, $guardianId] = $claimantRole === 'guardian'
            ? [$counterpart->id, $claimant->id]   // guardian claimant → counterpart is the student
            : [$claimant->id, $counterpart->id];  // student claimant → counterpart is the guardian

        $id = (string) Str::uuid7();
        DB::table('guardian_links')->insert([
            'id' => $id,
            'student_id' => $studentId,
            'guardian_id' => $guardianId,
            'status' => 'pending_approval',
            'origin' => $origin,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('guardian_link', $id, 'guardian_link.claimed',
            toState: 'pending_approval',
            payloadAfter: ['student_id' => $studentId, 'guardian_id' => $guardianId, 'origin' => $origin], actor: $actor);

        return $id;
    }

    private function opposite(string $role): string
    {
        return $role === 'guardian' ? 'student' : 'guardian';
    }

    /**
     * OD-24 (never silent) — when a guardian is ADDED, every OTHER active guardian
     * of the student gets a visibility record (including vouched additions, OD-30).
     * A first/sole guardian produces none (nobody to notify). System-context only.
     */
    public function recordGuardianAdditionVisibility(int $studentId, int $newGuardianId, string $newLinkId, string $origin): void
    {
        $existing = DB::table('guardian_links')
            ->where('student_id', $studentId)->where('status', 'active')
            ->where('guardian_id', '!=', $newGuardianId)
            ->pluck('guardian_id');

        foreach ($existing as $guardianId) {
            DB::table('link_visibility_events')->insert([
                'id' => (string) Str::uuid7(),
                'student_id' => $studentId,
                'new_guardian_id' => $newGuardianId,
                'new_link_id' => $newLinkId,
                'addressed_guardian_id' => $guardianId,
                'origin' => $origin,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * OD-24 refusal (Leo 2026-07-31): a non-vouch SECOND-guardian SELF-add is
     * refused — a student who already has an active guardian may only gain a
     * further one through the existing guardian's action (deferred) or a school
     * VOUCH. True when the student already has an active guardian and $requesterId
     * is not one of them. System-context (sees all guardians).
     */
    public function isUninitiatedSecondGuardian(int $studentId, int $requesterId): bool
    {
        $guardians = DB::table('guardian_links')
            ->where('student_id', $studentId)->where('status', 'active')
            ->pluck('guardian_id')->map(fn ($g) => (int) $g);

        return $guardians->isNotEmpty() && ! $guardians->contains($requesterId);
    }
}
