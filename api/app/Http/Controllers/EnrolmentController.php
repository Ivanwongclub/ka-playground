<?php

namespace App\Http\Controllers;

use App\Services\Enrolments\EnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrolmentController extends Controller
{
    public function __construct(private readonly EnrolmentService $enrolments) {}

    /** Guardian creates the INTENT (2.22). Response carries NO consent data. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'programme_id' => ['required', 'integer'],
            'student_id' => ['required', 'integer'],
        ]);
        $enrolment = $this->enrolments->create(
            (int) $data['programme_id'], (int) $data['student_id'], $request->user(),
        );

        return response()->json([
            'id' => $enrolment->id, 'programme_id' => $enrolment->programme_id,
            'student_id' => $enrolment->student_id, 'status' => $enrolment->status,
        ], 201);
    }

    /** RLS-shaped list: each session sees exactly its branch of the read set. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('enrolments')->orderBy('created_at')
            ->get(['id', 'programme_id', 'student_id', 'acting_guardian_id', 'status', 'created_at'])]);
    }
}
