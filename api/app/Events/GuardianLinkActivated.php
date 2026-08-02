<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * S-FIX-consent-reissue (D1): the ONE explicit signal that a guardian_link reached 'active'.
 * Dispatched from BOTH activation sites (LinkageService::approveLink, LinkController::schoolVouch)
 * after the to_state='active' audit. A single listener (ReissueConsentOnGuardianActivation) issues
 * consent requests to the newly-active guardian for the student's pre-confirm enrolments. No consent
 * semantics are hidden in the OD-24 visibility helper — this event is the seam.
 */
class GuardianLinkActivated
{
    use Dispatchable;

    public function __construct(
        public readonly int $studentId,
        public readonly int $guardianId,
        public readonly string $linkId,
        public readonly string $origin,
        public readonly int $actorId,
    ) {}
}
