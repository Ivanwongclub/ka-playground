<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * FR037/OD-20a: no request may sit in `signed` on a version that a LATER
 * MATERIAL version (same template, same language) has superseded, unless a
 * fresh open request exists for the same signer/student/programme. The
 * publish-time fan-out enforces this; the nightly assertion catches any path
 * that missed it. Vacuous-aware (fully active from S04A enrolment volume).
 */
class SupersededVersionReconsentAssertion implements Assertion
{
    public function key(): string
    {
        return 'consent.superseded_reconsent';
    }

    public function proves(): string
    {
        return 'no signed request rests on a version superseded by a later material version in its language without an open re-consent request';
    }

    public function cites(): string
    {
        return 'FR037 · OD-20a';
    }

    public function tags(): array
    {
        return ['S03'];
    }

    public function check(): AssertionResult
    {
        $signed = DB::table('consent_requests as r')
            ->join('consent_signatures as s', 's.request_id', '=', 'r.id')
            ->join('consent_template_versions as v', 'v.id', '=', 's.template_version_id')
            ->where('r.status', 'signed')
            ->get(['r.id', 'r.template_id', 'r.programme_id', 'r.student_id', 'r.signer_id', 's.language', 'v.version']);

        $violations = [];
        foreach ($signed as $row) {
            $laterMaterial = DB::table('consent_template_versions')
                ->where('template_id', $row->template_id)->where('language', $row->language)
                ->where('status', 'published')->where('is_material', true)
                ->where('version', '>', $row->version)->exists();
            if (! $laterMaterial) {
                continue;
            }
            $openReplacement = DB::table('consent_requests')
                ->where('template_id', $row->template_id)->where('programme_id', $row->programme_id)
                ->where('student_id', $row->student_id)->where('signer_id', $row->signer_id)
                ->whereIn('status', ['sent', 'viewed'])->exists();
            if (! $openReplacement) {
                $violations[] = $row->id;
            }
        }

        if ($violations !== []) {
            return AssertionResult::fail(count($violations).' signed request(s) on a materially superseded version with NO open re-consent: '.implode(', ', array_slice($violations, 0, 5)));
        }

        return AssertionResult::pass($signed->count().' signed request(s) checked'.($signed->isEmpty() ? ' (vacuous until signatures exist)' : ', none resting on a materially superseded version'));
    }
}
