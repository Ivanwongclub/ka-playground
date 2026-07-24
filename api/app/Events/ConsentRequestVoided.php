<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an issued consent request is voided (merge-data correction path).
 * S09's notification ladder consumes this to tell the signer the document
 * changed; no channel is built here (card non-scope).
 */
class ConsentRequestVoided
{
    use Dispatchable;

    public function __construct(
        public readonly string $requestId,
        public readonly int $signerId,
        public readonly string $reason,
        public readonly ?string $replacementRequestId,
    ) {}
}
