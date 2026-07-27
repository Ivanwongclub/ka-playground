<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class ConsentEvidenceReportTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $auditor;

    private User $guardian;

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
        $this->auditor = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $this->auditor->id,
            'capability' => 'audit_read', 'granted_by' => $this->ops->id, 'granted_at' => now(),
        ]);
        $this->guardian = User::factory()->create(['role' => 'guardian']);
        $this->student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $this->student->id,
            'guardian_id' => $this->guardian->id, 'status' => 'active', 'origin' => 'onboarding',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->ops);
        $this->templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach ([
            'en' => '<p>English terms {{student_name}} {{signature}}</p>',
            'zh-TC' => '<p>繁體條款 {{student_name}} {{signature}}</p>',
            'zh-SC' => '<p>简体条款 {{student_name}} {{signature}}</p>',
        ] as $lang => $body) {
            $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", [
                'language' => $lang, 'body_html' => $body,
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$vid}/publish")->assertOk();
        }
        $this->programme = Programme::query()->create([
            'code' => 'EVD-'.Str::upper(Str::random(4)), 'name_en' => 'Evidence P', 'name_tc' => 'P', 'name_sc' => 'P',
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

    private function signedSignatureId(string $language = 'zh-SC'): string
    {
        $id = $this->issueConsentRequest($this->templateId, $this->programme->id, $this->student->id, $this->guardian->id, $this->ops);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->getJson("/api/consent-requests/{$id}/document?language={$language}")->assertOk();
        $this->postJson("/api/consent-requests/{$id}/scrolled")->assertOk();

        return $this->postJson("/api/consent-requests/{$id}/sign", [
            'affirmed' => true, 'method' => 'drawn', 'strokes' => [[[10, 10], [60, 40]]],
        ])->assertStatus(201)->json('signature_id');
    }

    public function test_report_covers_versions_languages_and_status_lists(): void
    {
        $this->signedSignatureId('zh-SC');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->auditor);
        $report = $this->getJson('/api/reports/consent-evidence')->assertOk()->json();

        $coverage = collect($report['coverage_by_version_and_language']);
        $this->assertSame(1, $coverage->count());
        $this->assertSame('zh-SC', $coverage->first()['language']);
        $this->assertSame(1, (int) $coverage->first()['active']);
        $this->assertCount(0, $report['outstanding']);
        $this->assertSame(0, $report['placeholder_signatures']);

        // and the report is audit_read-gated
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->getJson('/api/reports/consent-evidence')->assertStatus(403);
    }

    public function test_bundle_contents_are_independently_hash_verifiable(): void
    {
        $sigId = $this->signedSignatureId('zh-SC');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->auditor);
        $response = $this->get("/api/reports/consent-evidence/{$sigId}/bundle")->assertOk();
        $zipPath = tempnam(sys_get_temp_dir(), 'bundletest');
        file_put_contents($zipPath, $response->streamedContent() ?? $response->getFile()->getContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertEqualsCanonicalizing(
            ['manifest.json', 'template.html', 'rendered.html', 'consent.pdf', 'audit-events.json', 'README.txt'],
            $names,
        );

        // THE POINT: a third party re-computes every hash from bundle bytes alone
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame($manifest['hashes']['template_sha256']['value'], hash('sha256', (string) $zip->getFromName('template.html')));
        $this->assertSame($manifest['hashes']['rendered_sha256']['value'], hash('sha256', (string) $zip->getFromName('rendered.html')));
        $this->assertSame($manifest['hashes']['pdf_sha256']['value'], hash('sha256', (string) $zip->getFromName('consent.pdf')));
        $this->assertNotSame($manifest['hashes']['template_sha256']['value'], $manifest['hashes']['rendered_sha256']['value']);
        $this->assertSame('zh-SC', $manifest['signature']['language_signed']);
        $this->assertSame('mpdf/mpdf 8.3.1', $manifest['pdf_generator']);
        // the gap is stated in the bundle itself
        $this->assertStringContainsString('NOT VERIFIABLE FROM THIS BUNDLE ALONE', (string) $zip->getFromName('README.txt'));
        $this->assertStringContainsString('RFC-3161', (string) $zip->getFromName('README.txt'));
        $zip->close();

        // guardian cannot export bundles (audit_read only)
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->get("/api/reports/consent-evidence/{$sigId}/bundle")->assertStatus(403);
    }
}
