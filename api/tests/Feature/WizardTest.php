<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Programmes\WizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WizardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $this->admin->id,
            'capability' => 'configuration', 'granted_by' => $this->admin->id, 'granted_at' => now(),
        ]);
        $this->programme = Programme::query()->create([
            'code' => 'WIZ-2026', 'name_en' => 'Wizard Prog', 'name_tc' => '巫program', 'name_sc' => '巫program',
            'jurisdiction' => 'HK',
        ]);
        Sanctum::actingAs($this->admin);
    }

    private function completeAllSections(): void
    {
        $payloads = [
            'basics' => ['description' => 'x', 'enrolment_closes_on' => '2027-01-10', 'starts_on' => '2027-02-01'],
            'eligibility' => ['min_enrolment' => 10, 'age_min' => 8, 'age_max' => 18],
            'fees' => ['has_fee_items' => true],
            'consent' => ['template_ref' => 'placeholder-s03'],
            'team_rules' => ['min_size' => 3, 'max_size' => 12, 'formation_deadline_on' => '2027-01-20'],
            'role_library' => ['roles' => ['leader']],
            'tracker' => ['stages_configured' => 5],
            'learning' => ['attendance_threshold_pct' => 70],
            'certification' => ['attendance_threshold_pct' => 70],
        ];
        foreach ($payloads as $key => $data) {
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", [
                'status' => 'complete', 'data' => $data,
            ])->assertOk();
        }
    }

    public function test_requires_configuration_capability(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'academy_admin'])); // no capability
        $this->getJson("/api/admin/programmes/{$this->programme->id}/wizard")->assertStatus(403);
    }

    public function test_readiness_counts_required_sections(): void
    {
        $state = $this->getJson("/api/admin/programmes/{$this->programme->id}/wizard")->assertOk()->json();
        $this->assertSame(['complete' => 0, 'required' => 9], $state['readiness']);

        $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/basics", [
            'status' => 'complete', 'data' => ['description' => 'x'],
        ])->assertOk();
        $state = $this->getJson("/api/admin/programmes/{$this->programme->id}/wizard")->json();
        $this->assertSame(1, $state['readiness']['complete']);
        $this->assertDatabaseHas('audit_events', ['action' => 'programme.section_saved']);
    }

    public function test_preflight_blocks_publish_without_consent_template_or_fees(): void
    {
        $result = $this->postJson("/api/admin/programmes/{$this->programme->id}/pre-flight")
            ->assertOk()->json();

        $codes = collect($result['findings'])->pluck('code');
        $this->assertFalse($result['publishable']);
        $this->assertTrue($codes->contains('consent.template_missing'));
        $this->assertTrue($codes->contains('fees.empty'));

        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertStatus(422);
        $this->assertSame('draft', $this->programme->fresh()->status);
    }

    public function test_warning_when_team_max_below_min_enrolment_does_not_block(): void
    {
        $this->completeAllSections();
        $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/team_rules", [
            // the timeline must survive a partial re-save: OD-33 is all-three-or-none, so dropping
            // formation_deadline_on here would raise a deadline.ordering ERROR and block publish.
            'status' => 'complete', 'data' => ['min_size' => 3, 'max_size' => 6, 'formation_deadline_on' => '2027-01-20'],
        ])->assertOk();

        $result = $this->postJson("/api/admin/programmes/{$this->programme->id}/pre-flight")->json();
        $this->assertTrue($result['publishable']); // warnings never block (D4)
        $this->assertTrue(collect($result['findings'])->pluck('code')->contains('team.max_below_min_enrolment'));
    }

    public function test_publish_walks_draft_to_published_with_version_snapshot(): void
    {
        $this->completeAllSections();

        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")
            ->assertOk()->assertJsonPath('status', 'published');

        $this->assertDatabaseHas('programme_versions', ['programme_id' => $this->programme->id, 'version' => 1]);
        $this->assertDatabaseHas('audit_events', ['action' => 'programme.published', 'to_state' => 'published']);
    }

    public function test_locked_sections_reject_edits_once_published_and_audit_the_attempt(): void
    {
        $this->completeAllSections();
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();

        $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/fees", [
            'status' => 'complete', 'data' => ['has_fee_items' => true, 'tampered' => true],
        ])->assertStatus(423);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'programme.locked_field_attempt',
            'entity_id' => (string) $this->programme->id,
        ]);

        // Non-locked sections stay editable (OD-12 thresholds live in learning)
        $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/learning", [
            'status' => 'complete', 'data' => ['attendance_threshold_pct' => 80],
        ])->assertOk();
    }

    public function test_template_clone_returns_to_draft_with_sections(): void
    {
        $this->completeAllSections();
        $templateId = $this->postJson("/api/admin/programmes/{$this->programme->id}/save-as-template")
            ->assertStatus(201)->json('template_id');

        $draftId = $this->postJson("/api/admin/programmes/{$templateId}/create-from-template")
            ->assertStatus(201)->json('programme_id');

        $this->assertDatabaseHas('programmes', ['id' => $draftId, 'status' => 'draft', 'is_template' => false]);
        $this->assertSame(9, DB::table('wizard_sections')->where('programme_id', $draftId)->count());
        $this->assertNotSame($templateId, $draftId);
        app(WizardService::class); // container smoke
    }
}
