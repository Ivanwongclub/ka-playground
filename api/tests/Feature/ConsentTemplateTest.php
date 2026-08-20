<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Consent\ConsentTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsentTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $this->config->id,
            'capability' => 'configuration', 'granted_by' => $this->config->id, 'granted_at' => now(),
        ]);
        Sanctum::actingAs($this->config);
    }

    private function template(): string
    {
        return $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->assertStatus(201)->json('id');
    }

    private function publishLanguage(string $templateId, string $language, string $body = '<p>terms {{signature}}</p>'): string
    {
        $versionId = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", [
            'language' => $language, 'body_html' => $body,
        ])->assertStatus(201)->json('version_id');
        $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$versionId}/publish")->assertOk();

        return $versionId;
    }

    public function test_publish_without_signature_anchor_is_blocked(): void
    {
        $t = $this->template();
        $vid = $this->postJson("/api/admin/consent-templates/{$t}/versions", [
            'language' => 'en', 'body_html' => '<p>no anchor here</p>',
        ])->json('version_id');

        $this->postJson("/api/admin/consent-templates/{$t}/versions/{$vid}/publish")
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', fn ($m) => str_contains($m, '{{signature}}'));
    }

    public function test_each_language_carries_its_own_distinct_hash(): void
    {
        $t = $this->template();
        $this->publishLanguage($t, 'en', '<p>English terms {{signature}}</p>');
        $this->publishLanguage($t, 'zh-TC', '<p>繁體條款 {{signature}}</p>');
        $this->publishLanguage($t, 'zh-SC', '<p>简体条款 {{signature}}</p>');

        $hashes = DB::table('consent_template_versions')->where('template_id', $t)
            ->where('status', 'published')->pluck('sha256', 'language');
        $this->assertCount(3, $hashes);
        $this->assertCount(3, $hashes->unique(), 'each language must hash independently (OD-20)');
        $this->assertSame(hash('sha256', '<p>繁體條款 {{signature}}</p>'), $hashes['zh-TC']);
    }

    public function test_published_versions_are_immutable_at_the_database(): void
    {
        $t = $this->template();
        $vid = $this->publishLanguage($t, 'en');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('immutable');
        DB::table('consent_template_versions')->where('id', $vid)->update(['body_html' => 'tampered']);
    }

    public function test_placeholder_seed_publishes_three_flagged_languages(): void
    {
        $t = $this->postJson('/api/admin/consent-templates/placeholder')->assertStatus(201)->json('template_id');

        $rows = DB::table('consent_template_versions')->where('template_id', $t)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertTrue((bool) $row->is_placeholder);
            $this->assertSame('published', $row->status);
            $this->assertStringContainsString('R15', $row->body_html);
            $this->assertNotNull($row->sha256);
        }
        $this->assertStringContainsString('非法律', $rows->firstWhere('language', 'zh-TC')->body_html);
        $this->assertStringContainsString('非法律', $rows->firstWhere('language', 'zh-SC')->body_html);
    }

    // ── OD-20 / OD-20a publish conditions ──

    private function programmeSelecting(string $templateId): Programme
    {
        $programme = Programme::query()->create([
            'code' => 'CNS-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'basics' => ['enrolment_closes_on' => '2027-01-10', 'starts_on' => '2027-02-01'], 'team_rules' => ['formation_deadline_on' => '2027-01-20'], default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }

        return $programme;
    }

    public function test_missing_language_version_blocks_publish(): void
    {
        $t = $this->template();
        $this->publishLanguage($t, 'en'); // TC + SC missing
        $programme = $this->programmeSelecting($t);

        $result = $this->postJson("/api/admin/programmes/{$programme->id}/pre-flight")->json();
        $codes = collect($result['findings'])->pluck('code');
        $this->assertFalse($result['publishable']);
        $this->assertTrue($codes->contains('consent.language_versions_incomplete'));
    }

    public function test_language_drift_blocks_publish_until_parity(): void
    {
        $t = $this->template();
        foreach (ConsentTemplateService::LANGUAGES as $lang) {
            $this->publishLanguage($t, $lang);
        }
        // Material change applied to EN only → v2 EN, v1 TC/SC = drift (OD-20a)
        $this->publishLanguage($t, 'en', '<p>English terms v2 — materially different {{signature}}</p>');
        $programme = $this->programmeSelecting($t);

        $result = $this->postJson("/api/admin/programmes/{$programme->id}/pre-flight")->json();
        $this->assertFalse($result['publishable']);
        $this->assertTrue(collect($result['findings'])->pluck('code')->contains('consent.language_drift'));

        // Bring the other two to parity → publishable
        $this->publishLanguage($t, 'zh-TC', '<p>繁體條款 v2 {{signature}}</p>');
        $this->publishLanguage($t, 'zh-SC', '<p>简体条款 v2 {{signature}}</p>');
        $result = $this->postJson("/api/admin/programmes/{$programme->id}/pre-flight")->json();
        $this->assertTrue($result['publishable']);
    }

    // ── Five-branch isolation (Leo item 4) ──

    public function test_five_branch_isolation_on_template_versions(): void
    {
        $t = $this->template();
        foreach (ConsentTemplateService::LANGUAGES as $lang) {
            $this->publishLanguage($t, $lang);
        }
        // plus one DRAFT row (staff-only visibility)
        $this->postJson("/api/admin/consent-templates/{$t}/versions", [
            'language' => 'en', 'body_html' => '<p>draft v2 {{signature}}</p>',
        ])->assertStatus(201);

        $programme = $this->programmeSelecting($t);
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();

        // [1] academy staff: drafts + published = 4 rows
        $this->assertCount(4, $this->getJson("/api/consent-templates/{$t}/versions")->json('data'));

        // [2] guardian: three published languages, no draft
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $rows = collect($this->getJson("/api/consent-templates/{$t}/versions")->json('data'));
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(['en', 'zh-TC', 'zh-SC'], $rows->pluck('language')->all());
        $this->assertTrue($rows->every(fn ($r) => $r['status'] === 'published'));

        // [3] student: same as guardian
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->assertCount(3, $this->getJson("/api/consent-templates/{$t}/versions")->json('data'));

        // [4] school_admin: same
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'school_admin']));
        $this->assertCount(3, $this->getJson("/api/consent-templates/{$t}/versions")->json('data'));

        // [5] Member: zero
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->assertCount(0, $this->getJson("/api/consent-templates/{$t}/versions")->json('data'));
    }

    public function test_unselected_or_unpublished_programme_hides_versions_from_bound_parties(): void
    {
        $t = $this->template();
        foreach (ConsentTemplateService::LANGUAGES as $lang) {
            $this->publishLanguage($t, $lang);
        }
        //

        // Template exists but NO published programme selects it
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->assertCount(0, $this->getJson("/api/consent-templates/{$t}/versions")->json('data'),
            'versions are readable only through a published programme that selects the template');
    }
}
