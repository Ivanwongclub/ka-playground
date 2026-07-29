<?php

namespace App\Http\Controllers;

use App\Services\Sessions\MentorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** S06-2 (2.6) — mentor lifecycle. Authority in-service (academy operations). */
class MentorController extends Controller
{
    public function __construct(private readonly MentorService $mentors) {}

    public function setStatus(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:active,inactive,departed']);
        $this->mentors->setStatus((int) $userId, $data['status'], $request->user());

        return response()->json(['status' => $data['status']]);
    }
}
