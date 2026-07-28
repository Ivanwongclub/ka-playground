<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * S05-6 audit element — the Team & Capacity Report (the client-facing product
 * screen, distinct from AUDIT.md). Per programme: capacity vs claimed vs pool
 * depth vs formation deadline; the 成團 log (approver + seat math, from the
 * immutable team.confirmed audit events); the exception ledger with parking age
 * against the backstop; and the waiver register. Every read is RLS-shaped — the
 * report shows exactly what the requesting admin is entitled to see.
 */
class TeamCapacityReportController extends Controller
{
    public function show(string $programmeId): JsonResponse
    {
        $cap = DB::table('programme_capacity')->where('programme_id', $programmeId)->first();
        $rules = (array) (json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $programmeId)->where('section_key', 'team_rules')->value('data'), true) ?? []);
        $poolDepth = DB::table('enrolments')->where('programme_id', $programmeId)->where('status', 'in_pool')->count();

        $confirmLog = DB::table('audit_events')
            ->where('programme_id', $programmeId)->where('action', 'team.confirmed')
            ->orderByDesc('occurred_at')
            ->get(['entity_id as team_id', 'actor_id as approver_id', 'payload_after', 'occurred_at'])
            ->map(fn ($r) => [
                'team_id' => $r->team_id,
                'approver_id' => $r->approver_id,
                'occurred_at' => $r->occurred_at,
                'seats_claimed' => json_decode((string) $r->payload_after, true)['seats_claimed'] ?? null,
                'member_count' => json_decode((string) $r->payload_after, true)['member_count'] ?? null,
            ]);

        $now = now();
        $exceptions = DB::table('team_exceptions')->where('programme_id', $programmeId)
            ->orderByDesc('created_at')
            ->get(['id', 'type', 'status', 'team_id', 'enrolment_id', 'backstop_at', 'resolution', 'created_at'])
            ->map(fn ($r) => (array) $r + [
                // days until (positive) or past (negative) the 90-day backstop, for parked rows
                'days_to_backstop' => $r->backstop_at !== null
                    ? (int) floor(($now->getTimestamp() - strtotime((string) $r->backstop_at)) / -86400)
                    : null,
            ]);

        $waivers = DB::table('teams')->where('programme_id', $programmeId)->whereNotNull('waiver_reason')
            ->get(['id as team_id', 'waiver_reason', 'waived_by', 'waived_at']);

        return response()->json([
            'capacity' => [
                'capacity' => $cap->capacity ?? null,
                'claimed' => $cap->claimed ?? null,
                'free' => $cap !== null ? (int) $cap->capacity - (int) $cap->claimed : null,
                'pool_depth' => $poolDepth,
                'formation_deadline_on' => $rules['formation_deadline_on'] ?? null,
            ],
            'confirm_log' => $confirmLog,
            'exception_ledger' => $exceptions,
            'waiver_register' => $waivers,
        ]);
    }
}
