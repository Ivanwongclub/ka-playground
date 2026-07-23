<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuthEventType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Auth lifecycle per 2.11 — every event audited (BI-8):
 * login · logout · failed_login · lockout · reset_requested · reset_completed.
 * Lockout: 5 consecutive failures → 15 min, admin-unlockable.
 * Sessions: 12 h idle, 30 d remember-me — enforced token-side in AppServiceProvider.
 */
class AuthService
{
    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    public const IDLE_HOURS = 12;

    public const REMEMBER_DAYS = 30;

    public function __construct(private readonly AuditService $audit) {}

    /** @return array{token: string, user: User} */
    public function login(string $email, string $password, bool $remember, ?string $ip): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // No user row: audit with null actor, refuse identically to a bad password
            $this->audit->record('user', $email, AuthEventType::FailedLogin->value, reason: 'unknown email');
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records']]);
        }

        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            $this->audit->record(
                'user', (string) $user->id, AuthEventType::FailedLogin->value,
                reason: 'account locked until '.$user->locked_until->toIso8601String(), actor: $user,
            );
            throw new HttpException(423, 'Account locked. Try again later or contact an administrator.');
        }

        if (! Hash::check($password, $user->password)) {
            $failures = $user->failed_login_attempts + 1;
            $user->forceFill(['failed_login_attempts' => $failures])->save();
            $this->audit->record(
                'user', (string) $user->id, AuthEventType::FailedLogin->value,
                reason: "invalid password (failure {$failures} of ".self::MAX_FAILURES.')', actor: $user,
            );

            if ($failures >= self::MAX_FAILURES) {
                $user->forceFill(['locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->save();
                $this->audit->record(
                    'user', (string) $user->id, AuthEventType::Lockout->value,
                    toState: 'locked',
                    reason: self::MAX_FAILURES.' consecutive failed logins — locked '.self::LOCK_MINUTES.' minutes',
                    actor: $user,
                );
            }
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records']]);
        }

        if (! $user->hasVerifiedEmail()) {
            // 2.11: email verification is mandatory before first login completes
            $this->audit->record(
                'user', (string) $user->id, AuthEventType::FailedLogin->value,
                reason: 'email not verified — first login refused (2.11)', actor: $user,
            );
            throw new HttpException(403, 'Email not verified. Verify your email before signing in.');
        }

        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
        $token = $user->createToken($remember ? 'remember' : 'session');
        $this->audit->record(
            'user', (string) $user->id, AuthEventType::Login->value,
            payloadAfter: ['remember' => $remember, 'ip' => $ip], actor: $user,
        );

        return ['token' => $token->plainTextToken, 'user' => $user];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
        $this->audit->record('user', (string) $user->id, AuthEventType::Logout->value, actor: $user);
    }

    public function unlock(User $actor, User $target): void
    {
        $target->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
        $this->audit->record(
            'user', (string) $target->id, 'lockout_cleared',
            fromState: 'locked', toState: 'unlocked',
            reason: 'administrator unlock', actor: $actor,
        );
    }
}
