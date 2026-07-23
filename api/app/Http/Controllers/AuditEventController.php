<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S00 audit element: Admin › Audit log viewer, filterable by
 * actor / entity / action / date. Read-only — the table is INSERT-only (BI-1).
 * Authorisation: Sanctum + audit_read capability arrive in S01 (OD-17); until
 * then the stack is local-only and undeployed (flagged in AUDIT S00 §5).
 */
class AuditEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'actor_id' => ['nullable', 'integer'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = AuditEvent::query()
            ->when($validated['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($validated['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($validated['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($validated['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($validated['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', $v))
            ->orderByDesc('occurred_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($events);
    }
}
