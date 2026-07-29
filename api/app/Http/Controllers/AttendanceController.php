<?php

namespace App\Http\Controllers;

use App\Services\Sessions\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** S06-3 — attendance capture (mentor or academy operations). */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function mark(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['student_id' => 'required|integer', 'status' => 'required|in:attended,no_show']);
        $this->attendance->mark($id, (int) $data['student_id'], $data['status'], $request->user());

        return response()->json(['status' => $data['status']]);
    }
}
