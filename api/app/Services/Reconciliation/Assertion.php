<?php

namespace App\Services\Reconciliation;

/**
 * A nightly reconciliation assertion (Spec P3 / SR010).
 *
 * Contract, non-negotiable:
 * - READ-ONLY. Assertions run nightly against production; any write is a
 *   defect. The runner additionally executes every check inside a transaction
 *   that is ALWAYS rolled back, so a defective write cannot persist.
 * - No skip, disable or known-failing affordance exists, deliberately
 *   (CLAUDE.md §2.7). A red assertion is a stop, not a skip.
 */
interface Assertion
{
    /** Unique stable key, e.g. 'audit.immutability'. */
    public function key(): string;

    /** One sentence: what this assertion proves when green. */
    public function proves(): string;

    /** The Build Invariant / requirement IDs it guards, e.g. 'BI-1'. */
    public function cites(): string;

    /** Sprint tags for --tag= filtering, e.g. ['S00']. */
    public function tags(): array;

    /** Run the check. Read-only. Throwing counts as failure. */
    public function check(): AssertionResult;
}
