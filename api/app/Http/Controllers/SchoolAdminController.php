<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Identity\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * School Admin surfaces (S02A step 3). Every query here runs under the actor's
 * RLS context — school_links/teacher_links only return the admin's own school's
 * rows, so cross-school data cannot appear regardless of what this code does.
 * The app layer adds the UX shape (404s, audited denials), never the boundary.
 */
class SchoolAdminController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly InvitationService $invitations,
    ) {}

    public function students(): JsonResponse
    {
        $rows = DB::table('school_links')
            ->join('users', 'users.id', '=', 'school_links.student_id')
            ->where('school_links.status', 'active')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'school_links.school_id']);

        return response()->json(['data' => $rows]);
    }

    public function student(Request $request, int $id): JsonResponse
    {
        $row = DB::table('school_links')
            ->join('users', 'users.id', '=', 'school_links.student_id')
            ->where('school_links.status', 'active')
            ->where('users.id', $id)
            ->first(['users.id', 'users.name', 'school_links.school_id']);

        if ($row === null) {
            // Out of scope OR nonexistent — identical 404, attempt audited (FR006)
            $this->audit->record(
                'user', (string) $id, 'scope.denied',
                reason: 'student detail requested outside the acting scope (FR006)',
                actor: $request->user(),
            );
            abort(404);
        }

        return response()->json(['data' => $row]);
    }

    public function teachers(): JsonResponse
    {
        $rows = DB::table('teacher_links')
            ->join('users', 'users.id', '=', 'teacher_links.teacher_id')
            ->where('teacher_links.status', 'active')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'teacher_links.school_id']);

        return response()->json(['data' => $rows]);
    }

    /** B1: School Admin invites teachers — scoped to their own school. */
    public function inviteTeacher(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'unique:users,email']]);

        // The admin's active school under their own RLS view — never from input
        $schoolId = DB::table('school_admin_links')
            ->where('school_admin_id', $request->user()->id)
            ->where('status', 'active')
            ->value('school_id');
        if ($schoolId === null) {
            abort(403, 'No active school affiliation');
        }

        $result = $this->invitations->issue($request->user(), $validated['email'], 'teacher', (int) $schoolId);

        return response()->json([
            'invitation_id' => $result['invitation']->id,
            'token' => $result['plain_token'],
            'school_id' => (int) $schoolId,
        ], 201);
    }
}
