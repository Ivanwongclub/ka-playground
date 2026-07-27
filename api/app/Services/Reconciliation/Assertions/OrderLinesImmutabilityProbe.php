<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * BI-5: order_lines rejects mutation at the database. WHERE false touches no
 * row; the trigger (and, on real environments, the privilege revoke) still
 * fires, so the probe is read-only in effect. Mirrors AuditImmutabilityProbe.
 */
class OrderLinesImmutabilityProbe implements Assertion
{
    public function key(): string
    {
        return 'order_lines.immutable';
    }

    public function proves(): string
    {
        return 'order_lines rejects UPDATE and DELETE at the database level (corrections are credit notes/refunds, never edits)';
    }

    public function cites(): string
    {
        return 'BI-5';
    }

    public function tags(): array
    {
        return ['S04B'];
    }

    public function check(): AssertionResult
    {
        if (DB::getDriverName() !== 'pgsql') {
            return AssertionResult::fail('requires pgsql — the guard exists only on the platform database');
        }
        foreach (['UPDATE order_lines SET amount_minor = amount_minor WHERE false', 'DELETE FROM order_lines WHERE false'] as $statement) {
            try {
                DB::statement($statement);

                return AssertionResult::fail("order_lines accepted `{$statement}` — immutability guard missing (BI-5)");
            } catch (QueryException) {
                // expected — trigger or privilege revoke blocked it
            }
        }

        return AssertionResult::pass('order_lines rejects both UPDATE and DELETE at the DB');
    }
}
