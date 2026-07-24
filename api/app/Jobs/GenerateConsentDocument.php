<?php

namespace App\Jobs;

use App\Services\Consent\ConsentDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * FR038: generate the signed PDF + audit certificate after a signature lands.
 * Runs in the queue's system context (S02A lifecycle); idempotent per signature.
 */
class GenerateConsentDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $signatureId) {}

    public function handle(ConsentDocumentService $documents): void
    {
        $documents->generate($this->signatureId);
    }
}
