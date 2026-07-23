<?php

namespace Tests\Feature;

use App\Jobs\ScanUpload;
use App\Models\Upload;
use App\Services\Uploads\UploadService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

// EICAR: the industry-standard harmless antivirus test string.
const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

/** Deterministic scanner for pipeline tests: flags EICAR, passes everything else. */
class EicarOnlyScanner implements VirusScanner
{
    public function scan(string $contents): ?string
    {
        return str_contains($contents, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')
            ? 'Eicar-Signature'
            : null;
    }
}

class UploadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
    }

    private function runScanJob(string $uploadId): void
    {
        (new ScanUpload($uploadId))->handle(
            app(VirusScanner::class),
            app(\App\Services\Audit\AuditService::class),
            app(\App\Services\Uploads\ImageHardener::class),
        );
    }

    private function jpeg(string $name = 'photo.jpg'): UploadedFile
    {
        $img = imagecreatetruecolor(24, 24);
        imagefilledrectangle($img, 0, 0, 23, 23, (int) imagecolorallocate($img, 120, 60, 180));
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        // Splice a COM (comment) segment after SOI to simulate embedded metadata/payload
        $marker = 'KAP_PAYLOAD_MARKER_SHOULD_NOT_SURVIVE';
        $com = "\xFF\xFE".pack('n', strlen($marker) + 2).$marker;
        $bytes = substr($bytes, 0, 2).$com.substr($bytes, 2);

        $tmp = tempnam(sys_get_temp_dir(), 'kap');
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    public function test_disallowed_mime_is_rejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kap');
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $file = new UploadedFile($tmp, 'vector.svg', 'image/svg+xml', null, true);

        $this->expectException(ValidationException::class);
        app(UploadService::class)->intake($file, 'image');
    }

    public function test_oversize_file_is_rejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kap');
        file_put_contents($tmp, '%PDF-1.4 '.str_repeat('x', 15 * 1024 * 1024 + 1));
        $file = new UploadedFile($tmp, 'big.pdf', 'application/pdf', null, true);

        $this->expectException(ValidationException::class);
        app(UploadService::class)->intake($file, 'document');
    }

    public function test_unknown_context_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        app(UploadService::class)->intake($this->jpeg(), 'not_a_context');
    }

    public function test_scan_sees_original_bytes_and_clean_image_is_hardened(): void
    {
        Queue::fake();
        $upload = app(UploadService::class)->intake($this->jpeg(), 'image');

        // Pending store holds the ORIGINAL — the scanner must see the payload
        $pending = Storage::disk('local')->get($upload->path);
        $this->assertStringContainsString('KAP_PAYLOAD_MARKER_SHOULD_NOT_SURVIVE', $pending);

        // After a clean verdict the visible file is re-encoded: payload gone
        $this->runScanJob($upload->id);
        $upload->refresh();
        $stored = Storage::disk('local')->get($upload->path);
        $this->assertStringNotContainsString('KAP_PAYLOAD_MARKER_SHOULD_NOT_SURVIVE', $stored);
        $this->assertNotFalse(@imagecreatefromstring($stored), 'hardened file is a valid image');
        $this->assertSame(hash('sha256', $stored), $upload->sha256);
        $this->assertSame(strlen($stored), $upload->size_bytes);
    }

    public function test_undecodable_image_is_quarantined_not_published(): void
    {
        Queue::fake();
        // Valid JPEG magic (finfo says image/jpeg) but undecodable garbage after
        $tmp = tempnam(sys_get_temp_dir(), 'kap');
        file_put_contents($tmp, "\xFF\xD8\xFF\xE0".str_repeat('garbage', 64));
        $file = new UploadedFile($tmp, 'broken.jpg', 'image/jpeg', null, true);

        $upload = app(UploadService::class)->intake($file, 'image');
        $this->runScanJob($upload->id);

        $upload->refresh();
        $this->assertSame(Upload::STATUS_QUARANTINED, $upload->status);
        $this->assertFalse($upload->isVisible());
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'upload',
            'entity_id' => $upload->id,
            'action' => 'upload.quarantined',
        ]);
    }

    public function test_clean_file_is_invisible_until_scan_passes(): void
    {
        Queue::fake();
        $service = app(UploadService::class);
        $upload = $service->intake($this->jpeg(), 'image');

        // Before the scan: pending, invisible, contents refused (BI-10)
        $this->assertSame(Upload::STATUS_PENDING, $upload->status);
        $this->assertFalse($upload->isVisible());
        Queue::assertPushed(ScanUpload::class, fn (ScanUpload $j) => $j->uploadId === $upload->id);
        try {
            $service->contents($upload);
            $this->fail('contents() must refuse a pending upload');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('BI-10', $e->getMessage());
        }

        // Run the scan job: clean → visible, moved out of pending
        $this->runScanJob($upload->id);
        $upload->refresh();
        $this->assertSame(Upload::STATUS_CLEAN, $upload->status);
        $this->assertTrue($upload->isVisible());
        $this->assertStringContainsString('uploads/clean/', $upload->path);
        $this->assertNotEmpty($service->contents($upload));
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'upload',
            'entity_id' => $upload->id,
            'action' => 'upload.scan_passed',
        ]);
    }

    public function test_eicar_is_quarantined_with_audit_event(): void
    {
        Queue::fake();
        $tmp = tempnam(sys_get_temp_dir(), 'kap');
        file_put_contents($tmp, '%PDF-1.4 '.EICAR);
        $file = new UploadedFile($tmp, 'infected.pdf', 'application/pdf', null, true);

        $service = app(UploadService::class);
        $upload = $service->intake($file, 'document');
        $this->runScanJob($upload->id);

        $upload->refresh();
        $this->assertSame(Upload::STATUS_QUARANTINED, $upload->status);
        $this->assertSame('Eicar-Signature', $upload->scan_signature);
        $this->assertFalse($upload->isVisible());
        $this->assertStringContainsString('uploads/quarantine/', $upload->path);
        $this->assertTrue(Storage::disk('local')->exists($upload->path), 'quarantined file retained as evidence');
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'upload',
            'entity_id' => $upload->id,
            'action' => 'upload.quarantined',
            'from_state' => Upload::STATUS_PENDING,
            'to_state' => Upload::STATUS_QUARANTINED,
        ]);

        $this->expectException(RuntimeException::class);
        $service->contents($upload);
    }
}
