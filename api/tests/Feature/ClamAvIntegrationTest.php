<?php

namespace Tests\Feature;

use App\Jobs\ScanUpload;
use App\Models\Upload;
use App\Services\Audit\AuditService;
use App\Services\Uploads\ClamAvScanner;
use App\Services\Uploads\ImageHardener;
use App\Services\Uploads\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Eicar;
use Tests\TestCase;

/**
 * Real-clamd integration: runs whenever the compose clamav service is reachable
 * (host port 3310); skips — visibly, never silently green — when it is not.
 *
 * The full-path tests use the KAP test signature (deploy/clamav/kap-test.ndb,
 * mounted into the compose clamav): a harmless marker that clamd flags, embedded
 * in files that legitimately PASS the MIME allow-list. That proves the layer the
 * EICAR demos cannot: intake accepts → queued scan quarantines.
 */
class ClamAvIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'KAP-CUSTOM-TEST-SIGNATURE-6a8f2c';

    private function scanner(): ClamAvScanner
    {
        $host = config('uploads.clamav.host');
        $port = config('uploads.clamav.port');
        $probe = @stream_socket_client("tcp://{$host}:{$port}", $e, $s, 2);
        if ($probe === false) {
            $this->markTestSkipped("clamd not reachable at {$host}:{$port} — start the compose clamav service");
        }
        fclose($probe);

        return new ClamAvScanner($host, $port);
    }

    private function runScanJob(string $uploadId): void
    {
        (new ScanUpload($uploadId))->handle($this->scanner(), app(AuditService::class), app(ImageHardener::class));
    }

    public function test_real_clamd_flags_eicar(): void
    {
        $signature = $this->scanner()->scan(Eicar::STRING);

        $this->assertNotNull($signature, 'clamd must flag the EICAR test string');
        $this->assertStringContainsStringIgnoringCase('eicar', $signature);
    }

    public function test_real_clamd_passes_clean_content(): void
    {
        $this->assertNull($this->scanner()->scan('KA Playground clean-content scan probe'));
    }

    public function test_valid_pdf_with_marker_passes_intake_and_is_quarantined_by_scan(): void
    {
        $this->scanner(); // reachability gate before touching the DB
        Storage::fake('local');
        Queue::fake();

        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            .'% '.self::MARKER."\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        $tmp = tempnam(sys_get_temp_dir(), 'kap');
        file_put_contents($tmp, $pdf);
        $file = new UploadedFile($tmp, 'evidence.pdf', 'application/pdf', null, true);

        // Layer the EICAR demos could not test: the file PASSES the allow-list…
        $upload = app(UploadService::class)->intake($file, 'document');
        $this->assertSame(Upload::STATUS_PENDING, $upload->status, 'valid PDF must clear intake');
        $this->assertSame('application/pdf', $upload->mime_type);

        // …and only the real scan catches it
        $this->runScanJob($upload->id);
        $upload->refresh();
        $this->assertSame(Upload::STATUS_QUARANTINED, $upload->status);
        $this->assertStringContainsString('KAP.TestSig.Marker', (string) $upload->scan_signature);
        $this->assertFalse($upload->isVisible());
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'upload',
            'entity_id' => $upload->id,
            'action' => 'upload.quarantined',
        ]);
    }

    public function test_valid_jpeg_with_marker_passes_intake_and_is_quarantined_by_scan(): void
    {
        $this->scanner();
        Storage::fake('local');
        Queue::fake();

        $img = imagecreatetruecolor(24, 24);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        // Marker in a COM segment: a valid JPEG that finfo types image/jpeg
        $com = "\xFF\xFE".pack('n', strlen(self::MARKER) + 2).self::MARKER;
        $bytes = substr($bytes, 0, 2).$com.substr($bytes, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'kap');
        file_put_contents($tmp, $bytes);
        $file = new UploadedFile($tmp, 'photo.jpg', 'image/jpeg', null, true);

        $upload = app(UploadService::class)->intake($file, 'image');
        $this->assertSame(Upload::STATUS_PENDING, $upload->status, 'valid JPEG must clear intake');

        // Scan runs on ORIGINAL bytes, so the metadata-borne marker is caught
        // before hardening could erase it
        $this->runScanJob($upload->id);
        $upload->refresh();
        $this->assertSame(Upload::STATUS_QUARANTINED, $upload->status);
        $this->assertStringContainsString('KAP.TestSig.Marker', (string) $upload->scan_signature);
        $this->assertFalse($upload->isVisible());
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'upload',
            'entity_id' => $upload->id,
            'action' => 'upload.quarantined',
        ]);
    }
}
