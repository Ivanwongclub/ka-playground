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

    /**
     * RLS-shaped list: each session sees exactly its branch of the read set.
     * S-UX2b: additive display names. LEFT JOINs only — a name-join must never drop a row.
     * Names are further gated by each joined table's own RLS (users_read, programmes): a name
     * resolves iff the caller could already SELECT that row, else NULL. So a student who sees
     * their own enrolment but cannot read the guardian's user row gets acting_guardian = NULL.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('enrolments as e')
            ->leftJoin('programmes as p', 'p.id', '=', 'e.programme_id')
            ->leftJoin('users as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as g', 'g.id', '=', 'e.acting_guardian_id')
            ->orderBy('e.created_at')
            ->get([
                'e.id', 'e.programme_id', 'e.student_id', 'e.acting_guardian_id', 'e.status', 'e.created_at',
                'p.name_en as programme_name_en', 'p.name_tc as programme_name_tc', 'p.name_sc as programme_name_sc',
                's.name as student_name', 'g.name as acting_guardian',
            ])]);
    }
}
