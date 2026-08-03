<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * S-UX3-9 — teacher "My Students": the teacher's school roll. Child data (minor identities), but
 * ELEVATION-FREE — a teacher links to a SCHOOL (teacher_links = teacher_id+school_id), and both
 * school_links_read and users_read admit a teacher to THEIR school's students (school_id ∈ app.school_ids).
 * So this rides the teacher's own RLS, crossing no wall (unlike the S-UX3-4 attendance roster). Same shape
 * as SchoolAdminController::students, under the teacher's RLS.
 *
 * Allowlist is TIGHT: {student_id, student_name} only. Withheld — guardian identity, consent, enrolment
 * detail, money, another school's students (the last enforced by RLS, not just the query).
 */
class TeacherStudentsController extends Controller
{
    public function index(): JsonResponse
    {
        // Under the teacher's RLS: school_links_read admits their school's links; users_read admits those
        // students' names. A teacher of another school (or none) gets no rows — no elevation, no wall-cross.
        $rows = DB::table('school_links')
            ->join('users', 'users.id', '=', 'school_links.student_id')
            ->where('school_links.status', 'active')
            ->orderBy('users.name')
            ->get(['users.id as student_id', 'users.name as student_name']);

        return response()->json(['data' => $rows->map(fn ($r): array => [
            'student_id' => (int) $r->student_id,
            'student_name' => $r->student_name,
        ])->all()]);
    }
}
