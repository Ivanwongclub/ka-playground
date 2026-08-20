<?php

namespace App\Services\Programmes;

use App\Models\Programme;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Withdrawal policy (2.1/E7). The SCHEMA is the control (Leo): a policy that
 * cannot be represented cannot be configured. Bands must be strictly ordered,
 * non-overlapping, inside the full-refund/no-refund window, 0–100%.
 * Seeds are OD-2 PROVISIONAL data and will change; the validation will not.
 */
class WithdrawalPolicyService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param list<array{until_date: string, refund_pct: int}> $bands */
    public function save(Programme $programme, ?string $fullRefundBefore, ?string $noRefundAfter, bool $requiresApproval, array $bands, User $actor): void
    {
        $full = $fullRefundBefore !== null ? Carbon::parse($fullRefundBefore) : null;
        $none = $noRefundAfter !== null ? Carbon::parse($noRefundAfter) : null;
        if ($full !== null && $none !== null && $full->greaterThan($none)) {
            throw ValidationException::withMessages(['no_refund_after' => ['no_refund_after must not precede full_refund_before']]);
        }

        $previous = null;
        foreach ($bands as $i => $band) {
            $until = Carbon::parse($band['until_date']);
            $pct = (int) $band['refund_pct'];
            if ($pct < 0 || $pct > 100) {
                throw ValidationException::withMessages(['bands' => ["band {$i}: refund_pct must be 0–100 (got {$pct})"]]);
            }
            if ($previous !== null && $until->lessThanOrEqualTo($previous)) {
                throw ValidationException::withMessages(['bands' => ["band {$i}: until_dates must be strictly increasing — unordered or overlapping bands are refused"]]);
            }
            if ($full !== null && $until->lessThanOrEqualTo($full)) {
                throw ValidationException::withMessages(['bands' => ["band {$i}: lies inside the full-refund window (before {$full->toDateString()}) — it could never apply"]]);
            }
            if ($none !== null && $until->greaterThan($none)) {
                throw ValidationException::withMessages(['bands' => ["band {$i}: lies beyond no_refund_after ({$none->toDateString()}) — it could never apply"]]);
            }
            $previous = $until;
        }

        DB::transaction(function () use ($programme, $full, $none, $requiresApproval, $bands, $actor): void {
            DB::table('withdrawal_policies')->updateOrInsert(
                ['programme_id' => $programme->id],
                [
                    'id' => DB::table('withdrawal_policies')->where('programme_id', $programme->id)->value('id') ?? (string) Str::uuid7(),
                    'full_refund_before' => $full, 'no_refund_after' => $none,
                    'requires_approval' => $requiresApproval, 'seeded_provisional' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            );
            DB::table('withdrawal_bands')->where('programme_id', $programme->id)->delete();
            foreach ($bands as $i => $band) {
                DB::table('withdrawal_bands')->insert([
                    'id' => (string) Str::uuid7(), 'programme_id' => $programme->id,
                    'position' => $i, 'until_date' => Carbon::parse($band['until_date']),
                    'refund_pct' => (int) $band['refund_pct'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('withdrawal_policy', (string) $programme->id, 'withdrawal_policy.saved',
                payloadAfter: ['bands' => count($bands), 'requires_approval' => $requiresApproval],
                actor: $actor);
        });
    }

    /**
     * OD-2 provisional seed: full refund before start · no bands · no refund after start · approval required.
     *
     * FIX-REFUND-SEED — a provisional policy is NEVER seeded with a NULL window. Until now this read
     * `$programme->starts_at`, a column nothing wrote, so every wizard-published programme got
     * full_refund_before = no_refund_after = NULL; refundPctAt then returns 0 for every date and OD-2's
     * "full refund before start" silently becomes "no refund at all" — a policy that presents as configured
     * (seeded_provisional = true) while being inert, failing in the direction that costs the family.
     * WizardService::syncBasicsDates now populates the column from basics.starts_on, and this refuses if it
     * is still absent. Defence in depth: pre-flight names the missing date first (a readable finding), this
     * throw is the backstop for any future caller that skips pre-flight. It runs inside publish()'s
     * transaction, so the refusal rolls back the status flip, the capacity seed and the version snapshot
     * together — the programme stays draft rather than going live with a broken refund policy.
     */
    public function seedProvisional(Programme $programme, User $actor): void
    {
        if (DB::table('withdrawal_policies')->where('programme_id', $programme->id)->exists()) {
            return;
        }
        if ($programme->starts_at === null) {
            throw ValidationException::withMessages(['basics' => [
                'Cannot seed the provisional withdrawal policy: the programme has no start date (basics.starts_on). A provisional policy with no refund window would refund nothing, ever (OD-2).',
            ]]);
        }
        DB::table('withdrawal_policies')->insert([
            'id' => (string) Str::uuid7(), 'programme_id' => $programme->id,
            'full_refund_before' => $programme->starts_at, 'no_refund_after' => $programme->starts_at,
            'requires_approval' => true, 'seeded_provisional' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('withdrawal_policy', (string) $programme->id, 'withdrawal_policy.seeded',
            reason: 'OD-2 PROVISIONAL defaults — client confirmation pending; adjustable as data',
            actor: $actor);
    }

    /** E7 computation against the schema — fixture-tested; S04B consumes this. */
    public function refundPctAt(int $programmeId, Carbon $at): int
    {
        $policy = DB::table('withdrawal_policies')->where('programme_id', $programmeId)->first();
        if ($policy === null) {
            return 0;
        }
        if ($policy->full_refund_before !== null && $at->lessThan(Carbon::parse($policy->full_refund_before))) {
            return 100;
        }
        $band = DB::table('withdrawal_bands')->where('programme_id', $programmeId)
            ->where('until_date', '>', $at)->orderBy('until_date')->first();
        if ($band !== null) {
            return (int) $band->refund_pct;
        }

        return 0; // past the last band / past no_refund_after
    }
}
