<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        // OD-64: attribution is NEVER null. When no human actor exists and the
        // scope context is system (queue jobs, scheduler, console), the event
        // is attributed to the SYSTEM actor explicitly — a job-driven state
        // change reads "system", not an attribution hole.
        $systemActor = false;
        if ($actor === null) {
            try {
                $ctx = DB::selectOne("SELECT current_setting('app.context', true) AS c")->c ?? '';
            } catch (\Throwable) {
                $ctx = '';
            }
            $systemActor = $ctx === 'system';
        }

        $event = new AuditEvent([
            'event_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_role' => $actor?->getAttribute('role') ?? ($systemActor ? 'system' : null),
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
