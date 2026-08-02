<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S00 audit element: Admin › Audit log viewer, filterable by
 * actor / entity / action / date. Read-only — the table is INSERT-only (BI-1).
 * Secured in S01 STEP 6: auth:sanctum + permission:audit.read (OD-17).
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

        // S-UX2b: additive actor_name via a LEFT JOIN (actor may be null/system → NULL, row survives).
        // Filters qualified to audit_events.* to stay unambiguous under the join. entity_id stays raw
        // (polymorphic across entity types — its per-type name resolver is a separate, later card).
        $events = AuditEvent::query()
            ->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')
            ->select('audit_events.*', 'users.name as actor_name')
            ->when($validated['actor_id'] ?? null, fn ($q, $v) => $q->where('audit_events.actor_id', $v))
            ->when($validated['entity_type'] ?? null, fn ($q, $v) => $q->where('audit_events.entity_type', $v))
            ->when($validated['action'] ?? null, fn ($q, $v) => $q->where('audit_events.action', 'like', $v.'%'))
            ->when($validated['from'] ?? null, fn ($q, $v) => $q->where('audit_events.occurred_at', '>=', $v))
            ->when($validated['to'] ?? null, fn ($q, $v) => $q->where('audit_events.occurred_at', '<=', $v))
            ->orderByDesc('audit_events.occurred_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($events);
    }
}
