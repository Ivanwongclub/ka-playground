<?php

namespace App\Http\Controllers;

use App\Services\Assessments\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** S06-4b (2.5) — assessment lifecycle + grading + the RLS-embargoed result reads. */
class AssessmentController extends Controller
{
    public function __construct(private readonly AssessmentService $assessments) {}

    /**
     * R2-ASSESS (ruling 1) — the per-programme assessment LIST. RLS-shaped on the existing assessments_read
     * (ops/audit see all; a programme's student and their guardian see title/status only — NEVER a score;
     * scores stay embargoed in assessment_results_read). No elevation. Serves both the admin section and the
     * family's Profile360 released-results composition.
     */
    public function index(Request $request, string $programmeId): JsonResponse
    {
        return response()->json(['data' => DB::table('assessments')
            ->where('programme_id', $programmeId)
            ->orderBy('created_at')
            ->get(['id', 'title', 'status', 'team_id'])]);
    }

    public function store(Request $request, string $programmeId): JsonResponse
    {
        $data = $request->validate(['title' => 'required|string', 'team_id' => 'sometimes|nullable|uuid']);
        $id = $this->assessments->create((int) $programmeId, $data, $request->user());

        return response()->json(['id' => $id, 'status' => 'draft'], 201);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['to' => 'required|string']);
        $this->assessments->transition($id, $data['to'], $request->user());

        return response()->json(['status' => $data['to']]);
    }

    public function grade(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['student_id' => 'required|integer', 'score' => 'required|integer']);
        $this->assessments->grade($id, (int) $data['student_id'], (int) $data['score'], $request->user());

        return response()->json(['status' => 'graded']);
    }

    /** RLS-embargoed single result read: a family sees it only once Released; academy sees all states. */
    public function result(Request $request, string $id, string $studentId): JsonResponse
    {
        $row = DB::table('assessment_results')->where('assessment_id', $id)->where('student_id', (int) $studentId)
            ->first(['id', 'assessment_id', 'student_id', 'score', 'graded_at']);

        return response()->json(['result' => $row]); // null when the embargo hides it
    }

    /** Academy view: all results for the assessment (RLS admits every state to ops/audit). */
    public function results(Request $request, string $id): JsonResponse
    {
        $rows = DB::table('assessment_results')->where('assessment_id', $id)->get(['student_id', 'score', 'graded_at']);

        return response()->json(['results' => $rows]);
    }
}
