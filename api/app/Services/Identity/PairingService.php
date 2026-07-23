<?php

namespace App\Services\Identity;

use App\Models\GuardianLink;
use App\Models\PairingCode;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pairing codes (B4): 6-char case-sensitive, 7-day or first-use expiry, max 5
 * active per student, student confirmation guards against a mistyped code
 * linking a stranger. Hard invalidation after 10 GLOBAL failed attempts on the
 * code string (2.13) — global across accounts, distinct from the per-account
 * throttle. Misses that match no code are throttle-only (nothing to attribute).
 */
class PairingService
{
    public const MAX_ACTIVE = 5;

    public const INVALIDATION_THRESHOLD = 10;

    public function __construct(private readonly AuditService $audit) {}

    public function generate(User $student): PairingCode
    {
        $active = PairingCode::query()
            ->where('student_id', $student->id)
            ->whereNull('used_at')->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->count();
        if ($active >= self::MAX_ACTIVE) {
            throw ValidationException::withMessages(['code' => ['Maximum 5 active codes — revoke or let one expire first']]);
        }

        $code = PairingCode::query()->create([
            'id' => (string) Str::uuid7(),
            'student_id' => $student->id,
            'code' => Str::random(6), // mixed-case alphanumeric = case-sensitive
            'expires_at' => now()->addDays(7),
        ]);

        $this->audit->record(
            'pairing_code', $code->id, 'pairing_code.generated',
            toState: 'active', actor: $student,
        );

        return $code;
    }

    public function redeem(User $guardian, string $codeString): GuardianLink
    {
        $code = PairingCode::query()->where('code', $codeString)->first(); // binary-collated: case-sensitive

        if ($code === null) {
            $this->recordFailure($codeString);
            throw ValidationException::withMessages(['code' => ['Invalid pairing code']]);
        }
        if ($code->invalidated_at !== null) {
            $this->recordFailure($codeString);
            throw ValidationException::withMessages(['code' => ['This code has been invalidated']]);
        }
        if ($code->used_at !== null || $code->expires_at->isPast()) {
            $this->recordFailure($codeString);
            throw ValidationException::withMessages(['code' => ['This code has expired']]);
        }
        $alreadyLinked = GuardianLink::query()
            ->where('student_id', $code->student_id)
            ->where('guardian_id', $guardian->id)
            ->where('status', 'active')->exists();
        if ($alreadyLinked) {
            $this->recordFailure($codeString);
            throw ValidationException::withMessages(['code' => ['You are already linked to this student']]);
        }

        $code->forceFill(['used_at' => now()])->save(); // first successful use consumes (B4)
        $link = GuardianLink::query()->create([
            'id' => (string) Str::uuid7(),
            'student_id' => $code->student_id,
            'guardian_id' => $guardian->id,
            'status' => 'pending_confirmation', // student must confirm (B4 step 8)
            'origin' => 'pairing_code',
        ]);
        $this->audit->record(
            'guardian_link', $link->id, 'guardian_link.requested',
            toState: 'pending_confirmation',
            payloadAfter: ['student_id' => $code->student_id, 'guardian_id' => $guardian->id, 'origin' => 'pairing_code'],
            actor: $guardian,
        );

        return $link;
    }

    private function recordFailure(string $codeString): void
    {
        $attempts = DB::transaction(function () use ($codeString): int {
            DB::statement(
                'INSERT INTO pairing_code_failures (code, attempts, last_attempt_at) VALUES (?, 1, ?)
                 ON CONFLICT (code) DO UPDATE SET attempts = pairing_code_failures.attempts + 1, last_attempt_at = ?',
                [$codeString, now(), now()],
            );

            return (int) DB::table('pairing_code_failures')->where('code', $codeString)->value('attempts');
        });

        if ($attempts < self::INVALIDATION_THRESHOLD) {
            return;
        }
        $victim = PairingCode::query()
            ->where('code', $codeString)->whereNull('invalidated_at')->first();
        if ($victim !== null) {
            $victim->forceFill(['invalidated_at' => now()])->save();
            $this->audit->record(
                'pairing_code', $victim->id, 'pairing_code.invalidated',
                fromState: 'active', toState: 'invalidated',
                reason: self::INVALIDATION_THRESHOLD.' global failed attempts on this code (2.13)',
            );
        }
    }

    public function confirm(User $student, GuardianLink $link, bool $accept): GuardianLink
    {
        if ($link->student_id !== $student->id || $link->status !== 'pending_confirmation') {
            throw ValidationException::withMessages(['link' => ['No pending request to confirm']]);
        }

        $link->forceFill($accept
            ? ['status' => 'active', 'verified_at' => now()]
            : ['status' => 'cancelled'])->save();
        $this->audit->record(
            'guardian_link', $link->id,
            $accept ? 'guardian_link.confirmed' : 'guardian_link.rejected',
            fromState: 'pending_confirmation',
            toState: $link->status,
            actor: $student,
        );

        return $link;
    }
}
