<?php

namespace App\Services\Enrolments;

use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;

/**
 * S06-1 (R3) — enrolment activation. "Active" = the programme is running, NOT
 * 成團 and NOT payment (school-settled activate on invoice, so activation is
 * payment-decoupled). A SYSTEM-actor scheduled job moves `confirmed → active`
 * for every confirmed enrolment whose programme has STARTED (basics.starts_on ≤
 * now). A late-joiner (成團 after the start date) is already confirmed, so the
 * next run picks it up — activation fires at max(programme_start, confirmed_at)
 * without any timestamp column: the job keys purely on "confirmed AND started".
 * The tracker is locked until Active (see TrackerService::approveGate).
 */
class EnrolmentActivationService
{
    public function __construct(
        private readonly ScopeContext $scope,
        private readonly EnrolmentService $enrolments,
    ) {}

    public function run(?\DateTimeInterface $asOf = null): array
    {
        $today = ($asOf ?? now())->format('Y-m-d');

        return $this->scope->asSystem(
            'Enrolment activation job (S06-1, R3): the SYSTEM activates confirmed enrolments whose programme has started (basics.starts_on ≤ now) — payment-decoupled, keyed purely on "confirmed AND started". enrolments state writes are system-only (S04A); this is the scheduled actor. Transitions confirmed → active only.',
            function () use ($today): array {
                $due = DB::select(
                    "SELECT e.id
                     FROM enrolments e
                     JOIN wizard_sections ws ON ws.programme_id = e.programme_id AND ws.section_key = 'basics'
                     WHERE e.status = 'confirmed'
                       AND (ws.data::jsonb->>'starts_on') IS NOT NULL
                       AND (ws.data::jsonb->>'starts_on')::date <= ?::date
                     ORDER BY e.id",
                    [$today]
                );
                $activated = 0;
                foreach ($due as $row) {
                    // SYSTEM actor (null under system context, OD-64): programme started, not a human act
                    $this->enrolments->transition($row->id, 'active', null, 'programme started — enrolment activated (R3)');
                    $activated++;
                }

                return ['activated' => $activated];
            },
        );
    }
}
