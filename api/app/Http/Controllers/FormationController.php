<?php

namespace App\Http\Controllers;

use App\Services\Teams\FormationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FormationController extends Controller
{
    public function __construct(private readonly FormationService $formation) {}

    /** §4 — the lobbies this student may form/join in. */
    public function lobbies(Request $request, int $programmeId): JsonResponse
    {
        return response()->json(['data' => $this->formation->lobbiesFor($programmeId, $request->user())]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'programme_id' => ['required', 'integer'],
            'category_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:80'],
        ]);
        $team = $this->formation->create((int) $data['programme_id'], $data['category_id'], $data['name'], $request->user());

        return response()->json(['id' => $team->id, 'status' => $team->status], 201);
    }

    public function join(Request $request, string $id): JsonResponse
    {
        $this->formation->join($id, $request->user());

        return response()->json(['joined' => true]);
    }

    /**
     * RLS-shaped: members see their team; lobby school admin sees teams in their lobby.
     *
     * S-UX3-3a Backend delta B1 — ADDITIVE names (S-UX2b): programme, category/lobby and the
     * `created_by` person, each LEFT-joined so the row count is preserved (a name resolves or is
     * NULL, the team never drops). `created_by_name` rides `users_read`'s own gate (double-gated):
     * NULL when the caller may not see that user — expected, never the raw id on screen. Plus an
     * additive active-member count for the Team Formation queue. No column removed; existing keys unchanged.
     */
    public function index(): JsonResponse
    {
        $rows = DB::table('teams as t')
            ->leftJoin('programmes as p', 'p.id', '=', 't.programme_id')
            ->leftJoin('team_categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->orderBy('t.created_at')
            ->get([
                't.id', 't.programme_id', 't.category_id', 't.name', 't.status', 't.created_by',
                'p.name_en as programme_name_en', 'p.name_tc as programme_name_tc', 'p.name_sc as programme_name_sc',
                'c.name_en as category_name_en', 'c.name_tc as category_name_tc', 'c.name_sc as category_name_sc',
                'u.name as created_by_name',
            ]);

        // Additive active-member count, resolved under the caller's RLS (advisory queue signal).
        $counts = DB::table('team_members')->where('status', 'active')
            ->select('team_id', DB::raw('count(*) as n'))->groupBy('team_id')->pluck('n', 'team_id');
        $rows->each(fn ($r) => $r->member_count = (int) ($counts[$r->id] ?? 0));

        return response()->json(['data' => $rows]);
    }
}
