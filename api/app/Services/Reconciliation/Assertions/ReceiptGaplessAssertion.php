<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/** BI-2: receipt numbers are gapless per sequence — max = count, no duplicates, next = max+1. */
class ReceiptGaplessAssertion implements Assertion
{
    public function key(): string
    {
        return 'receipts.gapless';
    }

    public function proves(): string
    {
        return 'receipt numbers are gapless within each sequence: max = count, no duplicates, and the sequence counter is exactly one past the max';
    }

    public function cites(): string
    {
        return 'BI-2';
    }

    public function tags(): array
    {
        return ['S04B'];
    }

    public function check(): AssertionResult
    {
        $failures = [];
        $sequences = DB::table('receipt_sequences')->get();
        foreach ($sequences as $seq) {
            $agg = DB::selectOne(
                'SELECT count(*) AS c, count(DISTINCT receipt_number) AS d, coalesce(max(receipt_number), 0) AS m
                 FROM receipts WHERE sequence_key = ?', [$seq->key]);
            if ((int) $agg->c !== (int) $agg->d) {
                $failures[] = "{$seq->key}: duplicate receipt numbers ({$agg->c} rows, {$agg->d} distinct)";
            }
            if ((int) $agg->c !== (int) $agg->m) {
                $failures[] = "{$seq->key}: gap — {$agg->c} receipts but max number {$agg->m}";
            }
            if ((int) $seq->next_number !== (int) $agg->m + 1) {
                $failures[] = "{$seq->key}: counter {$seq->next_number} ≠ max {$agg->m} + 1";
            }
        }
        $total = (int) DB::table('receipts')->count();

        return $failures !== []
            ? AssertionResult::fail(implode(' · ', $failures))
            : AssertionResult::pass("{$total} receipt(s) across ".$sequences->count().' sequence(s), all gapless'.($total === 0 ? ' (vacuous)' : ''));
    }
}
