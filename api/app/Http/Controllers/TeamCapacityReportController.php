<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * S05-6 audit element — the Team & Capacity Report (the client-facing product
 * screen, distinct from AUDIT.md). Per programme: capacity vs claimed vs pool
 * depth vs formation deadline; the Team Formation log (approver + seat math, from the
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

        // Backend delta B5 — ADDITIVE names (S-UX2b), each a double-gated LEFT join to users_read
        // (NULL when the caller may not see that user; count-preserving). The report runs under the
        // caller's RLS — NO elevation. approver_name on the confirm log's actor_id:
        $confirmLog = DB::table('audit_events as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.programme_id', $programmeId)->where('a.action', 'team.confirmed')
            ->orderByDesc('a.occurred_at')
            ->get(['a.entity_id as team_id', 'a.actor_id as approver_id', 'u.name as approver_name', 'a.payload_after', 'a.occurred_at'])
            ->map(fn ($r) => [
                'team_id' => $r->team_id,
                'approver_id' => $r->approver_id,
                'approver_name' => $r->approver_name,
                'occurred_at' => $r->occurred_at,
                'seats_claimed' => json_decode((string) $r->payload_after, true)['seats_claimed'] ?? null,
                'member_count' => json_decode((string) $r->payload_after, true)['member_count'] ?? null,
            ]);

        $now = now();
        // B5 — the exception ledger's bare enrolment_id gains the student's name (exception → enrolment → user).
        $exceptions = DB::table('team_exceptions as x')
            ->leftJoin('enrolments as e', 'e.id', '=', 'x.enrolment_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.student_id')
            ->where('x.programme_id', $programmeId)
            ->orderByDesc('x.created_at')
            ->get(['x.id', 'x.type', 'x.status', 'x.team_id', 'x.enrolment_id', 'u.name as student_name', 'x.backstop_at', 'x.resolution', 'x.created_at'])
            ->map(fn ($r) => (array) $r + [
                // days until (positive) or past (negative) the 90-day backstop, for parked rows
                'days_to_backstop' => $r->backstop_at !== null
                    ? (int) floor(($now->getTimestamp() - strtotime((string) $r->backstop_at)) / -86400)
                    : null,
            ]);

        // B5 — the waiver register's bare waived_by gains the waiving admin's name.
        $waivers = DB::table('teams as t')
            ->leftJoin('users as u', 'u.id', '=', 't.waived_by')
            ->where('t.programme_id', $programmeId)->whereNotNull('t.waiver_reason')
            ->get(['t.id as team_id', 't.waiver_reason', 't.waived_by', 'u.name as waived_by_name', 't.waived_at']);

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
