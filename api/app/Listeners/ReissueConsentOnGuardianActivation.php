<?php

namespace App\Listeners;

use App\Events\GuardianLinkActivated;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Consent\ConsentSigningService;
use App\Services\Enrolments\EnrolmentService;
use Illuminate\Support\Facades\DB;

/**
 * S-FIX-consent-reissue (D1, synchronous). A newly-active guardian must receive a consent request for
 * the student's PRE-CONFIRM enrolments — else `consent.issuance_completeness` reds and, on
 * requires_all programmes, consent can never complete (dead loop / Team Formation block). Reuses the existing
 * atomic issuance (ConsentSigningService::issueRequest) and the gate re-evaluation
 * (EnrolmentService::evaluateConsentGate). Idempotent; runs in its own system context.
 */
class ReissueConsentOnGuardianActivation
{
    /** D2: pre-confirm, non-terminal states where a new guardian's consent is still live. */
    private const CONSENT_LIVE = ['submitted', 'pending_consent', 'in_pool', 'teamed'];

    public function __construct(
        private readonly ScopeContext $scope,
        private readonly ConsentSigningService $signing,
        private readonly EnrolmentService $enrolments,
    ) {}

    public function handle(GuardianLinkActivated $event): void
    {
        $actor = User::find($event->actorId);
        if ($actor === null) {
            return;
        }

        $this->scope->asSystem(
            'Consent re-issuance on guardian activation (S-FIX-consent-reissue): a newly-active guardian must receive a consent request for the student\'s pre-confirm enrolments (submitted/pending_consent/in_pool/teamed). The enrolments and the just-activated relationship are outside the activating actor\'s derived scope; issuance and gate re-evaluation are system-context by construction. Idempotent (never a duplicate open/signed request per programme+student+signer). D3 reopen: evaluateConsentGate regresses a requires_all in_pool enrolment to pending_consent until the new guardian signs; non-requires_all stays satisfied.',
            function () use ($event, $actor): void {
                $enrolments = DB::table('enrolments')
                    ->where('student_id', $event->studentId)
                    ->whereIn('status', self::CONSENT_LIVE)
                    ->get(['id', 'programme_id']);

                $programmes = [];
                foreach ($enrolments as $enrolment) {
                    $templateRef = json_decode((string) DB::table('wizard_sections')
                        ->where('programme_id', $enrolment->programme_id)
                        ->where('section_key', 'consent')
                        ->value('data'), true)['template_ref'] ?? null;
                    if ($templateRef === null) {
                        continue;
                    }

                    // Idempotency: the identical guard IssueConsentRequests uses — one open/signed
                    // request per (template, programme, student, signer). A re-dispatch is a no-op.
                    $alreadyOpen = DB::table('consent_requests')
                        ->where('template_id', $templateRef)
                        ->where('programme_id', $enrolment->programme_id)
                        ->where('student_id', $event->studentId)
                        ->where('signer_id', $event->guardianId)
                        ->whereIn('status', ['sent', 'viewed', 'signed'])
                        ->exists();
                    if (! $alreadyOpen) {
                        $this->signing->issueRequest(
                            $templateRef, (int) $enrolment->programme_id, $event->studentId, $event->guardianId,
                            $actor, "consent re-issue: guardian {$event->guardianId} activated (link {$event->linkId})",
                        );
                    }
                    $programmes[(int) $enrolment->programme_id] = true;
                }

                // D3 reopen: re-evaluate the gate per programme (self-guards to pending_consent/in_pool).
                foreach (array_keys($programmes) as $programmeId) {
                    $this->enrolments->evaluateConsentGate($programmeId, $event->studentId, $actor, 'guardian added — consent gate re-evaluated');
                }
            },
        );
    }
}
