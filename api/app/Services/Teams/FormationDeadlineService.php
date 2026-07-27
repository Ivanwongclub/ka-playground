<?php

namespace App\Services\Teams;

use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;

/**
 * S05 STEP 3 — the formation-deadline job (OD-33). Distinct from the wizard's
 * publish-time date-ordering validation (that lives in WizardService pre-flight).
 *
 * At/after a programme's formation_deadline_on: forming teams that meet the
 * minimum size AUTO-SUBMIT (forming → submitted, SYSTEM actor, so an approver
 * can 成團 them); teams below the minimum raise a `deadline_noncompliant`
 * exception (OD-33 "non-compliant → admin alert") that surfaces on the matching
 * screen beside the unplaced pool. The unteamed pool itself is queried live
 * (in_pool enrolments not in an active team) — no per-student row until an admin
 * rolls (parks) or the backstop fires.
 *
 * Idempotent: only forming teams are touched, and an open exception is never
 * duplicated for the same team.
 */
class FormationDeadlineService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly TeamExceptionService $exceptions,
    ) {}

    /** Process every published programme whose formation deadline has passed as of $asOf (default: today). */
    public function run(?\DateTimeInterface $asOf = null): array
    {
        $today = ($asOf ?? now())->format('Y-m-d');

        return $this->scope->asSystem(
            'Formation deadline job (S05-3, OD-33): at the deadline the SYSTEM auto-submits size-compliant forming teams and raises deadline_noncompliant exceptions for the rest. teams.status and team_exceptions are system-only writes; this is the scheduled actor. Reads and writes only rows of past-deadline programmes.',
            function () use ($today): array {
                $autoSubmitted = 0;
                $flagged = 0;
                // published programmes whose formation_deadline_on <= today
                $programmes = DB::table('programmes')->where('status', 'published')->pluck('id');
                foreach ($programmes as $programmeId) {
                    $rules = $this->teamRules((int) $programmeId);
                    $deadline = $rules['formation_deadline_on'] ?? null;
                    if ($deadline === null || $deadline > $today) {
                        continue; // no deadline, or not yet reached
                    }
                    $minSize = (int) ($rules['min_team_size'] ?? 1);
                    $teams = DB::table('teams')->where('programme_id', $programmeId)->where('status', 'forming')->get();
                    foreach ($teams as $team) {
                        $members = DB::table('team_members')->where('team_id', $team->id)->where('status', 'active')->count();
                        if ($members >= $minSize) {
                            DB::table('teams')->where('id', $team->id)->update(['status' => 'submitted', 'updated_at' => now()]);
                            $this->audit->record('team', $team->id, 'team.auto_submitted',
                                fromState: 'forming', toState: 'submitted', reason: 'formation deadline reached, size-compliant (OD-33)',
                                programmeId: (int) $programmeId, payloadAfter: ['member_count' => $members, 'min_size' => $minSize]);
                            $autoSubmitted++;
                        } else {
                            $raised = $this->exceptions->raise((int) $programmeId, 'deadline_noncompliant', teamId: $team->id,
                                reason: "Team below minimum at formation deadline: {$members}/{$minSize} (OD-33)");
                            if ($raised !== null) {
                                $flagged++;
                            }
                        }
                    }
                }

                return ['auto_submitted' => $autoSubmitted, 'flagged' => $flagged];
            },
        );
    }

    /** @return array<string, mixed> */
    private function teamRules(int $programmeId): array
    {
        return (array) (json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $programmeId)->where('section_key', 'team_rules')
            ->value('data'), true) ?? []);
    }
}
