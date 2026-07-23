<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Probes that the database actually rejects mutation of audit_events.
 * WHERE false touches no row; the statement-level trigger still fires,
 * so the probe is read-only even in effect.
 */
class AuditImmutabilityProbe implements Assertion
{
    public function key(): string
    {
        return 'audit.immutability';
    }

    public function proves(): string
    {
        return 'audit_events rejects UPDATE and DELETE at the database level';
    }

    public function cites(): string
    {
        return 'BI-1';
    }

    public function tags(): array
    {
        return ['S00'];
    }

    public function check(): AssertionResult
    {
        if (DB::getDriverName() !== 'pgsql') {
            // No skip affordance exists: on the wrong engine this is a FAILURE.
            // The statement-level trigger only exists on the platform database.
            return AssertionResult::fail(
                'requires pgsql (current driver: '.DB::getDriverName().') — run against the platform database'
            );
        }

        foreach ([
            'UPDATE audit_events SET action = action WHERE false',
            'DELETE FROM audit_events WHERE false',
        ] as $statement) {
            // Own savepoint per statement: a rejected statement aborts the
            // enclosing pg transaction, and rollback-to-savepoint restores it
            DB::beginTransaction();
            try {
                DB::statement($statement);
                DB::rollBack();

                return AssertionResult::fail(
                    "database ACCEPTED a forbidden statement: {$statement}"
                );
            } catch (QueryException $e) {
                DB::rollBack();
                if (! str_contains($e->getMessage(), 'INSERT-only')) {
                    return AssertionResult::fail(
                        "statement rejected but not by the BI-1 guard: {$e->getMessage()}"
                    );
                }
            }
        }

        return AssertionResult::pass('UPDATE and DELETE both rejected by the BI-1 trigger');
    }
}
