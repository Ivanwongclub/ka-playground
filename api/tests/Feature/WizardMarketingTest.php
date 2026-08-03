<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Reconciliation\Assertions\PublishedProgrammeCompletenessAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-MARKETPLACE-A STEP 1 (Option B — decouple). Marketing is an OPTIONAL wizard section that gates the
 * PUBLIC STOREFRONT, not publish. Proves: the storefront-completeness definition (trilingual save
 * rejection + post-publish non-degradability), the grandfathered reconcile extension (present ⇒ complete;
 * absent ⇒ grandfathered), and — critically — that publish() is UNAFFECTED (a programme with no marketing
 * still publishes cleanly).
 */
class WizardMarketingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Programme $programme;

    /** a fully-complete trilingual marketing payload */
    private const MK = [
        'tagline' => ['en' => 'Build robots', 'tc' => '製造機械人', 'sc' => '制造机械人'],
        'category' => ['en' => 'STEM', 'tc' => '理工', 'sc' => '理工'],
        'age_range' => ['en' => '8–12', 'tc' => '8至12歲', 'sc' => '8至12岁'],
        'duration' => ['en' => '10 weeks', 'tc' => '10週', 'sc' => '10周'],
        'brand_color' => '#7A3B57',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->admin->id, 'capability' => 'configuration', 'granted_by' => $this->admin->id, 'granted_at' => now()]);
        $this->programme = Programme::query()->create(['code' => 'MK-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        Sanctum::actingAs($this->admin);
    }

    private function completeAllSections(): void
    {
        $payloads = [
            'basics' => ['description' => 'x'], 'eligibility' => ['min_enrolment' => 10, 'age_min' => 8, 'age_max' => 18],
            'fees' => ['has_fee_items' => true], 'consent' => ['template_ref' => 'placeholder-s03'],
            'team_rules' => ['min_size' => 3, 'max_size' => 12], 'role_library' => ['roles' => ['leader']],
            'tracker' => ['stages_configured' => 5], 'learning' => ['attendance_threshold_pct' => 70],
            'certification' => ['attendance_threshold_pct' => 70],
        ];
        foreach ($payloads as $key => $data) {
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
    }

    private function saveMarketing(string $status, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/marketing", ['status' => $status, 'data' => $data]);
    }

    // ── TEST 4: publish() is UNAFFECTED (the decoupling) — no marketing, still publishes ────────────
    public function test_publish_is_unaffected_by_the_optional_marketing_section(): void
    {
        $this->completeAllSections(); // NO marketing
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")
            ->assertOk()->assertJsonPath('status', 'published');
        // and preFlight never raises a marketing finding
        $codes = collect($this->postJson("/api/admin/programmes/{$this->programme->id}/pre-flight")->json('findings') ?? [])->pluck('code');
        $this->assertFalse($codes->contains('section.marketing.incomplete'));
    }

    // ── TEST 2: saveSection trilingual rejection ────────────────────────────────────────────────────
    public function test_complete_marketing_save_missing_a_language_is_rejected(): void
    {
        $bad = self::MK;
        unset($bad['tagline']['sc']); // drop 简
        $res = $this->saveMarketing('complete', $bad);
        $res->assertStatus(422);
        $this->assertStringContainsString('marketing.language_incomplete', $res->getContent());
        $this->assertStringContainsString('tagline.sc', $res->getContent());
        // a bad brand_color is also a gap
        $badBrand = self::MK;
        $badBrand['brand_color'] = 'purple';
        $this->assertStringContainsString('brand_color', $this->saveMarketing('complete', $badBrand)->assertStatus(422)->getContent());
        // an INCOMPLETE-status save may carry gaps (drafting is fine)
        $this->saveMarketing('incomplete', $bad)->assertOk();
        // a fully trilingual complete save is accepted
        $this->saveMarketing('complete', self::MK)->assertOk();
    }

    // ── TEST 3: post-publish re-validation — editable but not degradable ────────────────────────────
    public function test_published_marketing_cannot_be_edited_into_incomplete(): void
    {
        $this->completeAllSections();
        $this->saveMarketing('complete', self::MK)->assertOk();
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();

        // editing published marketing into a language gap → rejected
        $gappy = self::MK;
        unset($gappy['duration']['tc']);
        $this->saveMarketing('complete', $gappy)->assertStatus(422);
        // downgrading a published programme's marketing to incomplete → rejected
        $this->saveMarketing('incomplete', self::MK)->assertStatus(422);
        // a valid trilingual copy edit → still allowed (editable)
        $edit = self::MK;
        $edit['tagline'] = ['en' => 'New copy', 'tc' => '新文案', 'sc' => '新文案'];
        $this->saveMarketing('complete', $edit)->assertOk();
    }

    // ── TEST 1: grandfather red-green (the reconcile extension) ─────────────────────────────────────
    public function test_grandfather_no_marketing_passes_present_incomplete_fails(): void
    {
        // a consent+fee-complete PUBLISHED programme, so the ONLY variable is marketing
        $pid = $this->sys(function () {
            $pid = DB::table('programmes')->insertGetId(['code' => 'GF-'.Str::random(5), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'is_template' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('wizard_sections')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $pid, 'section_key' => 'consent', 'status' => 'complete', 'data' => json_encode(['template_ref' => 'placeholder-s03']), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fee_items')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $pid, 'name_en' => 'F', 'name_tc' => 'F', 'name_sc' => 'F', 'amount_minor' => 250000, 'currency' => 'HKD', 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);

            return $pid;
        });

        $assertion = new PublishedProgrammeCompletenessAssertion();

        // (a) NO marketing row → GRANDFATHERED → PASS
        $pass = $this->sys(fn () => $assertion->check());
        $this->assertTrue($pass->passed, 'a published programme with no marketing row is grandfathered: '.$pass->details);

        // (b) a PRESENT-but-incomplete marketing row → FAIL (catches a half-filled/tampered row)
        $this->sys(fn () => DB::table('wizard_sections')->insert([
            'id' => (string) Str::uuid7(), 'programme_id' => $pid, 'section_key' => 'marketing', 'status' => 'complete',
            'data' => json_encode(['tagline' => ['en' => 'x', 'tc' => 'x'], 'category' => ['en' => 'x', 'tc' => 'x', 'sc' => 'x'], 'age_range' => ['en' => 'x', 'tc' => 'x', 'sc' => 'x'], 'duration' => ['en' => 'x', 'tc' => 'x', 'sc' => 'x'], 'brand_color' => '#123456']),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $fail = $this->sys(fn () => $assertion->check());
        $this->assertFalse($fail->passed, 'a present-but-incomplete marketing row must fail');
        $this->assertStringContainsString('marketing present but incomplete', $fail->details);
        $this->assertStringContainsString('tagline.sc', $fail->details);
    }

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->setSystem();
        }
    }
}
