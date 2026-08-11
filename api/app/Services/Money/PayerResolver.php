<?php

namespace App\Services\Money;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * S04F STEP 1 (OD-25 · D-18) — the ONE place a payment obligation's payer is
 * resolved from the programme's E6 `payer_party`. Both Team Formation obligation-creation
 * sites (TeamConfirmationService, TeamResolutionService) call this — if only one
 * did, a school order from the other path would silently keep `guardian` and
 * drop from the invoice branch.
 *
 * The mapping is TOTAL and EXPLICIT — it bridges the enum mismatch
 * (programme `parent` vs obligation/order `guardian`) and THROWS on anything it
 * cannot map, so an unmapped E6 value can never silently produce the wrong payer:
 *   parent  → guardian  (family-paid)
 *   student → student   (self-paid)
 *   school  → school    + payer_school_id = the student's SINGLE active roll
 *
 * A `school` programme whose student has zero or >1 active school rolls is a
 * LOUD audited failure (no guardian fallback) — the caller aborts.
 */
class PayerResolver
{
    /**
     * @return array{payer_party: string, payer_school_id: ?int}
     */
    public function resolve(int $programmeId, int $studentId): array
    {
        $e6 = DB::table('programmes')->where('id', $programmeId)->value('payer_party');

        return match ($e6) {
            'parent' => ['payer_party' => 'guardian', 'payer_school_id' => null],
            'student' => ['payer_party' => 'student', 'payer_school_id' => null],
            'school' => $this->schoolPayer($programmeId, $studentId),
            default => throw new UnresolvablePayerException(
                "programme {$programmeId}: unmapped E6 payer_party ".var_export($e6, true).' — refusing to guess a payer'
            ),
        };
    }

    /**
     * @return array{payer_party: string, payer_school_id: int}
     */
    private function schoolPayer(int $programmeId, int $studentId): array
    {
        // The student's SINGLE active roll — resolved LIVE (not a stale/revoked link).
        $rolls = DB::table('school_links')
            ->where('student_id', $studentId)->where('status', 'active')
            ->pluck('school_id');

        if ($rolls->count() !== 1) {
            // Loud, and it survives the caller's transaction rollback (logs are not transactional).
            Log::critical('S04F: unresolvable school payer — refusing silent guardian fallback (D-18)', [
                'programme_id' => $programmeId,
                'student_id' => $studentId,
                'active_rolls' => $rolls->count(),
            ]);
            throw new UnresolvablePayerException(
                "student {$studentId} on school-paid programme {$programmeId} has {$rolls->count()} active school rolls — "
                .'cannot resolve payer_school_id; refusing to fall back to guardian (the order must reach the invoice branch)'
            );
        }

        return ['payer_party' => 'school', 'payer_school_id' => (int) $rolls->first()];
    }
}
