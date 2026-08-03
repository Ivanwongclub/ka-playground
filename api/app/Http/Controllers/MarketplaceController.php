<?php

namespace App\Http\Controllers;

use App\Services\Programmes\WizardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * S-MARKETPLACE-A STEP 2 — the public programme catalogue (the S04C-analogue anonymous READ; S04C was the
 * first anonymous WRITE). UNAUTHENTICATED. Under Option B this read is the SOLE storefront safety gate:
 * publish no longer requires marketing, so nothing but the filter here keeps an incomplete or marketing-
 * less programme out of the public storefront.
 *
 * SAFETY BOUNDARY (filters at QUERY TIME — never relies on the battery):
 *   a row is returned iff  status='published' AND is_template=false AND marketing-complete, where
 *   "marketing-complete" is the SAME shared predicate STEP 1 defined —
 *   `WizardService::marketingLanguageGaps($data) === []` (never re-implemented here). The reconcile
 *   assertion `programmes.published_completeness` is a BACKSTOP, not this gate.
 *
 * NO PII: joins ONLY `programmes` + `wizard_sections` — never users / enrolments / guardians, no
 * enrolled_count, no capacity (omitted v1). These two tables carry no RLS (public-safe), so the read
 * needs no elevation. Current/past is derived from the basics timeline (`starts_on`) — no new state.
 *
 * CONSTANT-SHAPE: any id that is not (published AND non-template AND marketing-complete) — including a
 * nonexistent or non-numeric id — returns the identical `notFound()` body, so the surface cannot be used
 * to distinguish "exists but not listable" from "does not exist" (no enumeration, no state leak).
 */
class MarketplaceController extends Controller
{
    /** GET /programmes — the public catalogue (current + past, split by `phase`). */
    public function catalogue(): JsonResponse
    {
        return response()->json(['data' => $this->listable()->all()]);
    }

    /** GET /programmes/{id} — public detail; a non-listable id returns the constant-shape not-found. */
    public function show(string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return $this->notFound(); // garbage id → same shape (no DB probe, no enumeration)
        }
        $row = $this->listable((int) $id)->first();

        return $row !== null ? response()->json($row) : $this->notFound();
    }

    /**
     * Every listable storefront row (optionally a single id). Reads ONLY `programmes` + `wizard_sections`.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function listable(?int $onlyId = null): Collection
    {
        $q = DB::table('programmes')->where('status', 'published')->where('is_template', false);
        if ($onlyId !== null) {
            $q->where('id', $onlyId);
        }
        $programmes = $q->orderBy('id')->get(['id', 'code', 'name_en', 'name_tc', 'name_sc']);
        if ($programmes->isEmpty()) {
            return collect();
        }

        $sections = DB::table('wizard_sections')
            ->whereIn('programme_id', $programmes->pluck('id'))
            ->whereIn('section_key', ['marketing', 'basics'])
            ->get(['programme_id', 'section_key', 'data'])
            ->groupBy('programme_id');
        $today = now()->toDateString();

        return $programmes->map(function ($p) use ($sections, $today): ?array {
            $byKey = ($sections->get($p->id) ?? collect())->keyBy('section_key');

            $mkRow = $byKey->get('marketing');
            if ($mkRow === null) {
                return null; // grandfathered — legitimately NOT in the storefront
            }
            $mk = json_decode((string) $mkRow->data, true) ?? [];
            if (WizardService::marketingLanguageGaps($mk) !== []) {
                return null; // present-but-incomplete — absent from the storefront (the safety gate)
            }

            $basics = json_decode((string) ($byKey->get('basics')->data ?? '{}'), true) ?? [];
            $startsOn = $basics['starts_on'] ?? null;

            return [
                'id' => (int) $p->id,
                'code' => $p->code,
                'name_en' => $p->name_en, 'name_tc' => $p->name_tc, 'name_sc' => $p->name_sc,
                'phase' => ($startsOn !== null && $startsOn < $today) ? 'past' : 'current',
                'starts_on' => $startsOn,
                'enrolment_closes_on' => $basics['enrolment_closes_on'] ?? null,
                'tagline' => $mk['tagline'], 'category' => $mk['category'],
                'age_range' => $mk['age_range'], 'duration' => $mk['duration'],
                'brand_color' => $mk['brand_color'],
            ];
        })->filter()->values();
    }

    /** The single, constant not-found response used for every non-listable / nonexistent id. */
    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'No such programme'], 404);
    }
}
