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

    /** RLS-shaped: members see their team; lobby school admin sees teams in their lobby. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('teams')->orderBy('created_at')
            ->get(['id', 'programme_id', 'category_id', 'name', 'status', 'created_by'])]);
    }
}
