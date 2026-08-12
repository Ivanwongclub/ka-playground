<?php

namespace App\Http\Controllers;

use App\Services\Teams\RoleRotationService;
use App\Services\Teams\TeamTeacherLinkService;
use App\Services\Teams\TrackerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S05-5 — roles & tracker. Authority is enforced in the services (OD-15/61).
 */
class RolesTrackerController extends Controller
{
    public function __construct(
        private readonly TeamTeacherLinkService $teacherLinks,
        private readonly RoleRotationService $roles,
        private readonly TrackerService $tracker,
    ) {}

    /** Link a teacher to a team (OD-61) — school admin of lobby or academy admin. */
    public function linkTeacher(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['teacher_id' => 'required|integer']);
        $linkId = $this->teacherLinks->link($id, (int) $data['teacher_id'], $request->user());

        return response()->json(['status' => 'linked', 'link_id' => $linkId]);
    }

    /** S-MENTOR-1 (ruling 5) — remove a team-teacher link; authority enforced in-service (same as link). */
    public function unlinkTeacher(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['teacher_id' => 'required|integer']);
        $this->teacherLinks->unlink($id, (int) $data['teacher_id'], $request->user());

        return response()->json(['status' => 'unlinked']);
    }

    /** Record a role assignment/rotation (OD-15) — staff. */
    public function assignRole(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['enrolment_id' => 'required|uuid', 'role_id' => 'required|uuid']);
        $result = $this->roles->assignRole($id, $data['enrolment_id'], $data['role_id'], $request->user());

        return response()->json($result);
    }

    /** Approve a stage gate (OD-61) — team-linked teacher or lobby school admin. */
    public function approveGate(Request $request, string $id, string $stage): JsonResponse
    {
        $data = $request->validate(['notes' => 'sometimes|nullable|string']);
        $result = $this->tracker->approveGate($id, $stage, $request->user(), $data['notes'] ?? null);

        return response()->json($result);
    }
}
