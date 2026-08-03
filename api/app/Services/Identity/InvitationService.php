<?php

namespace App\Services\Identity;

use App\Models\Invitation;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Audit\AuthEventType;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitation-only onboarding (2.11 / L4 / SR001). The academy issues; the
 * recipient sets a password through a single-use tokenised link (14 days);
 * email verification is mandatory before first login completes.
 *
 * Students are never invited (guardian-led creation, L4); Members are never
 * invited until S06 delivers their surfaces (OD-22).
 */
class InvitationService
{
    // S-UX3-8 (OD-22): 'member' becomes invitable now that the Member surfaces are delivered — the
    // school-less accept path (doAccept) mints a member with exactly the member role's caps; the
    // teacher-only school-vouch branch is cleanly skipped for a member (school_id === null).
    public const INVITABLE_ROLES = ['guardian', 'teacher', 'school_admin', 'academy_admin', 'member'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly AccountMintingService $minting,
    ) {}

    /** @return array{invitation: Invitation, plain_token: string} */
    public function issue(User $actor, string $email, string $role, ?int $schoolId = null): array
    {
        if (! in_array($role, self::INVITABLE_ROLES, true)) {
            $reason = $role === 'member'
                ? 'Member invitations are not issued until S06 delivers the Member surfaces (OD-22)'
                : "role '{$role}' is not invitable (students are created by their guardian — L4)";
            throw ValidationException::withMessages(['role' => [$reason]]);
        }

        $plainToken = Str::random(64);
        $invitation = Invitation::query()->create([
            'id' => (string) Str::uuid7(),
            'email' => $email,
            'role' => $role,
            'school_id' => $schoolId, // teacher invitations carry the vouching school (B1)
            'token_hash' => hash('sha256', $plainToken),
            'issued_by' => $actor->id,
            'expires_at' => now()->addDays(14),
        ]);

        $this->audit->record(
            entityType: 'invitation',
            entityId: $invitation->id,
            action: 'invitation.issued',
            toState: 'issued',
            payloadAfter: ['email' => $email, 'role' => $role, 'school_id' => $schoolId],
            actor: $actor,
        );

        // Delivery: the invitation email rides the notification engine scaffold;
        // locally MAIL_MAILER=log. The plain token appears exactly once, here.
        return ['invitation' => $invitation, 'plain_token' => $plainToken];
    }

    public function accept(string $plainToken, string $password): User
    {
        return $this->scope->asSystem(
            'Invitation acceptance is a pre-authentication bootstrap act by design (2.11): creates the invited account and activates any school-vouched teacher affiliation — single-use token-gated writes no scoped context could perform.',
            fn (): User => $this->doAccept($plainToken, $password),
        );
    }

    private function doAccept(string $plainToken, string $password): User
    {
        $invitation = Invitation::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if ($invitation === null) {
            throw ValidationException::withMessages(['token' => ['Invalid invitation token']]);
        }
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages(['token' => ['This invitation has already been used']]);
        }
        if ($invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['token' => ['This invitation has expired']]);
        }

        // Invited staff: password set now (D-i-2 mintWithPassword), then email verification.
        $user = $this->minting->mintWithPassword(
            Str::before($invitation->email, '@'), // provisional; profile completion refines
            $invitation->email,
            $invitation->role,
            $password,
        );
        $invitation->forceFill(['accepted_at' => now(), 'user_id' => $user->id])->save();

        if ($invitation->school_id !== null && $invitation->role === 'teacher') {
            // The issuing school admin vouched — the teacher↔school affiliation
            // activates now (already under the accept() elevation). FLAG #2 /
            // no_active_without_approval: the activation MUST audit to_state='active'
            // (this path was previously the ONE un-audited active-link write).
            $teacherLinkId = (string) Str::uuid7();
            \Illuminate\Support\Facades\DB::table('teacher_links')->insert([
                'id' => $teacherLinkId,
                'teacher_id' => $user->id,
                'school_id' => $invitation->school_id,
                'status' => 'active',
                'origin' => 'invitation',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record(
                entityType: 'teacher_link',
                entityId: $teacherLinkId,
                action: 'teacher_link.created',
                toState: 'active',
                payloadAfter: ['teacher_id' => $user->id, 'school_id' => $invitation->school_id, 'origin' => 'invitation'],
                actor: $user,
            );
        }

        $this->audit->record(
            entityType: 'invitation',
            entityId: $invitation->id,
            action: AuthEventType::InvitationAccepted->value,
            fromState: 'issued',
            toState: 'accepted',
            payloadAfter: ['user_id' => $user->id, 'role' => $user->role],
            actor: $user,
        );

        $user->sendEmailVerificationNotification();

        return $user;
    }
}
