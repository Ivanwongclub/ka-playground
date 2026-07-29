<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class EnrolmentTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $guardian;

    private User $coGuardian;

    private User $student;

    private Programme $programme;

    private string $templateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $cap) {
            DB::table('admin_capabilities')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $this->ops->id,
                'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now(),
            ]);
        }
        $this->guardian = User::factory()->create(['role' => 'guardian']);
        $this->coGuardian = User::factory()->create(['role' => 'guardian']);
        $this->student = User::factory()->create(['role' => 'student']);
        foreach ([$this->guardian, $this->coGuardian] as $g) {
            DB::table('guardian_links')->insert([
                'id' => (string) Str::uuid7(), 'student_id' => $this->student->id,
                'guardian_id' => $g->id, 'status' => 'active', 'origin' => 'onboarding',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        [$this->programme, $this->templateId] = $this->publishedProgramme('ENR');
    }

    /** @return array{0: Programme, 1: string} */
    private function publishedProgramme(string $prefix): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach (['en' => 'English terms', 'zh-TC' => '繁體條款', 'zh-SC' => '简体条款'] as $lang => $text) {
            $vid = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", [
                'language' => $lang, 'body_html' => "<p>{$text} {{student_name}} {{signature}}</p>",
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$vid}/publish")->assertOk();
        }
        $programme = Programme::query()->create([
            'code' => $prefix.'-'.Str::upper(Str::random(4)), 'name_en' => 'P '.$prefix, 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();

        return [$programme, $templateId];
    }

    private function enrol(?User $guardian = null, ?Programme $programme = null): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian ?? $this->guardian);

        return $this->postJson('/api/my/enrolments', [
            'programme_id' => ($programme ?? $this->programme)->id, 'student_id' => $this->student->id,
        ])->assertStatus(201)->json();
    }

    private function signAs(User $signer, Programme $programme): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($signer);
        $request = DB::table('consent_requests')->where('programme_id', $programme->id)
            ->where('signer_id', $signer->id)->whereIn('status', ['sent', 'viewed'])->first();
        $this->assertNotNull($request, 'an open request must exist for the signer');
        $this->getJson("/api/consent-requests/{$request->id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$request->id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$request->id}/sign", [
            'affirmed' => true, 'method' => 'typed', 'typed_name' => 'Guardian Test',
        ])->assertStatus(201);
    }

    public function test_enrolment_issues_requests_to_every_guardian_and_carries_no_consent_data(): void
    {
        $response = $this->enrol();

        $this->assertSame('pending_consent', DB::table('enrolments')->where('id', $response['id'])->value('status'),
            'sync queue: issuance job ran inline and moved submitted → pending_consent');
        // one request per ACTIVE guardian, derived server-side (design answer)
        $signers = DB::table('consent_requests')->where('programme_id', $this->programme->id)
            ->where('student_id', $this->student->id)->pluck('signer_id');
        $this->assertEqualsCanonicalizing([$this->guardian->id, $this->coGuardian->id], $signers->all());
        // the response body contains NO consent fields
        $this->assertEqualsCanonicalizing(['id', 'programme_id', 'student_id', 'status'], array_keys($response));
        // audited with the acting guardian (2.22)
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'enrolment', 'entity_id' => $response['id'],
            'action' => 'enrolment.submitted', 'actor_id' => $this->guardian->id,
        ]);
    }

    public function test_signature_opens_the_pool_gate_automatically(): void
    {
        $id = $this->enrol()['id'];
        $this->signAs($this->guardian, $this->programme); // OD-10 any-one

        $this->assertSame('in_pool', DB::table('enrolments')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'enrolment', 'entity_id' => $id, 'action' => 'enrolment.in_pool',
            'actor_id' => $this->guardian->id,
        ]);
    }

    public function test_supersede_pulls_the_enrolment_back_out_of_the_pool(): void
    {
        $id = $this->enrol()['id'];
        $this->signAs($this->guardian, $this->programme);
        $this->assertSame('in_pool', DB::table('enrolments')->where('id', $id)->value('status'));

        // material change to the SIGNED language → supersede → gate re-evaluates
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", [
            'language' => 'en', 'body_html' => '<p>English v2 material {{student_name}} {{signature}}</p>',
        ])->json('version_id');
        $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$vid}/publish", ['material' => true])->assertOk();

        $this->assertSame('pending_consent', DB::table('enrolments')->where('id', $id)->value('status'),
            'the gate must run BACKWARD when consent is unsatisfied (OD-34 integrity)');
    }

    public function test_duplicate_submit_returns_the_original(): void
    {
        $first = $this->enrol();
        $again = $this->enrol();                       // same guardian
        $byCoGuardian = $this->enrol($this->coGuardian); // other guardian, same student

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame($first['id'], $byCoGuardian['id'], 'BI-4: one live enrolment per student × programme');
        $this->assertSame(1, DB::table('enrolments')->count());
    }

    public function test_two_programmes_are_fully_independent(): void
    {
        [$programmeB] = $this->publishedProgramme('IND');
        $a = $this->enrol();
        $b = $this->enrol(programme: $programmeB);

        $this->assertNotSame($a['id'], $b['id']);
        $this->signAs($this->guardian, $this->programme); // sign A only
        $this->assertSame('in_pool', DB::table('enrolments')->where('id', $a['id'])->value('status'));
        $this->assertSame('pending_consent', DB::table('enrolments')->where('id', $b['id'])->value('status'),
            'OD-63: consent in one programme never satisfies another');
    }

    public function test_guardian_cannot_enrol_a_student_they_are_not_linked_to(): void
    {
        $stranger = User::factory()->create(['role' => 'guardian']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($stranger);
        $this->postJson('/api/my/enrolments', [
            'programme_id' => $this->programme->id, 'student_id' => $this->student->id,
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('enrolments')->count());
    }

    public function test_unpublished_programme_refuses_enrolment(): void
    {
        $draft = Programme::query()->create([
            'code' => 'DRF-'.Str::upper(Str::random(4)), 'name_en' => 'Draft', 'name_tc' => 'D', 'name_sc' => 'D',
            'jurisdiction' => 'HK',
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->postJson('/api/my/enrolments', [
            'programme_id' => $draft->id, 'student_id' => $this->student->id,
        ])->assertStatus(422);
    }

    public function test_status_cannot_be_written_outside_the_system_state_machine(): void
    {
        $id = $this->enrol()['id'];
        $scope = app(\App\Services\Authz\ScopeContext::class);
        $scope->set($this->guardian);
        try {
            $updated = DB::table('enrolments')->where('id', $id)->update(['status' => 'active']);
            $this->assertSame(0, $updated, 'guardian-context UPDATE must touch zero rows (enr_update: system only)');
        } finally {
            $scope->setSystem();
        }
        $this->assertSame('pending_consent', DB::table('enrolments')->where('id', $id)->value('status'));
    }

    public function test_five_branch_isolation_on_enrolments(): void
    {
        $school = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $otherSchool = School::query()->create(['name_en' => 'School B', 'name_tc' => '乙校', 'name_sc' => '乙校']);
        $otherAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $otherAdmin->id, 'school_id' => $otherSchool->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->enrol();

        // [1] acting guardian and [2] co-guardian both see the student's enrolment
        foreach ([$this->guardian, $this->coGuardian] as $g) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($g);
            $this->assertCount(1, $this->getJson('/api/enrolments')->json('data'));
        }
        // [3] the student sees their own
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->student);
        $this->assertCount(1, $this->getJson('/api/enrolments')->json('data'));
        // [4] school_admin of the school sees; other-school admin zero
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($schoolAdmin);
        $this->assertCount(1, $this->getJson('/api/enrolments')->json('data'));
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($otherAdmin);
        $this->assertCount(0, $this->getJson('/api/enrolments')->json('data'));
        // [5] an UNRELATED guardian sees zero; Member is denied at the permission gate
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->assertCount(0, $this->getJson('/api/enrolments')->json('data'));
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->getJson('/api/enrolments')->assertStatus(403);
    }
}
