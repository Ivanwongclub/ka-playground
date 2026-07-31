<?php

namespace Tests\Support;

/**
 * The industry-standard EICAR antivirus test string, as an autoloaded class
 * constant (PSR-4 via Tests\) so EVERY test that needs it resolves it regardless
 * of which subset runs. It was previously a file-scoped `const EICAR` in
 * UploadServiceTest.php, which made ClamAvIntegrationTest fail in isolation with
 * "Undefined constant Tests\Feature\EICAR" (the tail of the S05/S06 F-1 finding —
 * the scanner double [[EicarOnlyScanner]] was extracted in cff895a, this constant
 * was not).
 */
final class Eicar
{
    public const STRING = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
}
