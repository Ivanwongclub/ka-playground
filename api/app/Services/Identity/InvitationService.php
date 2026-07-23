<?php

namespace App\Services\Identity;

use App\Models\Invitation;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuthEventType;
use Illuminate\Support\Facades\Hash;
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
    public const INVITABLE_ROLES = ['guardian', 'teacher', 'school_admin', 'academy_admin'];

    public function __construct(private readonly AuditService $audit) {}

    /** @return array{invitation: Invitation, plain_token: string} */
    public function issue(User $actor, string $email, string $role): array
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
            'token_hash' => hash('sha256', $plainToken),
            'issued_by' => $actor->id,
            'expires_at' => now()->addDays(14),
        ]);

        $this->audit->record(
            entityType: 'invitation',
            entityId: $invitation->id,
            action: 'invitation.issued',
            toState: 'issued',
            payloadAfter: ['email' => $email, 'role' => $role],
            actor: $actor,
        );

        // Delivery: the invitation email rides the notification engine scaffold;
        // locally MAIL_MAILER=log. The plain token appears exactly once, here.
        return ['invitation' => $invitation, 'plain_token' => $plainToken];
    }

    public function accept(string $plainToken, string $password): User
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

        $user = User::query()->create([
            'name' => Str::before($invitation->email, '@'), // provisional; profile completion refines
            'email' => $invitation->email,
            'password' => Hash::make($password),
            'role' => $invitation->role,
        ]);
        $invitation->forceFill(['accepted_at' => now(), 'user_id' => $user->id])->save();

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
