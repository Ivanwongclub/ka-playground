<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S-MARKETPLACE-A STEP 2 — the public catalogue read, the SOLE storefront safety gate under Option B.
 * The leak test is airtight by design: only a published + non-template + marketing-complete programme
 * appears; every other case is ABSENT from the catalogue AND returns a constant-shape not-found from the
 * detail endpoint (no enumeration, no state leak, no PII). Unauthenticated throughout.
 */
class MarketplaceCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const MK_COMPLETE = [
        'tagline' => ['en' => 'Build robots', 'tc' => '製造機械人', 'sc' => '制造机械人'],
        'category' => ['en' => 'STEM', 'tc' => '理工', 'sc' => '理工'],
        'age_range' => ['en' => '8–12', 'tc' => '8至12歲', 'sc' => '8至12岁'],
        'duration' => ['en' => '10 weeks', 'tc' => '10週', 'sc' => '10周'],
        'brand_color' => '#7A3B57',
    ];

    /** Create a programme + optional marketing/basics sections. programmes/wizard_sections carry no RLS. */
    private function mkProgramme(string $status, bool $isTemplate, ?string $marketing, ?string $startsOn = '2099-01-01'): int
    {
        $pid = DB::table('programmes')->insertGetId([
            'code' => 'MP'.Str::upper(Str::random(6)), 'name_en' => 'Prog EN', 'name_tc' => '課程', 'name_sc' => '课程',
            'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => $status, 'is_template' => $isTemplate,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('wizard_sections')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $pid, 'section_key' => 'basics', 'status' => 'complete', 'data' => json_encode(['starts_on' => $startsOn, 'enrolment_closes_on' => '2098-12-01']), 'created_at' => now(), 'updated_at' => now()]);
        if ($marketing !== null) {
            $data = self::MK_COMPLETE;
            if ($marketing === 'incomplete') {
                unset($data['tagline']['sc']); // drop 简 → present-but-incomplete
            }
            DB::table('wizard_sections')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $pid, 'section_key' => 'marketing', 'status' => 'complete', 'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $pid;
    }

    private function catalogueIds(): array
    {
        return collect($this->getJson('/api/programmes')->assertOk()->json('data'))->pluck('id')->all();
    }

    // ── 1. complete → APPEARS + detail 200 (+ current/past split) ────────────────────────────────────
    public function test_complete_programme_appears_and_detail_returns_200(): void
    {
        $listable = $this->mkProgramme('published', false, 'complete', '2099-01-01'); // upcoming → current
        $past = $this->mkProgramme('published', false, 'complete', '2000-01-01');     // started long ago → past

        $this->assertContains($listable, $this->catalogueIds());
        $this->assertContains($past, $this->catalogueIds());

        $detail = $this->getJson("/api/programmes/{$listable}")->assertOk()->json();
        $this->assertSame('Build robots', $detail['tagline']['en']);
        $this->assertSame('current', $detail['phase']);
        $this->assertSame('past', collect($this->getJson('/api/programmes')->json('data'))->firstWhere('id', $past)['phase']);
    }

    // ── 2. present-but-incomplete marketing → ABSENT + constant not-found ────────────────────────────
    public function test_incomplete_marketing_is_absent_and_detail_is_not_found(): void
    {
        $id = $this->mkProgramme('published', false, 'incomplete');
        $this->assertNotContains($id, $this->catalogueIds());
        $this->assertSame($this->notFoundBody(), $this->getJson("/api/programmes/{$id}")->assertStatus(404)->getContent());
    }

    // ── 3. grandfathered (no marketing row) → ABSENT + not-found ─────────────────────────────────────
    public function test_grandfathered_no_marketing_is_absent(): void
    {
        $id = $this->mkProgramme('published', false, null);
        $this->assertNotContains($id, $this->catalogueIds());
        $this->getJson("/api/programmes/{$id}")->assertStatus(404);
    }

    // ── 4. draft (even marketing-complete) → ABSENT + not-found ──────────────────────────────────────
    public function test_draft_is_absent(): void
    {
        $id = $this->mkProgramme('draft', false, 'complete');
        $this->assertNotContains($id, $this->catalogueIds());
        $this->getJson("/api/programmes/{$id}")->assertStatus(404);
    }

    // ── 5. published TEMPLATE → ABSENT + not-found ───────────────────────────────────────────────────
    public function test_published_template_is_absent(): void
    {
        $id = $this->mkProgramme('published', true, 'complete');
        $this->assertNotContains($id, $this->catalogueIds());
        $this->getJson("/api/programmes/{$id}")->assertStatus(404);
    }

    // ── 6. CONSTANT-SHAPE: every non-listable / nonexistent / garbage id returns a byte-identical 404 ─
    public function test_not_found_is_constant_shape_across_all_cases(): void
    {
        $incomplete = $this->mkProgramme('published', false, 'incomplete');
        $grandfathered = $this->mkProgramme('published', false, null);
        $draft = $this->mkProgramme('draft', false, 'complete');
        $template = $this->mkProgramme('published', true, 'complete');

        $bodies = [];
        $statuses = [];
        foreach (["/api/programmes/{$incomplete}", "/api/programmes/{$grandfathered}", "/api/programmes/{$draft}",
            "/api/programmes/{$template}", '/api/programmes/999999', '/api/programmes/abc'] as $url) {
            $res = $this->getJson($url);
            $bodies[] = $res->getContent();
            $statuses[] = $res->getStatusCode();
        }
        // every case is byte-identical — no way to distinguish "exists but not listable" from "does not exist"
        $this->assertCount(1, array_unique($bodies), 'not-found bodies must be byte-identical');
        $this->assertCount(1, array_unique($statuses), 'not-found statuses must be identical');
        $this->assertSame(404, $statuses[0]);
        $this->assertSame($this->notFoundBody(), $bodies[0]);
    }

    // ── 7. NO PII: only the marketing + programme-identity allowlist appears; no personal fields ──────
    public function test_no_pii_in_catalogue_or_detail(): void
    {
        $id = $this->mkProgramme('published', false, 'complete');
        $catalogueRaw = $this->getJson('/api/programmes')->getContent();
        $detail = $this->getJson("/api/programmes/{$id}")->assertOk();

        // NB: bare 'enrolment' is NOT forbidden — `enrolment_closes_on` is a non-PII timeline date; the
        // exact key-allowlist below is the real guarantee. Forbidden = personal / count / capacity fields.
        foreach (['enrolled_count', 'capacity', 'claimed', 'guardian', 'student_id', 'user_id', 'acting_guardian', 'signer'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $catalogueRaw, "catalogue must not leak '{$forbidden}'");
            $this->assertStringNotContainsString($forbidden, $detail->getContent(), "detail must not leak '{$forbidden}'");
        }
        // exact key allowlist on a detail row
        $this->assertEqualsCanonicalizing(
            // KAP-MKT-1: +status (open/closed, derived — no capacity), +banner_url (a public marketing URL, no
            // PII). Still the marketing + programme-identity allowlist; nothing personal / count / capacity.
            ['id', 'code', 'name_en', 'name_tc', 'name_sc', 'phase', 'starts_on', 'enrolment_closes_on', 'tagline', 'category', 'age_range', 'duration', 'brand_color', 'status', 'banner_url'],
            array_keys($detail->json()),
        );
    }

    // ── 8. THROTTLE: the catalogue routes carry the per-IP limiter (like /register/schools) ──────────
    public function test_catalogue_routes_are_throttled(): void
    {
        foreach (['api/programmes', 'api/programmes/{id}'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array('GET', $r->methods(), true));
            $this->assertNotNull($route, "route {$uri} exists");
            $this->assertContains('throttle:catalogue', $route->gatherMiddleware(), "{$uri} is throttled");
        }
    }

    private function notFoundBody(): string
    {
        return json_encode(['message' => 'No such programme']);
    }
}
