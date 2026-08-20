<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Reconciliation\Assertions\ConsentHashIntegrityAssertion;
use App\Services\Reconciliation\Assertions\ConsentLanguageCompletenessAssertion;
use App\Services\Reconciliation\Assertions\SupersededVersionReconsentAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class ConsentReconsentTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $guardian;

    private User $studentA;

    private User $studentB;

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
        $this->studentA = User::factory()->create(['role' => 'student', 'name' => 'Student A']);
        $this->studentB = User::factory()->create(['role' => 'student', 'name' => 'Student B']);
        foreach ([$this->studentA, $this->studentB] as $student) {
            DB::table('guardian_links')->insert([
                'id' => (string) Str::uuid7(), 'student_id' => $student->id,
                'guardian_id' => $this->guardian->id, 'status' => 'active', 'origin' => 'onboarding',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($this->ops);
        $this->templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach ([
            'en' => '<p>English v1 {{student_name}} {{signature}}</p>',
            'zh-TC' => '<p>繁體 v1 {{student_name}} {{signature}}</p>',
            'zh-SC' => '<p>简体 v1 {{student_name}} {{signature}}</p>',
        ] as $lang => $body) {
            $this->publishLanguage($lang, $body);
        }
        $this->programme = Programme::query()->create([
            'code' => 'RCN-'.Str::upper(Str::random(4)), 'name_en' => 'Reconsent P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $this->templateId],
                'basics' => ['enrolment_closes_on' => '2027-01-10', 'starts_on' => '2027-02-01'], 'team_rules' => ['formation_deadline_on' => '2027-01-20'], default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();
    }

    private function publishLanguage(string $language, string $body, bool $material = false): string
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", [
            'language' => $language, 'body_html' => $body,
        ])->assertStatus(201)->json('version_id');
        $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$vid}/publish", [
            'material' => $material,
        ])->assertOk();

        return $vid;
    }

    private function signedRequest(User $student, string $language): string
    {
        $id = $this->issueConsentRequest($this->templateId, $this->programme->id, $student->id, $this->guardian->id, $this->ops);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language={$language}")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/sign", [
            'affirmed' => true, 'method' => 'typed', 'typed_name' => 'Guardian Test',
        ])->assertStatus(201);

        return $id;
    }

    // ── OD-20a: language-aware re-consent ──

    public function test_material_tc_change_supersedes_tc_signatures_only_and_issues_fresh_requests(): void
    {
        $tcRequest = $this->signedRequest($this->studentA, 'zh-TC');
        $enRequest = $this->signedRequest($this->studentB, 'en');

        $this->publishLanguage('zh-TC', '<p>繁體 v2 — 重大變更 {{student_name}} {{signature}}</p>', material: true);

        // TC-signed request superseded; EN-signed request STANDS (OD-20a)
        $this->assertSame('superseded', DB::table('consent_requests')->where('id', $tcRequest)->value('status'));
        $this->assertSame('signed', DB::table('consent_requests')->where('id', $enRequest)->value('status'));

        // fresh open request for the SAME signer/student/programme, fresh snapshot
        $fresh = DB::table('consent_requests')
            ->where('student_id', $this->studentA->id)->where('signer_id', $this->guardian->id)
            ->where('status', 'sent')->first();
        $this->assertNotNull($fresh, 'a fresh re-consent request must be issued');
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'consent_request', 'entity_id' => $tcRequest,
            'action' => 'consent_request.superseded', 'actor_id' => $this->ops->id,
        ]);
        // the signature evidence rows are untouched
        $this->assertSame(2, DB::table('consent_signatures')->count());
    }

    public function test_non_material_publish_supersedes_nothing(): void
    {
        $tcRequest = $this->signedRequest($this->studentA, 'zh-TC');
        $this->publishLanguage('zh-TC', '<p>繁體 v2 — 錯字修正 {{student_name}} {{signature}}</p>', material: false);

        $this->assertSame('signed', DB::table('consent_requests')->where('id', $tcRequest)->value('status'));
        $this->assertSame(1, DB::table('consent_requests')->count(), 'no fresh request issued');
    }

    // ── FR037 staleness guard ──

    public function test_signature_cannot_land_on_a_version_that_is_no_longer_current(): void
    {
        $id = $this->issueConsentRequest($this->templateId, $this->programme->id, $this->studentA->id, $this->guardian->id, $this->ops);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk(); // reads v1
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();

        // v2 publishes AFTER the guardian read v1 — the v1 row still exists,
        // still published, still hash-valid. The signature must be refused.
        $this->publishLanguage('en', '<p>English v2 {{student_name}} {{signature}}</p>');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->postJson("/api/consent-requests/{$id}/sign", [
            'affirmed' => true, 'method' => 'typed', 'typed_name' => 'Guardian Test',
        ])->assertStatus(409);

        // re-reading serves v2; then signing succeeds and records v2's hash
        $doc = $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertOk()->json();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        $response = $this->postJson("/api/consent-requests/{$id}/sign", [
            'affirmed' => true, 'method' => 'typed', 'typed_name' => 'Guardian Test',
        ])->assertStatus(201);
        $this->assertSame($doc['template_sha256'], $response->json('template_sha256'));
    }

    // ── FR037 decline ──

    public function test_decline_is_terminal_reasoned_and_audited(): void
    {
        $id = $this->issueConsentRequest($this->templateId, $this->programme->id, $this->studentA->id, $this->guardian->id, $this->ops);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        // reason is REQUIRED
        $this->postJson("/api/consent-requests/{$id}/decline", [])->assertStatus(422);
        $this->postJson("/api/consent-requests/{$id}/decline", [
            'reason' => 'We do not agree to the photography clause',
        ])->assertOk();

        $this->assertSame('declined', DB::table('consent_requests')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'consent_request', 'entity_id' => $id, 'action' => 'consent_request.declined',
            'actor_id' => $this->guardian->id, 'reason' => 'We do not agree to the photography clause',
        ]);
        // terminal: nothing further is possible
        $this->getJson("/api/consent-requests/{$id}/document?language=en")->assertStatus(409);
        $this->postJson("/api/consent-requests/{$id}/sign", [
            'affirmed' => true, 'method' => 'typed', 'typed_name' => 'G',
        ])->assertStatus(409);
        $this->postJson("/api/consent-requests/{$id}/decline", ['reason' => 'again?'])->assertStatus(409);
        // and it does not satisfy consent
        $this->assertFalse(app(\App\Services\Consent\ConsentSigningService::class)
            ->consentSatisfied($this->programme->id, $this->studentA->id));
    }

    public function test_non_addressee_cannot_decline(): void
    {
        $id = $this->issueConsentRequest($this->templateId, $this->programme->id, $this->studentA->id, $this->guardian->id, $this->ops);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->postJson("/api/consent-requests/{$id}/decline", ['reason' => 'not my request'])->assertStatus(404);
    }

    // ── The three S03 assertions ──

    public function test_s03_assertions_pass_on_healthy_state_and_fail_on_violations(): void
    {
        $this->signedRequest($this->studentA, 'zh-TC');

        $this->assertTrue((new ConsentHashIntegrityAssertion)->check()->passed);
        $this->assertTrue((new ConsentLanguageCompletenessAssertion)->check()->passed);
        $this->assertTrue((new SupersededVersionReconsentAssertion)->check()->passed);

        // Violation: material TC v2 publishes and the fan-out is "missed" —
        // simulate by re-flipping the superseded request and removing the fresh
        // one (system context can; the nightly must catch exactly this)
        $this->publishLanguage('zh-TC', '<p>繁體 v2 {{student_name}} {{signature}}</p>', material: true);
        DB::table('consent_requests')->where('status', 'sent')->delete();
        DB::table('consent_requests')->where('status', 'superseded')->update(['status' => 'signed']);

        $result = (new SupersededVersionReconsentAssertion)->check();
        $this->assertFalse($result->passed, 'a signed request on a materially superseded version with no open re-consent must fail');
        $this->assertStringContainsString('NO open re-consent', $result->details);
    }
}
