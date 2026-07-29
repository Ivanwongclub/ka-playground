<?php

namespace App\Http\Controllers;

use App\Services\Members\MemberSurfaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** S06-5 (OD-22) — Member self-service: RSVP, own rsvps, directory, own profile. */
class MemberController extends Controller
{
    public function __construct(private readonly MemberSurfaceService $members) {}

    public function rsvp(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:going,not_going,maybe']);
        $this->members->rsvp($id, $data['status'], $request->user());

        return response()->json(['status' => $data['status']]);
    }

    /** RLS-shaped: a Member sees only their OWN rsvps (never another Member's). */
    public function myRsvps(Request $request): JsonResponse
    {
        $rows = DB::table('event_rsvps')->get(['event_id', 'member_id', 'status']);

        return response()->json(['rsvps' => $rows]);
    }

    /** RLS-shaped directory: a Member sees visible member profiles; non-members see nothing. */
    public function directory(): JsonResponse
    {
        $rows = DB::table('member_profiles')->where('visible', true)->get(['user_id', 'display_name', 'headline']);

        return response()->json(['directory' => $rows]);
    }

    public function profile(Request $request): JsonResponse
    {
        $data = $request->validate(['display_name' => 'required|string', 'headline' => 'sometimes|nullable|string', 'visible' => 'sometimes|boolean']);
        $this->members->upsertProfile($data, $request->user());

        return response()->json(['status' => 'saved']);
    }
}
