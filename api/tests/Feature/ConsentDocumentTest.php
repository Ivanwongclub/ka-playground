<?php

namespace Tests\Feature;

use App\Jobs\GenerateConsentDocument;
use App\Models\Programme;
use App\Models\User;
use App\Services\Consent\ConsentDocumentService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsentDocumentTest extends TestCase
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
        $this->student = User::factory()->create(['role' => 'student', 'name' => 'Wing Yan Chan']);
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
            'en' => '<p>English terms for {{student_name}}. {{signature}} {{signature_date}}</p>',
            'zh-TC' => '<p>{{student_name}} 之繁體條款,家長簽署如下。{{signature}} {{signature_date}}</p>',
            'zh-SC' => '<p>{{student_name}} 之简体条款。{{signature}} {{signature_date}}</p>',
        ] as $lang => $body) {
            $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", [
                'language' => $lang, 'body_html' => $body,
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$vid}/publish")->assertOk();
        }
        $this->programme = Programme::query()->create([
            'code' => 'PDF-'.Str::upper(Str::random(4)), 'name_en' => 'PDF P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
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

    private function signedRequest(string $language = 'en'): array
    {
        $id = $this->issueConsentRequest($this->templateId, $this->programme->id, $this->student->id, $this->guardian->id, $this->ops);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language={$language}")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();
        $sigId = $this->postJson("/api/consent-requests/{$id}/sign", [
            'affirmed' => true, 'method' => 'drawn', 'strokes' => [[[10, 10], [40, 22], [90, 15]]],
        ])->assertStatus(201)->json('signature_id');

        return [$id, $sigId];
    }

    public function test_signing_generates_a_self_contained_document_with_recorded_generator(): void
    {
        [, $sigId] = $this->signedRequest();

        $doc = DB::table('consent_documents')->where('signature_id', $sigId)->first();
        $this->assertNotNull($doc, 'sync queue should have generated the document inline');
        $this->assertSame('mpdf/mpdf 8.3.1', $doc->generator);

        $bytes = app(ConsentDocumentService::class)->download($doc);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame($doc->pdf_sha256, hash('sha256', $bytes), 'stored hash matches served bytes');
        // Leo S03-3 review, option (b): the document makes NO conformance claim
        // a free validator could disprove — no pdfaid XMP block. Fonts still
        // embedded (self-contained); real PDF/A is the S10 decision.
        $this->assertStringNotContainsString('pdfaid', $bytes, 'must not claim a standard it fails');
        $this->assertStringContainsString('FontFile2', $bytes, 'fonts embedded — self-contained');
    }

    public function test_cjk_document_embeds_the_sun_exta_subset(): void
    {
        [, $sigId] = $this->signedRequest('zh-TC');
        $doc = DB::table('consent_documents')->where('signature_id', $sigId)->first();
        $bytes = app(ConsentDocumentService::class)->download($doc);

        $this->assertSame('zh-TC', $doc->language);
        $this->assertStringContainsString('Sun-ExtA', $bytes, 'CJK font must be the embedded Sun-ExtA, not Adobe CJK');
        $this->assertStringContainsString('FontFile2', $bytes, 'TrueType font program must be EMBEDDED');
        $this->assertStringNotContainsString('UniCNS-UCS2', $bytes, 'non-embedded Adobe CJK CMaps must not appear');
    }

    // ── Leo condition 1: hostile merge values ──

    public function test_markup_and_file_reference_in_merge_values_render_as_literal_text_and_read_nothing(): void
    {
        $canary = tempnam(sys_get_temp_dir(), 'canary');
        file_put_contents($canary, 'CANARY-9f3a7e2d-DO-NOT-EMBED');
        $this->student->update(['name' => "<img src='file://{$canary}'>@import url(file://{$canary}); Chan"]);

        [, $sigId] = $this->signedRequest();
        $doc = DB::table('consent_documents')->where('signature_id', $sigId)->first();
        $this->assertNotNull($doc, 'generation must succeed with hostile merge values');
        $bytes = app(ConsentDocumentService::class)->download($doc);

        $this->assertStringNotContainsString('CANARY-9f3a7e2d-DO-NOT-EMBED', $bytes, 'the file must never be read');
        // and the value reached the HTML as escaped literal text
        $signature = DB::table('consent_signatures')->where('id', $sigId)->first();
        $request = DB::table('consent_requests')->where('id', $signature->request_id)->first();
        $version = DB::table('consent_template_versions')->where('id', $signature->template_version_id)->first();
        $html = app(\App\Services\Consent\ConsentSigningService::class)->renderBody($request, $version->body_html);
        $this->assertStringContainsString('&lt;img', $html, 'markup is escaped, not parsed');
        $this->assertStringNotContainsString("<img src='file://", $html);
    }

    public function test_mpdf_refuses_stream_wrapper_fetches_even_for_raw_markup(): void
    {
        $canary = tempnam(sys_get_temp_dir(), 'canary2');
        file_put_contents($canary, str_repeat('CANARY-BYTES-77e1', 64));

        // Simulate escaping AND sanitising both having failed: raw hostile markup
        try {
            $bytes = app(ConsentDocumentService::class)->renderPdf(
                "<p>doc</p><img src=\"file://{$canary}\">", '<p>cert</p>', 'en',
            );
            $this->assertStringNotContainsString('CANARY-BYTES-77e1', $bytes, 'file:// must not be fetched');
        } catch (\Mpdf\MpdfException $e) {
            $this->assertTrue(true, 'blocked outright: '.$e->getMessage());
        }
    }

    public function test_sanitizer_strips_asset_fetching_markup(): void
    {
        $service = app(ConsentDocumentService::class);
        $dirty = '<p ok>text</p><img src="x"><IFRAME src="y"></iframe><style>@import url(evil)</style>'
            .'<p style="background:url(file:///x)">styled</p>';
        $clean = $service->sanitizeForPdf($dirty);
        $this->assertStringNotContainsStringIgnoringCase('<img', $clean);
        $this->assertStringNotContainsStringIgnoringCase('<iframe', $clean);
        $this->assertStringNotContainsStringIgnoringCase('<style', $clean);
        $this->assertStringNotContainsStringIgnoringCase('@import', $clean);
        $this->assertStringNotContainsString('url(file', $clean);
        $this->assertStringContainsString('text', $clean);
    }

    // ── BI-6 discipline at generation time ──

    public function test_generation_refuses_when_rerender_no_longer_matches_the_signed_hash(): void
    {
        Bus::fake([GenerateConsentDocument::class]);
        [, $sigId] = $this->signedRequest();
        Bus::assertDispatched(GenerateConsentDocument::class);

        // Merge data drifts AFTER signing (the void path exists for this — but
        // generation must also refuse on its own)
        $signature = DB::table('consent_signatures')->where('id', $sigId)->first();
        DB::table('consent_requests')->where('id', $signature->request_id)
            ->update(['merge_data' => json_encode(['student_name' => 'Someone Else'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Re-render hash mismatch');
        app(ConsentDocumentService::class)->generate($sigId);
    }

    // ── BI-10: invisible until scanned ──

    public function test_download_refuses_until_the_scan_has_passed(): void
    {
        [, $sigId] = $this->signedRequest();
        $doc = DB::table('consent_documents')->where('signature_id', $sigId)->first();
        DB::table('uploads')->where('id', $doc->pdf_upload_id)->update(['status' => 'pending']);

        Sanctum::actingAs($this->guardian);
        $this->getJson("/api/consent-documents/{$doc->id}/download")->assertStatus(409);
    }

    // ── Five-branch isolation + downloads ──

    public function test_five_branch_isolation_on_consent_documents(): void
    {
        $school = \App\Models\School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        [, $sigId] = $this->signedRequest();
        $docId = DB::table('consent_documents')->where('signature_id', $sigId)->value('id');

        // [1] signer: sees + downloads their own signed copy
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->assertCount(1, $this->getJson('/api/consent-documents')->json('data'));
        $this->assertStringStartsWith('%PDF', $this->getJson("/api/consent-documents/{$docId}/download")->assertOk()->getContent());
        // [2] co-guardian of the SAME student: zero, download 404
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->coGuardian);
        $this->assertCount(0, $this->getJson('/api/consent-documents')->json('data'));
        $this->getJson("/api/consent-documents/{$docId}/download")->assertStatus(404);
        // [3] school_admin: zero
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($schoolAdmin);
        $this->assertCount(0, $this->getJson('/api/consent-documents')->json('data'));
        // [4] ops/audit admin: sees (compliance)
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->assertCount(1, $this->getJson('/api/consent-documents')->json('data'));
        // [5] Member: zero
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->assertCount(0, $this->getJson('/api/consent-documents')->json('data'));
    }

    public function test_consent_documents_rows_are_immutable_at_the_database(): void
    {
        [, $sigId] = $this->signedRequest();
        $docId = DB::table('consent_documents')->where('signature_id', $sigId)->value('id');

        try {
            DB::table('consent_documents')->where('id', $docId)->update(['language' => 'en']);
            $this->fail('UPDATE on consent_documents should be impossible');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'permission denied') || str_contains($e->getMessage(), 'INSERT-only'),
                $e->getMessage(),
            );
        }
    }
}
