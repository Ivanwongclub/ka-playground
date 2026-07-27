<?php

namespace App\Services\Teams;

use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;

/**
 * S05-4 — the non-payment lapse cascade (OD-45), the resolution machinery that
 * makes `deadlines.no_silent_lapse` assertable rather than a permanent red.
 *
 * A scheduled SYSTEM job scans FAMILY-PAID, still-unpaid orders whose live
 * member is past the payment deadline + grace (grace = config default, overridden
 * per-member by a grace-ONCE extension in team_members.grace_until). For each:
 *   1. a SYSTEM-actor lapse audit event on the order,
 *   2. the member SUSPENDED on team_members (status=suspended) — NOT an enrolment
 *      state; the enrolment stays confirmed (OD-45: never automatic team collapse),
 *   3. an FR066 `lapse` exception.
 * If the suspension drops the team below its minimum, a `below_min` exception is
 * raised — the entry point to the OD-37 four terminal actions (STEP 4 resolution).
 */
class LapseDetectionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly TeamExceptionService $exceptions,
    ) {}

    public function run(?\DateTimeInterface $asOf = null): array
    {
        $now = $asOf ?? now();
        $graceDays = (int) config('teams.lapse_grace_days', 7);

        return $this->scope->asSystem(
            'Lapse-detection job (S05-4, OD-45): the SYSTEM scans family-paid unpaid orders past payment_due_at + grace and, for each, writes a lapse audit, suspends the member on team_members, and raises an FR066 lapse (+ below_min) exception. teams/team_members/team_exceptions are system-only writes; this is the scheduled actor.',
            function () use ($now, $graceDays): array {
                $lapsed = 0;
                $belowMin = 0;
                // family-paid, unpaid, with a LIVE team member, past the effective deadline
                $candidates = DB::select(
                    "SELECT o.id AS order_id, o.enrolment_id, tm.id AS tm_id, tm.team_id, t.programme_id
                     FROM orders o
                     JOIN team_members tm ON tm.enrolment_id = o.enrolment_id AND tm.status = 'active'
                     JOIN teams t ON t.id = tm.team_id
                     WHERE o.payer_party = 'guardian' AND o.status = 'issued'
                       AND COALESCE(tm.grace_until, o.payment_due_at + (? || ' days')::interval) < ?
                     ORDER BY o.id",
                    [$graceDays, $now]
                );

                $touchedTeams = [];
                foreach ($candidates as $c) {
                    DB::table('team_members')->where('id', $c->tm_id)->update([
                        'status' => 'suspended', 'suspended_at' => $now, 'updated_at' => now(),
                    ]);
                    $this->audit->record('order', $c->order_id, 'order.lapsed',
                        reason: 'family-paid order unpaid past payment_due_at + grace (OD-45)',
                        programmeId: (int) $c->programme_id,
                        payloadAfter: ['enrolment_id' => $c->enrolment_id, 'team_id' => $c->team_id, 'grace_days' => $graceDays]);
                    $this->audit->record('team_member', $c->tm_id, 'team_member.suspended',
                        toState: 'suspended', programmeId: (int) $c->programme_id,
                        payloadAfter: ['enrolment_id' => $c->enrolment_id, 'order_id' => $c->order_id]);
                    $this->exceptions->raise((int) $c->programme_id, 'lapse',
                        teamId: $c->team_id, enrolmentId: $c->enrolment_id,
                        reason: 'Family payment lapsed; member suspended (OD-45, FR066)');
                    $lapsed++;
                    $touchedTeams[$c->team_id] = (int) $c->programme_id;
                }

                // a suspension that drops a team below minimum opens the four-action exception (OD-37)
                foreach ($touchedTeams as $teamId => $programmeId) {
                    $minSize = (int) ($this->minTeamSize($programmeId) ?? 1);
                    $active = DB::table('team_members')->where('team_id', $teamId)->where('status', 'active')->count();
                    if ($active < $minSize) {
                        $raised = $this->exceptions->raise($programmeId, 'below_min', teamId: $teamId,
                            reason: "Team below minimum after suspension: {$active}/{$minSize} (OD-37)");
                        if ($raised !== null) {
                            $belowMin++;
                        }
                    }
                }

                return ['lapsed' => $lapsed, 'below_min' => $belowMin];
            },
        );
    }

    private function minTeamSize(int $programmeId): ?int
    {
        $rules = (array) (json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $programmeId)->where('section_key', 'team_rules')
            ->value('data'), true) ?? []);

        return isset($rules['min_team_size']) ? (int) $rules['min_team_size'] : null;
    }
}
