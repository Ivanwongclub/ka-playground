<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * A superuser can disable a trigger without touching a row of data; this probe
 * makes that tampering visible within one nightly cycle (Leo review, 23 Jul —
 * closes AUDIT S00 §5 item 5).
 */
class AuditTriggerEnabledProbe implements Assertion
{
    public function key(): string
    {
        return 'audit.trigger_enabled';
    }

    public function proves(): string
    {
        return "the BI-1 trigger audit_events_immutable_guard exists and is enabled (tgenabled = 'O')";
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
            return AssertionResult::fail(
                'requires pgsql (current driver: '.DB::getDriverName().') — run against the platform database'
            );
        }

        $trigger = DB::selectOne(
            "SELECT tgenabled FROM pg_trigger WHERE tgname = 'audit_events_immutable_guard' AND NOT tgisinternal"
        );

        if ($trigger === null) {
            return AssertionResult::fail('trigger audit_events_immutable_guard is MISSING');
        }
        if ($trigger->tgenabled !== 'O') {
            return AssertionResult::fail(
                "trigger exists but tgenabled = '{$trigger->tgenabled}' (expected 'O' — it has been disabled)"
            );
        }

        return AssertionResult::pass("tgenabled = 'O'");
    }
}
