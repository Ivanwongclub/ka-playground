<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Services\Authz\ScopeContext;
use App\Services\Programmes\WizardService;
use App\Services\Uploads\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
 * S-READ-3 items 2+3 — ONE conditional addition and one unconditional one:
 *   · `enrolment_opens_at` (unconditional, public) — served from the COLUMN, which is the SAME source the
 *     derived `status` is computed from, so the field and the chip can never contradict each other. It has a
 *     real writer (ProgrammeController's admin create/update validates it and snapshots it into
 *     programme_versions), unlike `programmes.ends_at`. Unblocks the storefront's "Coming soon" filter.
 *   · `fee_total_minor` (AUTHENTICATED FAMILY CALLERS ONLY, ruling F-3/F-5) — the ruling's word was
 *     "family-visible", not world-visible. The ANONYMOUS payload carries no money field at all, so
 *     `payment_links.single_reader` stays true in BOTH senses: its three mechanical checks pass AND the
 *     sentence they assert — the token path is the only UNAUTHENTICATED reader of payment data — remains
 *     factually correct. See withFeeTotals() for the audit-volume bound (F-4).
 *
 * CONSTANT-SHAPE: any id that is not (published AND non-template AND marketing-complete) — including a
 * nonexistent or non-numeric id — returns the identical `notFound()` body, so the surface cannot be used
 * to distinguish "exists but not listable" from "does not exist" (no enumeration, no state leak).
 */
class MarketplaceController extends Controller
{
    /** Byte-matches config/scope-elevations.php — asSystem throws if it drifts (ScopeContext:139-143). */
    public const FEE_REASON = 'Storefront fee totals (S-READ-3 F-3/F-4): sum fee_items.amount_minor per programme for the ALREADY-FILTERED listable programme ids, for an authenticated family caller only. fee_items is RLS finance-only and the wizard fees section stores no amounts, so there is no unelevated path to the published price; ONE elevated query per request bounds this to a single audit row per authenticated marketplace load.';

    public function __construct(private readonly ScopeContext $scope) {}

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
     * Every listable storefront row (optionally a single id). Reads `programmes` + `wizard_sections` (neither
     * carries RLS) and `uploads` for banner cleanliness — all public-safe, all unelevated. The ONE money read
     * lives in withFeeTotals() below, is elevated, and never runs for an anonymous caller.
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

        $rows = $programmes->map(function ($p) use ($sections, $today, $cleanBanners, $now): ?array {
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
                // FROM THE COLUMN — the same source `status` below is derived from. FLAG (S-READ-3 item 3,
                // ruled to the demo-seeder publish-contract card, NOT fixed here): `enrolment_closes_on` on the
                // next line comes from the basics WIZARD JSON instead, and syncBasicsDates mirrors only
                // `starts_at`, so the two sources are free to disagree — and in the seeded database they do.
                'enrolment_opens_at' => $p->enrolment_opens_at,
                'enrolment_closes_on' => $basics['enrolment_closes_on'] ?? null,
                'tagline' => $mk['tagline'], 'category' => $mk['category'],
                'age_range' => $mk['age_range'], 'duration' => $mk['duration'],
                'brand_color' => $mk['brand_color'],
                // KAP-MKT-1: derived enrolment STATUS — open/closed ONLY (D-MKT-2). Capacity/"Full" is NOT
                // derivable by families (pc_read is staff-only; claimed advances at Team Formation, not at
                // enrolment — OD-31). Do NOT add "Full" here without that context: it would fake R-4-unsafe data.
                'status' => (($p->enrolment_opens_at !== null && $now->lt(Carbon::parse($p->enrolment_opens_at)))
                    || ($p->enrolment_closes_at !== null && $now->gte(Carbon::parse($p->enrolment_closes_at)))) ? 'closed' : 'open',
                'banner_url' => ($p->banner_upload_id !== null && $cleanBanners->has($p->banner_upload_id)) ? "/api/programmes/{$p->id}/banner" : null,
            ];
        })->filter()->values();

        return $this->withFeeTotals($rows);
    }

    /**
     * S-READ-3 item 2 — attach `fee_total_minor` + `currency`, for an AUTHENTICATED FAMILY caller only.
     *
     * WHY AN ELEVATION AT ALL: `fee_items` is RLS-forced `system OR finance`, and the wizard's fees section
     * stores `{"has_fee_items": true}` and no amounts — so no unelevated path to the published price exists.
     * The card forbids touching fee_items_read, which leaves exactly this.
     *
     * F-4 — THE AUDIT VOLUME IS THE POINT OF THIS SHAPE. asSystem writes one audit_events row per CALL
     * (ScopeContext:151), and audit_events is INSERT-only and immutable (BI-1). So this is ONE query for
     * every listable programme at once — never one per programme — and it runs only for an authenticated
     * family caller. One audit row per authenticated marketplace load; anonymous traffic elevates nothing.
     * It also runs AFTER the marketing-complete filter, so it touches only ids actually being served.
     *
     * FAMILY = student + guardian, per the ruling. This is NOT the P-3/B-18 case: that rule keeps an
     * ENROLMENT'S ORDER AMOUNT — a specific family's obligation, "one shared column, two viewers"
     * (EnrolmentController:56) — off a read a student receives. A published catalogue LIST PRICE is
     * marketing, identical for every viewer, and the prototype puts it on the STUDENT explore card (L169).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withFeeTotals(Collection $rows): Collection
    {
        // The route is deliberately guest (anonymous browsing must keep working), so the token is resolved
        // opportunistically off the sanctum guard rather than by middleware: absent/invalid => null => no fee.
        $viewer = Auth::guard('sanctum')->user();
        if ($rows->isEmpty() || $viewer === null || ! in_array($viewer->role, ['student', 'guardian'], true)) {
            return $rows;
        }

        $ids = $rows->pluck('id')->all();
        $totals = $this->scope->asSystem(self::FEE_REASON, fn (): array => DB::table('fee_items')
            ->whereIn('programme_id', $ids)
            ->groupBy('programme_id')
            // COUNT(DISTINCT currency) is not decoration: summing across currencies would be a false total.
            // Phase 1's CHECK makes it impossible today (currency = 'HKD'); if OD-18 ever widens, this omits
            // the field rather than inventing a number. The currency is READ, never hardcoded here.
            ->selectRaw('programme_id, SUM(amount_minor) AS total_minor, MIN(currency) AS currency, COUNT(DISTINCT currency) AS currency_count')
            ->get()->keyBy('programme_id')->all());

        return $rows->map(function (array $row) use ($totals): array {
            $t = $totals[$row['id']] ?? null;
            // NO fee_items => NO field. Never HK$0.00: publish only requires the wizard's `has_fee_items`
            // boolean, so a published programme CAN have zero rows — and OrderService:36-38 throws in exactly
            // that case rather than issuing a free order. A price is never invented.
            if ($t === null || (int) $t->currency_count !== 1) {
                return $row;
            }
            // The SUM is the honest single number: it is precisely what OrderService:35-39 charges as
            // orders.total_amount_minor and what ConsentSigningService:68 freezes into the consent.
            $row['fee_total_minor'] = (int) $t->total_minor;
            $row['currency'] = $t->currency;

            return $row;
        });
    }

    /** The single, constant not-found response used for every non-listable / nonexistent id. */
    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'No such programme'], 404);
    }
}
