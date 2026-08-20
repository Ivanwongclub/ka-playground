<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Team formation in lobbies (TEAM-CATEGORIES §4–§8). A team belongs to one
 * lobby for life (§8); school-bound lobbies admit only linked students (§5);
 * joining transitions the member's enrolment in_pool → teamed. Team Formation (the
 * capacity claim + teamed→confirmed) is STEP 2.
 */
class FormationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly EnrolmentService $enrolments,
        private readonly ScopeContext $scope,
    ) {}

    /**
     * §4 resolution: the lobbies a student may form/join in, for a programme.
     * auto_by_school matches the student's active school links; else open
     * lobbies + the default.
     *
     * @return array<int, array{id: int, name_en: string, name_tc: string, name_sc: string, assignment_rule: string, school_bound: bool, eligible: bool}>
     */
    public function lobbiesFor(int $programmeId, User $student): array
    {
        $schoolIds = DB::table('school_links')->where('student_id', $student->id)
            ->where('status', 'active')->pluck('school_id')->all();
        $categories = DB::table('team_categories')->where('programme_id', $programmeId)->get();

        return $categories->map(function ($c) use ($schoolIds): array {
            $eligible = $c->school_id === null || in_array((int) $c->school_id, array_map('intval', $schoolIds), true);

            // S-FIX-UX-1 D4: additive name_tc/name_sc (already fetched by ->get()) so the student
            // create-team lobby picker renders trilingual — display-only, no formation-logic change.
            return [
                'id' => $c->id, 'name_en' => $c->name_en, 'name_tc' => $c->name_tc, 'name_sc' => $c->name_sc,
                'assignment_rule' => $c->assignment_rule, 'is_default' => (bool) $c->is_default,
                'school_bound' => $c->school_id !== null, 'eligible' => $eligible,
            ];
        })->all();
    }

    public function create(int $programmeId, string $categoryId, string $name, User $student): object
    {
        $enrolment = $this->pooledEnrolment($programmeId, $student);
        $category = $this->eligibleCategory($programmeId, $categoryId, $student);

        $id = (string) Str::uuid7();
        DB::table('teams')->insert([
            'id' => $id, 'programme_id' => $programmeId, 'category_id' => $category->id,
            'name' => $name, 'created_by' => $student->id, 'status' => 'forming',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->addMember($id, (string) $category->id, $enrolment, $student, $programmeId);
        $this->audit->record('team', $id, 'team.formed',
            toState: 'forming', programmeId: $programmeId,
            payloadAfter: ['category_id' => $category->id, 'name' => $name, 'created_by' => $student->id],
            actor: $student);

        return DB::table('teams')->where('id', $id)->first();
    }

    public function join(string $teamId, User $student): void
    {
        $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
        if ($team->status !== 'forming') {
            abort(409, "Team is {$team->status} and no longer accepting members");
        }
        $enrolment = $this->pooledEnrolment((int) $team->programme_id, $student);
        // §5/§8: the student must qualify for THIS team's lobby (cross-lobby join impossible)
        $this->eligibleCategory((int) $team->programme_id, $team->category_id, $student);
        $this->addMember($teamId, (string) $team->category_id, $enrolment, $student, (int) $team->programme_id);
    }

    /** The one enrolment-state write formation makes — a system state-machine transition. */
    private function addMember(string $teamId, string $categoryId, object $enrolment, User $student, int $programmeId): void
    {
        try {
            DB::table('team_members')->insert([
                'id' => (string) Str::uuid7(), 'team_id' => $teamId, 'programme_id' => $programmeId,
                'enrolment_id' => $enrolment->id, 'category_id' => $categoryId, 'student_id' => $student->id,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['team' => ['This student is already in a team for this programme']]);
        }
        // in_pool → teamed. enr_update is system-only by policy (S04A: the state
        // machine writes as system); the student's join authority was just
        // established above in their own context. Narrow, audited elevation.
        $this->scope->asSystem(
            'Team formation transition (S05): joining a team moves the member\'s enrolment in_pool → teamed. The enr_update policy restricts state writes to system (S04A); the joining student\'s authority was established by the pooled-enrolment + lobby-eligibility checks in their own context immediately prior. Transitions exactly this one enrolment.',
            fn () => $this->enrolments->transition($enrolment->id, 'teamed', $student, "joined team {$teamId}"),
        );
        $this->audit->record('team_member', $enrolment->id, 'team_member.joined',
            toState: 'active', programmeId: $programmeId,
            payloadAfter: ['team_id' => $teamId, 'student_id' => $student->id], actor: $student);
    }

    private function pooledEnrolment(int $programmeId, User $student): object
    {
        $e = DB::table('enrolments')->where('programme_id', $programmeId)
            ->where('student_id', $student->id)->where('status', 'in_pool')->first();
        if ($e === null) {
            // must be consented + unteamed (in the awaiting-a-team pool, OD-34)
            throw ValidationException::withMessages(['enrolment' => ['You must have a consented, unteamed enrolment in this programme to form or join a team']]);
        }

        return $e;
    }

    private function eligibleCategory(int $programmeId, string $categoryId, User $student): object
    {
        $c = DB::table('team_categories')->where('id', $categoryId)->where('programme_id', $programmeId)->first();
        if ($c === null) {
            throw ValidationException::withMessages(['lobby' => ['That lobby does not belong to this programme']]);
        }
        if ($c->school_id !== null) {
            // §5: school-bound lobby admits only students linked to that school
            $linked = DB::table('school_links')->where('student_id', $student->id)
                ->where('school_id', $c->school_id)->where('status', 'active')->exists();
            if (! $linked) {
                $name = DB::table('schools')->where('id', $c->school_id)->value('name_en');
                throw ValidationException::withMessages(['lobby' => ["This lobby is for students of {$name} — you are not linked to {$name}"]]);
            }
        }

        return $c;
    }
}
