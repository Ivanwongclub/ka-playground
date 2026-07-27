<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * BI-9 / OD-47 at the data level, both directions: no manual payment where the
 * recorder is also the confirmer; and no provider payment carrying a human
 * recorder or confirmer (provider payments self-confirm, out of BI-9 scope).
 */
class ManualPaymentSodAssertion implements Assertion
{
    public function key(): string
    {
        return 'payments.bi9_manual_sod';
    }

    public function proves(): string
    {
        return 'no manual payment has recorder = confirmer, and no provider payment carries a human recorder or confirmer (OD-47 both directions)';
    }

    public function cites(): string
    {
        return 'BI-9 · OD-47';
    }

    public function tags(): array
    {
        return ['S04B'];
    }

    public function check(): AssertionResult
    {
        $sameActor = (int) DB::table('payments')->where('origin', 'manual')
            ->whereColumn('recorded_by', 'confirmed_by')->count();
        $providerWithActor = (int) DB::table('payments')->where('origin', 'provider')
            ->where(fn ($q) => $q->whereNotNull('recorded_by')->orWhereNotNull('confirmed_by'))->count();
        $failures = [];
        if ($sameActor > 0) {
            $failures[] = "{$sameActor} manual payment(s) with recorder = confirmer (BI-9 breach)";
        }
        if ($providerWithActor > 0) {
            $failures[] = "{$providerWithActor} provider payment(s) carrying a human recorder/confirmer (OD-47 breach)";
        }
        $total = (int) DB::table('payments')->count();

        return $failures !== []
            ? AssertionResult::fail(implode(' · ', $failures))
            : AssertionResult::pass("{$total} payment(s) checked".($total === 0 ? ' (vacuous)' : ', SoD holds both directions'));
    }
}
