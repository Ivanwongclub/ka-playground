<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Consent\ConsentSigningService;
use App\Services\Enrolments\EnrolmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * S04A STEP 2 — the DESIGN-ANSWER job: enrolment issues consent requests into
 * a table it cannot read, via the queue's structural system context. Input is
 * an enrolment id only; nothing returns to the requester; the signer set
 * derives from guardian_links HERE, never from any payload. Idempotent.
 */
class IssueConsentRequests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $enrolmentId) {}

    public function handle(ConsentSigningService $signing, EnrolmentService $enrolments): void
    {
        $enrolment = DB::table('enrolments')->where('id', $this->enrolmentId)->first();
        if ($enrolment === null || $enrolment->status !== 'submitted') {
            return;
        }
        $templateRef = json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $enrolment->programme_id)->where('section_key', 'consent')
            ->value('data'), true)['template_ref'] ?? null;
        $acting = User::findOrFail($enrolment->acting_guardian_id);

        $guardianIds = DB::table('guardian_links')->where('student_id', $enrolment->student_id)
            ->where('status', 'active')->pluck('guardian_id');
        foreach ($guardianIds as $guardianId) {
            $open = DB::table('consent_requests')
                ->where('template_id', $templateRef)->where('programme_id', $enrolment->programme_id)
                ->where('student_id', $enrolment->student_id)->where('signer_id', $guardianId)
                ->whereIn('status', ['sent', 'viewed', 'signed'])->exists();
            if (! $open) {
                $signing->issueRequest($templateRef, (int) $enrolment->programme_id,
                    (int) $enrolment->student_id, (int) $guardianId, $acting,
                    "consent for enrolment {$this->enrolmentId}");
            }
        }

        $enrolments->transition($this->enrolmentId, 'pending_consent', $acting, 'consent requests issued');
        // fresh-consent-per-cohort means this normally stays pending; the gate
        // decides (a re-run after a signature must not strand the enrolment)
        $enrolments->evaluateConsentGate((int) $enrolment->programme_id, (int) $enrolment->student_id, $acting);
    }
}
