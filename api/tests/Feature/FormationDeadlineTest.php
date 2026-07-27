<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FormationDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private User $config;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $this->config->id,
            'capability' => 'configuration', 'granted_by' => $this->config->id, 'granted_at' => now(),
        ]);
        Sanctum::actingAs($this->config);
        $this->programme = Programme::query()->create([
            'code' => 'DLN-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
    }

    private function saveSections(array $basics, array $teamRules): void
    {
        $templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $lang) {
            $vid = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", [
                'language' => $lang, 'body_html' => '<p>x {{signature}}</p>',
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$vid}/publish")->assertOk();
        }
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'basics' => $basics,
                'team_rules' => $teamRules,
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
    }

    public function test_misordered_dates_block_publish(): void
    {
        $this->saveSections(
            ['enrolment_closes_on' => '2026-09-10', 'starts_on' => '2026-09-01'],
            ['formation_deadline_on' => '2026-09-05'],
        );
        $result = $this->postJson("/api/admin/programmes/{$this->programme->id}/pre-flight")->json();
        $this->assertFalse($result['publishable']);
        $this->assertTrue(collect($result['findings'])->pluck('code')->contains('deadline.ordering'));
    }

    public function test_partial_dates_block_publish(): void
    {
        $this->saveSections(['starts_on' => '2026-09-01'], ['x' => 1]);
        $result = $this->postJson("/api/admin/programmes/{$this->programme->id}/pre-flight")->json();
        $this->assertFalse($result['publishable']);
        $this->assertTrue(collect($result['findings'])->pluck('code')->contains('deadline.ordering'));
    }

    public function test_ordered_dates_publish_and_absent_dates_warn_only(): void
    {
        $this->saveSections(
            ['enrolment_closes_on' => '2026-08-20', 'starts_on' => '2026-09-01'],
            ['formation_deadline_on' => '2026-08-27'],
        );
        $result = $this->postJson("/api/admin/programmes/{$this->programme->id}/pre-flight")->json();
        $this->assertTrue($result['publishable']);
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();
    }

    public function test_post_publish_edit_cannot_break_the_ordering(): void
    {
        $this->saveSections(
            ['enrolment_closes_on' => '2026-08-20', 'starts_on' => '2026-09-01'],
            ['formation_deadline_on' => '2026-08-27'],
        );
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();

        // A12/OD-33: moving the deadline past the start is REFUSED post-publish
        $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/team_rules", [
            'status' => 'complete', 'data' => ['formation_deadline_on' => '2026-09-15'],
        ])->assertStatus(422);

        // and a legal move is accepted
        $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/team_rules", [
            'status' => 'complete', 'data' => ['formation_deadline_on' => '2026-08-25'],
        ])->assertOk();
    }
}
