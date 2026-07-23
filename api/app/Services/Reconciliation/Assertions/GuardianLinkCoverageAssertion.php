<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B8 / 2.2: no student with a non-terminal enrolment may have zero active
 * guardian links. Ships in S01 (card: "vacuous until S04A — ships now");
 * the query self-activates the moment the enrolments table exists.
 */
class GuardianLinkCoverageAssertion implements Assertion
{
    private const NON_TERMINAL = ['intent', 'consent_pending', 'payment_pending', 'active'];

    public function key(): string
    {
        return 'links.guardian_coverage';
    }

    public function proves(): string
    {
        return 'every student with a non-terminal enrolment has at least one active guardian link';
    }

    public function cites(): string
    {
        return 'Spec B8 · 2.2 · P3';
    }

    public function tags(): array
    {
        return ['S01'];
    }

    public function check(): AssertionResult
    {
        if (! Schema::hasTable('enrolments')) {
            return AssertionResult::pass('vacuous until S04A — enrolments table does not exist yet');
        }

        $orphans = DB::table('enrolments')
            ->whereIn(DB::raw('lower(status)'), self::NON_TERMINAL)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('guardian_links')
                    ->whereColumn('guardian_links.student_id', 'enrolments.student_id')
                    ->where('guardian_links.status', 'active');
            })
            ->distinct()->count('student_id');

        return $orphans === 0
            ? AssertionResult::pass('no enrolled student lacks an active guardian link')
            : AssertionResult::fail("{$orphans} student(s) with non-terminal enrolments have ZERO active guardian links (B8)");
    }
}
