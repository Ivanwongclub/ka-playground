<?php

namespace App\Http\Controllers;

use App\Services\Sessions\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** S06-2 — session lifecycle (2.3) + reschedule/clash (2.24). Authority in-service (academy operations). */
class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions) {}

    public function store(Request $request, string $programmeId): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string', 'starts_at' => 'required|date', 'ends_at' => 'required|date',
            'capacity' => 'required|integer|min:1', 'team_id' => 'sometimes|nullable|uuid', 'mentor_id' => 'sometimes|nullable|integer',
        ]);
        $id = $this->sessions->create((int) $programmeId, $data, $request->user());

        return response()->json(['id' => $id, 'status' => 'draft'], 201);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['to' => 'required|string']);
        $this->sessions->transition($id, $data['to'], $request->user());

        return response()->json(['status' => $data['to']]);
    }

    public function reschedule(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['starts_at' => 'required|date', 'ends_at' => 'required|date', 'capacity' => 'sometimes|nullable|integer|min:1']);
        $result = $this->sessions->reschedule($id, $data['starts_at'], $data['ends_at'], $data['capacity'] ?? null, $request->user());

        return response()->json($result);
    }

    public function clashPreview(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['starts_at' => 'required|date', 'ends_at' => 'required|date']);
        $result = $this->sessions->clashPreview($id, $data['starts_at'], $data['ends_at'], $request->user());

        return response()->json($result);
    }
}
