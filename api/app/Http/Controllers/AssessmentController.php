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
