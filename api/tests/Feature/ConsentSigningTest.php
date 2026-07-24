<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Consent\ConsentSigningService;
use App\Services\Consent\ConsentTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsentSigningTest extends TestCase
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

        Sanctum::actingAs($this->ops);
        $this->templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach ([
            'en' => '<p>English terms for {{student_name}} in {{programme_name}}, fee {{fee_total}} {{signature}}</p>',
            'zh-TC' => '<p>{{student_name}} 之繁體條款 {{signature}}</p>',
            'zh-SC' => '<p>{{student_name}} 之简体条款 {{signature}}</p>',
        ] as $lang => $body) {
            $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", [
                'language' => $lang, 'body_html' => $body,
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$vid}/publish")->assertOk();
        }
        $this->programme = Programme::query()->create([
            'code' => 'SGN-'.Str::upper(Str::random(4)), 'name_en' => 'Signing P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
        // Published programme selecting the template — the condition under
        // which requests are issuable and bound parties can read the text
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $this->templateId],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();
    }

    private function issue(?User $signer = null, ?User $student = null): string
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $id = $this->postJson('/api/admin/consent-requests', [
            'template_id' => $this->templateId, 'programme_id' => $this->programme->id,
            'student_id' => ($student ?? $this->student)->id, 'signer_id' => ($signer ?? $this->guardian)->id,
            'reason' => 'test issuance pre-S04A',
        ])->assertStatus(201)->json('id');
        $this->app['auth']->forgetGuards();

        return $id;
    }

    private function asSigner(User $signer): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($signer);
    }

    /** @return array{affirmed: bool, method: string, strokes: array<int, array<int, int[]>>} */
    private function validSignaturePayload(): array
    {
        return ['affirmed' => true, 'method' => 'drawn', 'strokes' => [[[10, 10], [40, 22], [90, 15]]]];
    }

    // ── The three server-side gates (Leo requirement 1) ──

    public function test_gate1_direct_sign_without_server_recorded_scroll_is_refused(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk(); // rendered, NOT scrolled

        $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.scroll.0', fn ($m) => str_contains($m, 'no server-recorded scroll-to-end event'));
    }

    public function test_gate2_sign_without_affirmation_is_refused(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();

        $payload = $this->validSignaturePayload();
        unset($payload['affirmed']);
        $this->postJson("/api/consent-requests/{$id}/sign", $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.affirmed.0', fn ($m) => str_contains($m, 'affirmation'));
    }

    public function test_gate3_sign_without_stroke_or_typed_capture_is_refused(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();

        $this->postJson("/api/consent-requests/{$id}/sign", ['affirmed' => true, 'method' => 'drawn', 'strokes' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.signature.0', fn ($m) => str_contains($m, 'No signature capture'));
        // typed with an empty name is equally refused
        $this->postJson("/api/consent-requests/{$id}/sign", ['affirmed' => true, 'method' => 'typed', 'typed_name' => '  '])
            ->assertStatus(422);
    }

    public function test_happy_path_signs_with_dual_hash_and_audit(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $doc = $this->getJson("/api/consent-requests/{$id}/document?language=zh-SC")->assertOk()->json();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();

        $response = $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())
            ->assertStatus(201);

        // BI-6, language-scoped: the stored hash is the SC version's — and dual
        $scHash = DB::table('consent_template_versions')->where('template_id', $this->templateId)
            ->where('language', 'zh-SC')->where('status', 'published')->value('sha256');
        $enHash = DB::table('consent_template_versions')->where('template_id', $this->templateId)
            ->where('language', 'en')->where('status', 'published')->value('sha256');
        $this->assertSame('zh-SC', $response->json('language'));
        $this->assertSame($scHash, $response->json('template_sha256'));
        $this->assertNotSame($enHash, $response->json('template_sha256'));
        $this->assertSame($doc['rendered_sha256'], $response->json('rendered_sha256'));
        $this->assertNotSame($response->json('template_sha256'), $response->json('rendered_sha256'));

        $this->assertSame('signed', DB::table('consent_requests')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'consent_request', 'entity_id' => $id,
            'action' => 'consent_request.signed', 'actor_id' => $this->guardian->id,
        ]);
    }

    // ── Session binding (Leo requirement 2) ──

    public function test_super_admin_cannot_sign_on_a_guardians_behalf(): void
    {
        $id = $this->issue();
        // Warm the gates as the real signer so ONLY the identity check can refuse
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();

        $super = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $super->id,
            'capability' => 'super_admin', 'granted_by' => $super->id, 'granted_at' => now(),
        ]);
        $this->asSigner($super);
        $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())
            ->assertStatus(403); // consent.sign is held by NO capability (S01 defect 1)
        $this->assertDatabaseHas('audit_events', [
            'action' => 'permission.denied', 'actor_id' => $super->id,
        ]);
        $this->assertSame(0, DB::table('consent_signatures')->count());
    }

    public function test_another_guardian_with_consent_sign_cannot_touch_an_unaddressed_request(): void
    {
        $id = $this->issue(); // addressed to $this->guardian
        $this->asSigner($this->coGuardian); // holds consent.sign by role — but is not the addressee
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertStatus(404);
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertStatus(404);
        $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())->assertStatus(404);
        $this->assertSame(0, DB::table('consent_signatures')->count());
    }

    public function test_link_level_deny_override_blocks_signing_for_that_student(): void
    {
        DB::table('guardian_links')->where('guardian_id', $this->guardian->id)
            ->where('student_id', $this->student->id)
            ->update(['permission_overrides' => json_encode(['deny' => ['consent.sign']])]);
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();

        $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())->assertStatus(403);
        $this->assertSame(0, DB::table('consent_signatures')->count());
    }

    // ── Language recorded = language RENDERED (Leo requirement 3) ──

    public function test_recorded_language_is_the_language_rendered_not_any_preference(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        // The guardian first reads TC… then switches to EN and reads to the end
        $this->getJson("/api/consent-requests/{$id}/document?language=zh-TC")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();

        $response = $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())->assertStatus(201);
        $this->assertSame('en', $response->json('language'));
        $enHash = DB::table('consent_template_versions')->where('template_id', $this->templateId)
            ->where('language', 'en')->where('status', 'published')->value('sha256');
        $this->assertSame($enHash, $response->json('template_sha256'));
    }

    public function test_language_switch_invalidates_the_earlier_scroll(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=zh-TC")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        // Switches to EN but does NOT read it to the end
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();

        $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.scroll.0', fn ($m) => str_contains($m, 'language displayed'));
    }

    // ── Dual-hash distinctness (card step 2/3 verification) ──

    public function test_same_version_different_merge_data_changes_rendered_hash_only(): void
    {
        $otherStudent = User::factory()->create(['role' => 'student', 'name' => 'Different Child']);
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $otherStudent->id,
            'guardian_id' => $this->guardian->id, 'status' => 'active', 'origin' => 'onboarding',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = $this->issue();
        $b = $this->issue(student: $otherStudent);

        $this->asSigner($this->guardian);
        $docA = $this->getJson("/api/consent-requests/{$a}/document?language=en")->json();
        $docB = $this->getJson("/api/consent-requests/{$b}/document?language=en")->json();

        $this->assertSame($docA['template_sha256'], $docB['template_sha256'], 'template hash: same version, unchanged');
        $this->assertNotSame($docA['rendered_sha256'], $docB['rendered_sha256'], 'rendered hash: different merge data, different document');
    }

    // ── OD-10 ──

    public function test_consent_satisfied_honours_requires_all_guardians(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())->assertStatus(201);

        $service = app(ConsentSigningService::class);
        // Default any-one (OD-10): one of two guardians signed → satisfied
        $this->assertTrue($service->consentSatisfied($this->programme->id, $this->student->id));

        // Flag on: the co-guardian has not signed → NOT satisfied
        DB::table('wizard_sections')->where('programme_id', $this->programme->id)->where('section_key', 'consent')
            ->update(['data' => json_encode(['template_ref' => $this->templateId, 'requires_all_guardians' => true])]);
        $this->assertFalse($service->consentSatisfied($this->programme->id, $this->student->id));
    }

    // ── Immutability (BI-6 evidence) ──

    public function test_signatures_are_immutable_at_the_database(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        $sigId = $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())->json('signature_id');

        try {
            DB::table('consent_signatures')->where('id', $sigId)->update(['language' => 'en']);
            $this->fail('UPDATE on consent_signatures should be impossible');
        } catch (\Illuminate\Database\QueryException $e) {
            // privilege revoke (42501) fires first for kap_app; the trigger backs it
            $this->assertTrue(
                str_contains($e->getMessage(), 'permission denied') || str_contains($e->getMessage(), 'INSERT-only'),
                $e->getMessage(),
            );
        }
    }

    // ── Five-branch isolation: consent_requests ──

    public function test_five_branch_isolation_on_consent_requests(): void
    {
        $school = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $otherSchool = School::query()->create(['name_en' => 'School B', 'name_tc' => '乙校', 'name_sc' => '乙校']);
        $otherSchoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $otherSchoolAdmin->id, 'school_id' => $otherSchool->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $id = $this->issue();

        // [1] addressed guardian sees it
        $this->asSigner($this->guardian);
        $this->assertCount(1, $this->getJson('/api/consent-requests')->json('data'));
        // [2] the student it concerns sees status
        $this->asSigner($this->student);
        $this->assertCount(1, $this->getJson('/api/consent-requests')->json('data'));
        // [3] school_admin of the student's school sees it (chasing, H4)
        $this->asSigner($schoolAdmin);
        $this->assertCount(1, $this->getJson('/api/consent-requests')->json('data'));
        // [4] other-school admin: zero
        $this->asSigner($otherSchoolAdmin);
        $this->assertCount(0, $this->getJson('/api/consent-requests')->json('data'));
        // [5] Member: zero
        $this->asSigner(User::factory()->create(['role' => 'member']));
        $this->assertCount(0, $this->getJson('/api/consent-requests')->json('data'));
        $this->assertNotEmpty($id);
    }

    // ── Five-branch isolation: consent_signatures (the strictest read set) ──

    public function test_five_branch_isolation_on_consent_signatures(): void
    {
        $school = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $id = $this->issue();
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())->assertStatus(201);

        // [1] the signer sees their own evidence
        $this->assertCount(1, $this->getJson('/api/consent-signatures')->json('data'));
        // [2] CO-GUARDIAN of the SAME student: request status YES, evidence ZERO
        $this->asSigner($this->coGuardian);
        $this->assertCount(0, $this->getJson('/api/consent-signatures')->json('data'));
        // [3] school_admin of the student's school: status yes (requests), evidence ZERO
        $this->asSigner($schoolAdmin);
        $this->assertCount(1, $this->getJson('/api/consent-requests')->json('data'));
        $this->assertCount(0, $this->getJson('/api/consent-signatures')->json('data'));
        // [4] ops/audit-capability admin sees (compliance duty)
        $this->asSigner($this->ops);
        $this->assertCount(1, $this->getJson('/api/consent-signatures')->json('data'));
        // [5] Member: zero
        $this->asSigner(User::factory()->create(['role' => 'member']));
        $this->assertCount(0, $this->getJson('/api/consent-signatures')->json('data'));
    }

    public function test_issuance_refuses_a_signer_who_is_not_an_active_guardian_of_the_student(): void
    {
        $stranger = User::factory()->create(['role' => 'guardian']);
        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/consent-requests', [
            'template_id' => $this->templateId, 'programme_id' => $this->programme->id,
            'student_id' => $this->student->id, 'signer_id' => $stranger->id, 'reason' => 'x-test',
        ])->assertStatus(422)->assertJsonPath('errors.signer.0', fn ($m) => str_contains($m, 'active guardian'));
    }

    // ── Ruling 1: manual issuance names operator + reason ──

    public function test_manual_issuance_without_a_reason_is_refused_and_the_reason_is_audited(): void
    {
        Sanctum::actingAs($this->ops);
        $this->postJson('/api/admin/consent-requests', [
            'template_id' => $this->templateId, 'programme_id' => $this->programme->id,
            'student_id' => $this->student->id, 'signer_id' => $this->guardian->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $id = $this->issue();
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'consent_request', 'entity_id' => $id,
            'action' => 'consent_request.issued', 'actor_id' => $this->ops->id,
            'reason' => 'test issuance pre-S04A',
        ]);
    }

    // ── Ruling 2: derived status — no leak of the other guardian's request ──

    public function test_co_guardian_reads_derived_status_without_any_of_the_other_guardians_data(): void
    {
        $id = $this->issue(); // addressed to guardian A
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())->assertStatus(201);

        // Guardian B: consent MET — and NOTHING else. No row, timestamp or identity.
        $this->asSigner($this->coGuardian);
        $response = $this->getJson("/api/my/students/{$this->student->id}/consent-status?programme_id={$this->programme->id}")
            ->assertOk();
        $this->assertSame([
            'programme_id' => $this->programme->id, 'student_id' => $this->student->id,
            'consent_met' => true, 'requires_all_guardians' => false,
            'your_signature_needed' => false,
        ], $response->json());
        // B's raw view stays empty — the derivation leaked no rows into scope
        $this->assertCount(0, $this->getJson('/api/consent-requests')->json('data'));
        $this->assertCount(0, $this->getJson('/api/consent-signatures')->json('data'));

        // a non-guardian of the student gets 404
        $this->asSigner(User::factory()->create(['role' => 'guardian']));
        $this->getJson("/api/my/students/{$this->student->id}/consent-status?programme_id={$this->programme->id}")
            ->assertStatus(404);
    }

    public function test_derived_status_under_requires_all_guardians_shows_own_signature_needed(): void
    {
        DB::table('wizard_sections')->where('programme_id', $this->programme->id)->where('section_key', 'consent')
            ->update(['data' => json_encode(['template_ref' => $this->templateId, 'requires_all_guardians' => true])]);
        $a = $this->issue();
        $this->issue(signer: $this->coGuardian);
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$a}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$a}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$a}/sign", $this->validSignaturePayload())->assertStatus(201);

        $this->asSigner($this->coGuardian);
        $status = $this->getJson("/api/my/students/{$this->student->id}/consent-status?programme_id={$this->programme->id}")->json();
        $this->assertFalse($status['consent_met']);
        $this->assertTrue($status['requires_all_guardians']);
        $this->assertTrue($status['your_signature_needed'], 'their OWN open request — already visible to them');
    }

    // ── Item 4: void + re-issue on merge-data drift ──

    public function test_void_and_reissue_produces_a_fresh_merge_snapshot_and_audits_the_reason(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $staleDoc = $this->getJson("/api/consent-requests/{$id}/document?language=en")->json();

        // The source record is corrected AFTER issuance — frozen data is now wrong
        DB::table('users')->where('id', $this->student->id)->update(['name' => 'Corrected Name']);

        $this->asSigner($this->ops);
        $result = $this->postJson("/api/admin/consent-requests/{$id}/void", [
            'reason' => 'student name misspelt in frozen merge data', 'reissue' => true,
        ])->assertOk()->json();
        $this->assertSame($id, $result['voided']);
        $this->assertNotNull($result['replacement']);
        $this->assertSame('voided', DB::table('consent_requests')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'consent_request', 'entity_id' => $id, 'action' => 'consent_request.voided',
            'actor_id' => $this->ops->id, 'reason' => 'student name misspelt in frozen merge data',
        ]);

        // The voided request is dead to the signer; the replacement renders FRESH data
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertStatus(409);
        $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())->assertStatus(409);
        $freshDoc = $this->getJson("/api/consent-requests/{$result['replacement']}/document?language=en")->assertOk()->json();
        $this->assertStringContainsString('Corrected Name', $freshDoc['body_html']);
        $this->assertNotSame($staleDoc['rendered_sha256'], $freshDoc['rendered_sha256'], 'fresh snapshot, fresh rendered hash');
        $this->assertSame($staleDoc['template_sha256'], $freshDoc['template_sha256'], 'template unchanged');
    }

    public function test_voiding_a_signed_request_preserves_the_signature_evidence(): void
    {
        $id = $this->issue();
        $this->asSigner($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        $sigId = $this->postJson("/api/consent-requests/{$id}/sign", $this->validSignaturePayload())->json('signature_id');

        $this->asSigner($this->ops);
        $this->postJson("/api/admin/consent-requests/{$id}/void", [
            'reason' => 'signed against corrected-away merge data', 'reissue' => true,
        ])->assertOk();

        $this->assertSame('voided', DB::table('consent_requests')->where('id', $id)->value('status'));
        // The signature is immutable evidence of what WAS signed — untouched
        $this->assertDatabaseHas('consent_signatures', ['id' => $sigId]);
    }
}
