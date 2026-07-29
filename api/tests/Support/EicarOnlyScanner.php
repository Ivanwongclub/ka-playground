<?php

namespace Tests\Support;

use App\Services\Uploads\VirusScanner;

/**
 * Test double for the virus scanner: flags only the EICAR test signature, clean
 * otherwise. Lives in tests/Support (PSR-4 autoloaded via Tests\) so EVERY test
 * class that binds it resolves it regardless of which subset runs — it was
 * previously defined inline in UploadServiceTest.php, which made any run that
 * excluded that file fail with "class EicarOnlyScanner does not exist"
 * (S05/S06 live-audit finding F-1).
 */
class EicarOnlyScanner implements VirusScanner
{
    public function scan(string $contents): ?string
    {
        return str_contains($contents, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')
            ? 'Eicar-Signature'
            : null;
    }
}
