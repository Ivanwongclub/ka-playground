<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Consent\ConsentSigningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * S04A STEP 1 — the void→re-issue path, re-routed through a system-context
 * job now that consent_requests INSERT is system-only. This is the stub the
 * card names; step 2's IssueConsentRequests(enrolmentId) generalises it.
 * The voiding operator stays the audited actor.
 */
class ReissueConsentRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $voidedRequestId,
        public readonly string $reason,
        public readonly int $actorId,
    ) {}

    public function handle(ConsentSigningService $signing): void
    {
        $voided = DB::table('consent_requests')->where('id', $this->voidedRequestId)->first();
        if ($voided === null || $voided->status !== 'voided') {
            return; // idempotent: nothing to re-issue
        }
        $already = DB::table('consent_requests')
            ->where('template_id', $voided->template_id)->where('programme_id', $voided->programme_id)
            ->where('student_id', $voided->student_id)->where('signer_id', $voided->signer_id)
            ->whereIn('status', ['sent', 'viewed'])->exists();
        if ($already) {
            return;
        }
        $signing->issueRequest(
            $voided->template_id, (int) $voided->programme_id,
            (int) $voided->student_id, (int) $voided->signer_id,
            User::findOrFail($this->actorId), $this->reason,
        );
    }
}
