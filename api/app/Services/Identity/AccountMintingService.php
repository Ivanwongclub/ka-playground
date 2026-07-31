<?php

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * S04D STEP 1 (D-i-2) — the ONE home for account creation, so the two credential
 * models can't diverge or get copied a third time (bulk).
 *
 * Both methods create the account under the CALLER's system elevation (the new
 * person is outside any actor's scope until they exist; INSERT..RETURNING checks
 * the row against SELECT policies). Neither elevates itself — the caller
 * (RegistrationApprovalService::approve, InvitationService::accept, bulk) owns the
 * allowlisted asSystem boundary.
 *
 * Two deliberately-separate credential models:
 *  - mintPendingActivation — self-registration approval + bulk (model B, OD-29):
 *    born UNVERIFIED, an activation token, and an UNUSABLE placeholder password.
 *    The credential-only-at-activation guarantee (D-iii) lives HERE and nowhere
 *    else — the account cannot log in until [[AccountActivationService]] sets the
 *    real password.
 *  - mintWithPassword — invited staff (S01 invitation, OD-60): the invitee sets
 *    their password at accept, then verifies their email. A distinct, older flow.
 */
class AccountMintingService
{
    /**
     * Model B: an account that CANNOT log in until activation sets its password.
     *
     * @return array{user: User, token: string} the plaintext token leaves once, here
     */
    public function mintPendingActivation(string $name, string $email, string $role): array
    {
        $token = Str::random(64); // 256-bit; only the sha256 hash is stored
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(40)), // unusable placeholder — the real password is set at activation
            'role' => $role,
        ]);
        // born UNVERIFIED (email_verified_at stays null); carry the activation token
        $user->forceFill([
            'activation_token_hash' => hash('sha256', $token),
            'activation_expires_at' => now()->addDays(7),
        ])->save();

        return ['user' => $user, 'token' => $token];
    }

    /** Invited staff (S01): the invitee's chosen password is set now; email verification follows. */
    public function mintWithPassword(string $name, string $email, string $role, string $password): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
        ]);
    }
}
