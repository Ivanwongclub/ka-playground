<?php

namespace App\Services\Assessments;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S06-4b (2.5) — the assessment lifecycle. Draft → Published → Open → Closed →
 * Graded → Released (+ Cancelled). Grading records results while Closed/Graded;
 * RELEASED is the terminal state that lifts the embargo. The embargo itself is
 * enforced at READ (assessment_results RLS), not here — this is the workflow +
 * the writes. Academy operations/super drive the lifecycle and grade.
 */
class AssessmentService
{
    public const TRANSITIONS = [
        'draft' => ['published', 'cancelled'],
        'published' => ['open', 'cancelled'],
        'open' => ['closed', 'cancelled'],
        'closed' => ['graded', 'cancelled'],
        'graded' => ['released'],
        'released' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    public function create(int $programmeId, array $attrs, User $actor): string
    {
        $this->assertOrganiser($actor);

        return $this->scope->asSystem(
            'Assessment create (S06-4b, 2.5): the academy creates a Draft assessment for a programme (optionally a team). assessments is a system-only write; the academy-operator authority was established before the elevation.',
            function () use ($programmeId, $attrs, $actor): string {
                $id = (string) Str::uuid7();
                DB::table('assessments')->insert([
                    'id' => $id, 'programme_id' => $programmeId, 'team_id' => $attrs['team_id'] ?? null,
                    'title' => $attrs['title'], 'status' => 'draft', 'created_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record('assessment', $id, 'assessment.created', toState: 'draft', programmeId: $programmeId,
                    payloadAfter: ['title' => $attrs['title']], actor: $actor);

                return $id;
            },
        );
    }

    public function transition(string $assessmentId, string $to, User $actor): void
    {
        $this->assertOrganiser($actor);
        $this->scope->asSystem(
            'Assessment lifecycle transition (S06-4b, 2.5): the academy advances an assessment (draft→published→open→closed→graded→released). RELEASED lifts the result embargo. assessments.status is a system-only write; authority established before the elevation.',
            function () use ($assessmentId, $to, $actor): void {
                $assessment = DB::table('assessments')->where('id', $assessmentId)->first() ?? abort(404);
                if (! in_array($to, self::TRANSITIONS[$assessment->status] ?? [], true)) {
                    abort(409, "Illegal assessment transition {$assessment->status} → {$to}");
                }
                DB::table('assessments')->where('id', $assessmentId)->update(['status' => $to, 'updated_at' => now()]);
                $this->audit->record('assessment', $assessmentId, "assessment.{$to}", fromState: $assessment->status, toState: $to,
                    programmeId: (int) $assessment->programme_id, actor: $actor);
            },
        );
    }

    /** Record/replace a student's result. Allowed while Closed or Graded (results are entered before Release). */
    public function grade(string $assessmentId, int $studentId, int $score, User $grader): void
    {
        $this->assertOrganiser($grader);
        $this->scope->asSystem(
            'Assessment grading (S06-4b, 2.5): the academy records a student\'s result on a closed/graded assessment. assessment_results is a system-only write; the embargo (results hidden until Released) is enforced separately at READ. Grader authority established before the elevation.',
            function () use ($assessmentId, $studentId, $score, $grader): void {
                $assessment = DB::table('assessments')->where('id', $assessmentId)->first() ?? abort(404);
                if (! in_array($assessment->status, ['closed', 'graded'], true)) {
                    abort(409, "Grading is only allowed while the assessment is Closed or Graded (is {$assessment->status})");
                }
                $enrolment = DB::table('enrolments')->where('programme_id', $assessment->programme_id)
                    ->where('student_id', $studentId)->whereIn('status', ['confirmed', 'active'])->first();
                if ($enrolment === null) {
                    abort(422, 'That student has no live enrolment in this programme');
                }
                DB::table('assessment_results')->updateOrInsert(
                    ['assessment_id' => $assessmentId, 'enrolment_id' => $enrolment->id],
                    ['id' => (string) Str::uuid7(), 'student_id' => $studentId, 'score' => $score,
                        'graded_by' => $grader->id, 'graded_at' => now(), 'updated_at' => now(), 'created_at' => now()],
                );
                $this->audit->record('assessment_result', "{$assessmentId}:{$studentId}", 'assessment_result.graded',
                    programmeId: (int) $assessment->programme_id,
                    payloadAfter: ['assessment_id' => $assessmentId, 'student_id' => $studentId, 'graded_by' => $grader->id], actor: $grader);
            },
        );
    }

    private function assertOrganiser(User $actor): void
    {
        if ($actor->role !== 'academy_admin') {
            abort(403, 'Assessment management is an academy action');
        }
        $caps = DB::table('admin_capabilities')->where('user_id', $actor->id)->pluck('capability');
        if (! $caps->contains('operations') && ! $caps->contains('super_admin')) {
            abort(403, 'Assessment management requires the operations capability');
        }
    }
}
