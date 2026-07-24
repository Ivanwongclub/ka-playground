<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Consent\ConsentTemplateService;
use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OD-20/OD-20a as a standing invariant, not just a publish-time gate: no
 * PUBLISHED programme's selected template may lack a language version or carry
 * drifted (unequal) published versions across the three languages.
 */
class ConsentLanguageCompletenessAssertion implements Assertion
{
    public function key(): string
    {
        return 'consent.language_completeness';
    }

    public function proves(): string
    {
        return 'no published programme selects a consent template missing a language version or with drifted versions (OD-20a parity)';
    }

    public function cites(): string
    {
        return 'OD-20 · OD-20a';
    }

    public function tags(): array
    {
        return ['S03'];
    }

    public function check(): AssertionResult
    {
        $selected = DB::table('programmes as p')
            ->join('wizard_sections as ws', function ($join): void {
                $join->on('ws.programme_id', '=', 'p.id')->where('ws.section_key', 'consent');
            })
            ->where('p.status', 'published')
            ->get(['p.id', 'p.code', 'ws.data']);

        $service = app(ConsentTemplateService::class);
        $failures = [];
        $checked = 0;
        foreach ($selected as $row) {
            $ref = json_decode((string) $row->data, true)['template_ref'] ?? null;
            if ($ref === null || ! Str::isUuid($ref)) {
                continue; // template_missing is the wizard pre-flight's finding; legacy sentinel grandfathered to S04A
            }
            $checked++;
            $parity = $service->languageParity($ref);
            if (! $parity['complete'] || $parity['drift']) {
                $failures[] = "{$row->code}: ".json_encode($parity['versions']);
            }
        }

        if ($failures !== []) {
            return AssertionResult::fail(count($failures).' published programme(s) with incomplete/drifted consent languages — '.implode(' · ', $failures));
        }

        return AssertionResult::pass("{$checked} published programme template selection(s) checked, all three languages present at parity".($checked === 0 ? ' (vacuous until selections exist)' : ''));
    }
}
