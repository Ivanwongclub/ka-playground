<?php

namespace Tests\Feature;

use App\Services\Uploads\ClamAvScanner;
use Tests\TestCase;

/**
 * Real-clamd integration: runs whenever the compose clamav service is reachable
 * (host port 3310); skips — visibly, never silently green — when it is not.
 */
class ClamAvIntegrationTest extends TestCase
{
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

    public function test_real_clamd_flags_eicar(): void
    {
        $signature = $this->scanner()->scan(EICAR);

        $this->assertNotNull($signature, 'clamd must flag the EICAR test string');
        $this->assertStringContainsStringIgnoringCase('eicar', $signature);
    }

    public function test_real_clamd_passes_clean_content(): void
    {
        $this->assertNull($this->scanner()->scan('KA Playground clean-content scan probe'));
    }
}
