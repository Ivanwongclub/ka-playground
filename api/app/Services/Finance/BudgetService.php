<?php

namespace App\Services\Finance;

use App\Models\TeamBudget;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Teams\TrackerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * S07 STEP 1 (FR061 · Spec §P1) — team-PROJECT budgets, record-only. ONE guarded
 * transition path (system-context, every edge audited); no side doors. The
 * ledger is WHOLLY SEPARATE from enrolment money (§A3/GR006) — this service
 * touches only team_budgets/budget_lines, never the Order module.
 *
 * Teacher-approval REUSES the S05 gate authority READ-ONLY (TrackerService::
 * approverKindFor → gateApproverKind, unchanged) — not re-homed (D-16).
 */
class BudgetService
{
    /** §P1 state machine (codes). */
    private const TRANSITIONS = [
        TeamBudget::DRAFT => [TeamBudget::SUBMITTED],
        TeamBudget::SUBMITTED => [TeamBudget::UNDER_REVIEW],
        TeamBudget::UNDER_REVIEW => [TeamBudget::APPROVED, TeamBudget::CHANGES_REQUESTED],
        TeamBudget::APPROVED => [TeamBudget::ACTIVE],
        TeamBudget::CHANGES_REQUESTED => [TeamBudget::DRAFT],
        TeamBudget::ACTIVE => [TeamBudget::CLOSED],
        TeamBudget::CLOSED => [],
    ];

    public function __construct(
        private readonly ScopeContext $scope,
        private readonly AuditService $audit,
        private readonly TrackerService $tracker,
    ) {}

    public function createDraft(string $teamId, User $actor): TeamBudget
    {
        return $this->elevated(function () use ($teamId, $actor): TeamBudget {
            $this->assertTeamMember($teamId, $actor);
            if (DB::table('team_budgets')->where('team_id', $teamId)->where('status', '<>', TeamBudget::CLOSED)->exists()) {
                throw ValidationException::withMessages(['budget' => ['This team already has a live budget — close it before starting another']]);
            }
            $budget = TeamBudget::query()->create([
                'id' => (string) Str::uuid7(), 'team_id' => $teamId, 'status' => TeamBudget::DRAFT, 'currency' => 'HKD',
            ]);
            $this->audit->record('team_budget', $budget->id, 'team_budget.draft', toState: TeamBudget::DRAFT,
                payloadAfter: ['team_id' => $teamId], actor: $actor);

            return $budget;
        });
    }

    public function addLine(string $budgetId, string $category, string $name, int $plannedMinor, User $actor): string
    {
        return $this->elevated(function () use ($budgetId, $category, $name, $plannedMinor, $actor): string {
            $budget = $this->load($budgetId);
            $this->assertTeamMember($budget->team_id, $actor);
            if (! in_array($budget->status, TeamBudget::EDITABLE, true)) {
                throw ValidationException::withMessages(['budget' => ["Budget lines are editable only while draft or changes_requested (currently {$budget->status})"]]);
            }
            if (! DB::table('budget_categories')->where('code', $category)->exists()) {
                throw ValidationException::withMessages(['category' => ["Unknown budget category '{$category}'"]]);
            }
            $id = (string) Str::uuid7();
            DB::table('budget_lines')->insert([
                'id' => $id, 'budget_id' => $budgetId, 'team_id' => $budget->team_id, 'category' => $category,
                'name' => $name, 'planned_amount_minor' => $plannedMinor, 'currency' => 'HKD',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    public function submit(string $budgetId, User $actor): void
    {
        $this->elevated(function () use ($budgetId, $actor): void {
            $budget = $this->load($budgetId);
            $this->assertTeamMember($budget->team_id, $actor);
            if (DB::table('budget_lines')->where('budget_id', $budgetId)->count() === 0) {
                throw ValidationException::withMessages(['budget' => ['A budget needs at least one line before submission']]);
            }
            DB::table('team_budgets')->where('id', $budgetId)->update(['submitted_by' => $actor->id]);
            $this->transition($budgetId, TeamBudget::SUBMITTED, $actor, 'submitted for approval');
        });
    }

    /** Teacher approves: submitted → under_review → approved → active (each edge audited). */
    public function approve(string $budgetId, User $approver): void
    {
        $this->elevated(function () use ($budgetId, $approver): void {
            $budget = $this->load($budgetId);
            $this->assertApprover($budget->team_id, $approver);
            $this->transition($budgetId, TeamBudget::UNDER_REVIEW, $approver, 'review opened');
            $this->transition($budgetId, TeamBudget::APPROVED, $approver, 'budget approved');
            DB::table('team_budgets')->where('id', $budgetId)->update(['approved_by' => $approver->id, 'activated_at' => now()]);
            $this->transition($budgetId, TeamBudget::ACTIVE, $approver, 'budget activated');
        });
    }

    public function requestChanges(string $budgetId, User $approver, ?string $notes = null): void
    {
        $this->elevated(function () use ($budgetId, $approver, $notes): void {
            $budget = $this->load($budgetId);
            $this->assertApprover($budget->team_id, $approver);
            $this->transition($budgetId, TeamBudget::UNDER_REVIEW, $approver, 'review opened');
            $this->transition($budgetId, TeamBudget::CHANGES_REQUESTED, $approver, $notes ?? 'changes requested');
        });
    }

    public function revise(string $budgetId, User $actor): void
    {
        $this->elevated(function () use ($budgetId, $actor): void {
            $this->assertTeamMember($this->load($budgetId)->team_id, $actor);
            $this->transition($budgetId, TeamBudget::DRAFT, $actor, 'reopened for revision');
        });
    }

    public function close(string $budgetId, User $actor): void
    {
        $this->elevated(function () use ($budgetId, $actor): void {
            $this->assertTeamMember($this->load($budgetId)->team_id, $actor);
            $this->transition($budgetId, TeamBudget::CLOSED, $actor, 'budget closed');
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function transition(string $budgetId, string $to, User $actor, string $reason): void
    {
        $budget = $this->load($budgetId);
        if ($budget->status === $to) {
            return;
        }
        if (! in_array($to, self::TRANSITIONS[$budget->status] ?? [], true)) {
            throw new \RuntimeException("Illegal budget transition {$budget->status} → {$to}");
        }
        DB::table('team_budgets')->where('id', $budgetId)->update(['status' => $to, 'updated_at' => now()]);
        $this->audit->record('team_budget', $budgetId, "team_budget.{$to}",
            fromState: $budget->status, toState: $to, reason: $reason,
            payloadAfter: ['team_id' => $budget->team_id], actor: $actor);
    }

    private function load(string $budgetId): object
    {
        return DB::table('team_budgets')->where('id', $budgetId)->first()
            ?? throw new \RuntimeException("Team budget {$budgetId} not found");
    }

    private function assertTeamMember(string $teamId, User $actor): void
    {
        $ok = DB::table('team_members')->where('team_id', $teamId)
            ->where('student_id', $actor->id)->where('status', 'active')->exists();
        if (! $ok) {
            abort(403, 'Only an active member of the team may manage its budget');
        }
    }

    private function assertApprover(string $teamId, User $approver): void
    {
        $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
        // reuse S05 gate authority READ-ONLY — throws 403 if the actor may not approve
        $this->tracker->approverKindFor($approver, $team);
    }

    private function elevated(\Closure $fn): mixed
    {
        return $this->scope->asSystem(
            'S07 STEP 1 team budget lifecycle (FR061 · Spec §P1): the whole budget state machine is a system-context op on team_budgets/budget_lines (system-write by construction, RECORD-ONLY, WHOLLY SEPARATE from the Order module per §A3/GR006). Actor authority — team membership for team actions, the reused S05 gate authority for approval — is checked before the elevation; every transition is audited.',
            $fn,
        );
    }
}
