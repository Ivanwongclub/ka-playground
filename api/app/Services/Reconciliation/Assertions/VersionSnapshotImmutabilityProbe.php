<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** D5: programme version snapshots reject mutation at the database (S02A). */
class VersionSnapshotImmutabilityProbe implements Assertion
{
    public function key(): string
    {
        return 'programmes.version_immutability';
    }

    public function proves(): string
    {
        return 'programme_versions rejects UPDATE and DELETE at the database level';
    }

    public function cites(): string
    {
        return 'Spec D5 · S02A';
    }

    public function tags(): array
    {
        return ['S02A'];
    }

    public function check(): AssertionResult
    {
        if (DB::getDriverName() !== 'pgsql') {
            return AssertionResult::fail('requires pgsql — run against the platform database');
        }

        foreach ([
            'UPDATE programme_versions SET version = version WHERE false',
            'DELETE FROM programme_versions WHERE false',
        ] as $statement) {
            DB::beginTransaction();
            try {
                DB::statement($statement);
                DB::rollBack();

                return AssertionResult::fail("database ACCEPTED: {$statement}");
            } catch (QueryException $e) {
                DB::rollBack();
                // Two valid rejection layers: the privilege REVOKE on the app
                // role (SQLSTATE 42501) fires before the trigger ever could;
                // both are database-level enforcement.
                $privilegeDenied = ($e->errorInfo[0] ?? '') === '42501';
                if (! $privilegeDenied && ! str_contains($e->getMessage(), 'INSERT-only')) {
                    return AssertionResult::fail("rejected but not by the D5 guard: {$e->getMessage()}");
                }
            }
        }

        return AssertionResult::pass('UPDATE and DELETE both rejected at the database (privilege revoke and/or D5 trigger)');
    }
}
