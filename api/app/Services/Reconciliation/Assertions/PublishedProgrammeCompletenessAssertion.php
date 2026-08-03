<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Programmes\WizardService;
use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/** D4/D5: no Published programme may lack a consent template selection or fee items; and a marketing
 *  section, IF present, is complete-trilingual (S-MARKETPLACE-A Option B — storefront gate). */
class PublishedProgrammeCompletenessAssertion implements Assertion
{
    public function key(): string
    {
        return 'programmes.published_completeness';
    }

    public function proves(): string
    {
        return 'every Published programme has a consent template selected and at least one fee item, and any marketing section it carries is complete in all three languages (a programme with no marketing section is grandfathered — legitimately absent from the public storefront)';
    }

    public function cites(): string
    {
        return 'Spec D4/D5 · S02B';
    }

    public function tags(): array
    {
        return ['S02B'];
    }

    public function check(): AssertionResult
    {
        $failures = [];
        $published = DB::table('programmes')->where('status', 'published')->where('is_template', false)->get(['id', 'code']);
        foreach ($published as $programme) {
            $consent = DB::table('wizard_sections')
                ->where('programme_id', $programme->id)->where('section_key', 'consent')->value('data');
            if (empty(json_decode((string) $consent, true)['template_ref'] ?? null)) {
                $failures[] = "{$programme->code}: no consent template selected";
            }
            if (DB::table('fee_items')->where('programme_id', $programme->id)->count() === 0) {
                $failures[] = "{$programme->code}: zero fee items";
            }
            // S-MARKETPLACE-A (Option B) — GRANDFATHER a published programme with NO marketing row (it is
            // legitimately not in the public storefront); a PRESENT marketing row must be complete-
            // trilingual, else it is a half-filled/tampered row the public read would otherwise have to
            // filter. Same predicate the STEP-2 public read uses.
            $marketing = DB::table('wizard_sections')
                ->where('programme_id', $programme->id)->where('section_key', 'marketing')->value('data');
            if ($marketing !== null) {
                $gaps = WizardService::marketingLanguageGaps(json_decode((string) $marketing, true) ?? []);
                if ($gaps !== []) {
                    $failures[] = "{$programme->code}: marketing present but incomplete (".implode(', ', $gaps).')';
                }
            }
        }

        return $failures === []
            ? AssertionResult::pass($published->count().' published programme(s), all complete')
            : AssertionResult::fail(implode('; ', $failures));
    }
}
