<?php

namespace App\Http\Controllers;

use App\Services\Members\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** S06-5 (OD-22) — network events. Management is academy; the list read is RLS-shaped. */
class EventController extends Controller
{
    public function __construct(private readonly EventService $events) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title_en' => 'required|string', 'title_tc' => 'required|string', 'title_sc' => 'required|string',
            'starts_at' => 'required|date', 'location' => 'sometimes|nullable|string',
        ]);
        $id = $this->events->create($data, $request->user());

        return response()->json(['id' => $id, 'status' => 'draft'], 201);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['to' => 'required|string']);
        $this->events->transition($id, $data['to'], $request->user());

        return response()->json(['status' => $data['to']]);
    }

    /** RLS-shaped: a Member sees published events (network-wide); academy sees all. */
    public function index(): JsonResponse
    {
        $rows = DB::table('events')->orderBy('starts_at')->get(['id', 'title_en', 'title_tc', 'title_sc', 'starts_at', 'location', 'status']);

        return response()->json(['events' => $rows]);
    }
}
