<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Services\Programmes\WizardService;
use App\Services\Uploads\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * KAP-MKT-1 (D-MKT-4) — stream the storefront banner, ONLY when it has passed the BI-10 scan. PUBLIC (a
     * banner is public marketing, no PII) but gated to PUBLISHED, non-template programmes (a draft's image
     * never serves). A not-clean / absent / non-listable banner returns the SAME constant-shape not-found —
     * the UI then renders the brand_color fallback, never a broken image.
     */
    public function banner(string $id): mixed
    {
        if (! ctype_digit($id)) {
            return $this->notFound();
        }
        $bannerId = DB::table('programmes')->where('id', (int) $id)
            ->where('status', 'published')->where('is_template', false)->value('banner_upload_id');
        if ($bannerId === null) {
            return $this->notFound();
        }
        $upload = Upload::query()->find($bannerId);
        if ($upload === null || $upload->status !== Upload::STATUS_CLEAN) {
            return $this->notFound(); // pending / quarantined → constant not-found; UI falls back to brand_color
        }

        return response(app(UploadService::class)->contents($upload), 200, [
            'Content-Type' => $upload->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * KAP-MKT-1 — the wizard's OPTIONAL banner upload (config.manage-gated at the route). Goes through the
     * SAME file-intake + ClamAV path (BI-10) as every other upload (context 'image': jpeg/png/webp ≤5MB);
     * the scan-clean reference lands on programmes.banner_upload_id. Marketing completeness is UNCHANGED —
     * the banner is never a publish/storefront prerequisite.
     */
    public function uploadBanner(Request $request, string $id): JsonResponse
    {
        $request->validate(['banner' => ['required', 'file']]);
        $upload = app(UploadService::class)->intake($request->file('banner'), 'image', $request->user());
        DB::table('programmes')->where('id', (int) $id)->update(['banner_upload_id' => $upload->id, 'updated_at' => now()]);

        return response()->json(['upload_id' => $upload->id, 'status' => $upload->status], 201);
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
        $programmes = $q->orderBy('id')->get(['id', 'code', 'name_en', 'name_tc', 'name_sc',
            'enrolment_opens_at', 'enrolment_closes_at', 'banner_upload_id']); // KAP-MKT-1: window + banner
        if ($programmes->isEmpty()) {
            return collect();
        }

        $sections = DB::table('wizard_sections')
            ->whereIn('programme_id', $programmes->pluck('id'))
            ->whereIn('section_key', ['marketing', 'basics'])
            ->get(['programme_id', 'section_key', 'data'])
            ->groupBy('programme_id');
        $today = now()->toDateString();
        // KAP-MKT-1 (D-MKT-4): which banners have passed the scan — `uploads` is global, no PII. A banner_url
        // is surfaced ONLY for a clean upload; otherwise null → the UI renders the brand_color fallback.
        $bannerIds = $programmes->pluck('banner_upload_id')->filter()->values();
        $cleanBanners = $bannerIds->isEmpty() ? collect() : DB::table('uploads')->whereIn('id', $bannerIds)->where('status', 'clean')->pluck('id')->flip();
        $now = now();

        return $programmes->map(function ($p) use ($sections, $today, $cleanBanners, $now): ?array {
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
                // KAP-MKT-1: derived enrolment STATUS — open/closed ONLY (D-MKT-2). Capacity/"Full" is NOT
                // derivable by families (pc_read is staff-only; claimed advances at Team Formation, not at
                // enrolment — OD-31). Do NOT add "Full" here without that context: it would fake R-4-unsafe data.
                'status' => (($p->enrolment_opens_at !== null && $now->lt(\Illuminate\Support\Carbon::parse($p->enrolment_opens_at)))
                    || ($p->enrolment_closes_at !== null && $now->gte(\Illuminate\Support\Carbon::parse($p->enrolment_closes_at)))) ? 'closed' : 'open',
                'banner_url' => ($p->banner_upload_id !== null && $cleanBanners->has($p->banner_upload_id)) ? "/api/programmes/{$p->id}/banner" : null,
            ];
        })->filter()->values();
    }

    /** The single, constant not-found response used for every non-listable / nonexistent id. */
    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'No such programme'], 404);
    }
}
