<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Enrolments\EnrolmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Re-evaluates the pool gate after any consent event (sign, void, supersede). */
class EvaluateConsentGate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $programmeId,
        public readonly int $studentId,
        public readonly ?int $actorId,
        public readonly string $reason,
    ) {}

    public function handle(EnrolmentService $enrolments): void
    {
        $enrolments->evaluateConsentGate($this->programmeId, $this->studentId,
            $this->actorId !== null ? User::find($this->actorId) : null, $this->reason);
    }
}
