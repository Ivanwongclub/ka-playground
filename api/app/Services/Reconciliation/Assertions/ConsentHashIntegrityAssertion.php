<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * BI-6, language-scoped (OD-20): every signature's stored template hash equals
 * its template version row's SHA-256, matched IN THE LANGUAGE SIGNED — and the
 * signature's language IS the version row's language, never a translation.
 */
class ConsentHashIntegrityAssertion implements Assertion
{
    public function key(): string
    {
        return 'consent.bi6_hash_language_scoped';
    }

    public function proves(): string
    {
        return "every signature's stored hash equals its template version's SHA-256, matched to the version row in the language signed";
    }

    public function cites(): string
    {
        return 'BI-6 · OD-20';
    }

    public function tags(): array
    {
        return ['S03'];
    }

    public function check(): AssertionResult
    {
        $total = (int) DB::table('consent_signatures')->count();
        $violations = DB::table('consent_signatures as s')
            ->join('consent_template_versions as v', 'v.id', '=', 's.template_version_id')
            ->where(function ($q): void {
                $q->whereColumn('s.template_sha256', '<>', 'v.sha256')
                    ->orWhereColumn('s.language', '<>', 'v.language');
            })
            ->get(['s.id', 's.language', 'v.language as version_language']);

        if ($violations->isNotEmpty()) {
            return AssertionResult::fail(
                $violations->count().' signature(s) with hash or language mismatch: '
                .$violations->take(5)->map(fn ($v) => $v->id)->implode(', ')
            );
        }

        return AssertionResult::pass("{$total} signature(s) checked, all hash- and language-bound".($total === 0 ? ' (vacuous until signatures exist)' : ''));
    }
}
