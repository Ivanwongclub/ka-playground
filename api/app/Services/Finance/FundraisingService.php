<?php

namespace App\Services\Finance;

use App\Models\TeamFundraising;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * S07 STEP 3 (FR057 · OD-4 · Pitch reframe) — a team declares its project type
 * (sponsorship | charity) and funding target. ONE per team. Record-only, WHOLLY
 * SEPARATE from the Order module. The declared `charity` type is the OD-4 anchor
 * — TransactionService refuses any distribution to a member against it.
 */
class FundraisingService
{
    public function __construct(
        private readonly ScopeContext $scope,
        private readonly AuditService $audit,
    ) {}

    public function declareProject(string $teamId, string $projectType, int $fundingTargetMinor, User $actor): TeamFundraising
    {
        if (! in_array($projectType, [TeamFundraising::SPONSORSHIP, TeamFundraising::CHARITY], true)) {
            throw ValidationException::withMessages(['project_type' => ['project_type must be sponsorship or charity']]);
        }

        return $this->scope->asSystem(
            'S07 STEP 3 fundraising declaration (FR057 · OD-4): a team member declares the project type (sponsorship|charity) + funding target on team_fundraising (one per team, system-write, record-only, WHOLLY SEPARATE from the Order module). Team membership is checked before the elevation; the declaration is audited.',
            function () use ($teamId, $projectType, $fundingTargetMinor, $actor): TeamFundraising {
                if (! DB::table('team_members')->where('team_id', $teamId)->where('student_id', $actor->id)->where('status', 'active')->exists()) {
                    abort(403, 'Only an active member of the team may declare its project');
                }
                $existing = TeamFundraising::query()->where('team_id', $teamId)->first();
                if ($existing !== null) {
                    $existing->update(['project_type' => $projectType, 'funding_target_minor' => $fundingTargetMinor]);
                    $row = $existing->fresh();
                } else {
                    $row = TeamFundraising::query()->create([
                        'id' => (string) Str::uuid7(), 'team_id' => $teamId, 'project_type' => $projectType,
                        'funding_target_minor' => $fundingTargetMinor, 'currency' => 'HKD', 'declared_by' => $actor->id,
                    ]);
                }
                $this->audit->record('team_fundraising', $row->id, 'team_fundraising.declared',
                    payloadAfter: ['team_id' => $teamId, 'project_type' => $projectType, 'funding_target_minor' => $fundingTargetMinor], actor: $actor);

                return $row;
            },
        );
    }
}
