<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The single write path into audit_events (BI-1). Every future module records
 * state transitions through this service; the actor's identity is always
 * captured (BI-8) — one identity per event, capabilities qualify, never blur it.
 */
class AuditService
{
    /**
     * @param  array<string, mixed>|null  $payloadBefore
     * @param  array<string, mixed>|null  $payloadAfter
     */
    public function record(
        string $entityType,
        string|int $entityId,
        string $action,
        ?string $fromState = null,
        ?string $toState = null,
        ?string $reason = null,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null,
        ?int $programmeId = null,
        ?int $onBehalfOf = null,
        ?Authenticatable $actor = null,
    ): AuditEvent {
        $actor ??= Auth::user();
        $request = app()->runningInConsole() ? null : request();

        $event = new AuditEvent([
            'event_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_role' => $actor?->getAttribute('role'),
            'on_behalf_of' => $onBehalfOf,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'action' => $action,
            'reason' => $reason,
            'payload_before' => $payloadBefore,
            'payload_after' => $payloadAfter,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->header('X-Request-Id') ?? (string) Str::uuid7(),
            'programme_id' => $programmeId,
        ]);
        $event->save();

        return $event;
    }
}
