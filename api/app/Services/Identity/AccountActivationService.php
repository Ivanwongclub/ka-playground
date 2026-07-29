<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuthEventType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Account activation (S04C STEP 2, OD-29 model B). The single verify-and-set-
 * password act that turns an approved-but-unverified account into one that can
 * log in. One act establishes BOTH the credential and the verified address, so
 * there is never a window of a password-bearing-but-unverified account, and the
 * anonymous surface never had to handle a secret.
 *
 * Runs in the guest (empty) context, exactly like login and invitation accept —
 * these are the auth-bootstrap flows that precede any scope by construction. The
 * 256-bit token (matched by sha256 hash) IS the access control; it is single-use
 * (cleared on success) and expiring.
 */
class AccountActivationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly LinkageService $linkage,
    ) {}

    public function activate(string $token, string $password): User
    {
        $hash = hash('sha256', $token);
        // Identify the candidate for the audit; the conditional burn below — not
        // this read — is the authority on who wins.
        $userId = User::query()->where('activation_token_hash', $hash)->value('id');

        // THE CAS BURN: a single conditional UPDATE sets password + verifies +
        // clears the token in one statement. Exactly one concurrent activation
        // flips the token to NULL and wins; a racing second, a replay, or an
        // invalid/expired token all match ZERO rows and get the SAME constant
        // refusal. "Exactly one activation wins" is structural here, like the
        // paid-link claim, the seat claim and the receipt sequence — never a
        // read-modify-write that two holders could both pass.
        $claimed = DB::update(
            'UPDATE users SET password = ?, email_verified_at = now(),
                activation_token_hash = NULL, activation_expires_at = NULL, updated_at = now()
             WHERE activation_token_hash = ? AND activation_expires_at > now()',
            [Hash::make($password), $hash],
        );
        if ($claimed !== 1) {
            throw ValidationException::withMessages(['token' => ['This activation link is invalid or has expired']]);
        }

        $user = User::findOrFail($userId); // the just-activated row (verified, token burned)
        $this->audit->record('user', (string) $user->id, AuthEventType::EmailVerified->value,
            toState: 'verified', actor: $user);
        $this->audit->record('user', (string) $user->id, 'account.activated',
            toState: 'activated', payloadAfter: ['via' => 'registration_activation'], actor: $user);

        // Now that THIS address is verified, any held link that claimed it
        // materialises into a pending link (STEP 3, road B). Pending only — never
        // active; an admin still decides the relationship (approveLink).
        $this->linkage->materialiseFor($user);

        return $user;
    }
}
