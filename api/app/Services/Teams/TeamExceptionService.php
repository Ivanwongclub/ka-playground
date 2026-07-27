<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S05 STEP 3 — the exception ledger's raise/resolve primitives (the FR066 family
 * for team formation). Callers run these INSIDE a system elevation (te_insert /
 * te_update are system-only); this class holds no elevation of its own — it is
 * the shared write path for the deadline job, matching, and the backstop.
 * STEP 4 reuses raise() for below_min / lapse.
 */
class TeamExceptionService
{
    public function __construct(private readonly AuditService $audit) {}

    /** Raise an open exception once per (type, team|enrolment) — never duplicate an open one. Returns id or null if one is already open. */
    public function raise(int $programmeId, string $type, ?string $teamId = null, ?string $enrolmentId = null, ?string $reason = null, ?\DateTimeInterface $backstopAt = null, ?User $by = null): ?string
    {
        $already = DB::table('team_exceptions')->where('programme_id', $programmeId)->where('type', $type)->where('status', 'open')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->when($enrolmentId !== null, fn ($q) => $q->where('enrolment_id', $enrolmentId))
            ->exists();
        if ($already) {
            return null;
        }
        $id = (string) Str::uuid7();
        DB::table('team_exceptions')->insert([
            'id' => $id, 'programme_id' => $programmeId, 'type' => $type,
            'team_id' => $teamId, 'enrolment_id' => $enrolmentId, 'status' => 'open',
            'reason' => $reason, 'backstop_at' => $backstopAt, 'created_by' => $by?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('team_exception', $id, "team_exception.{$type}",
            toState: 'open', reason: $reason, programmeId: $programmeId,
            payloadAfter: ['team_id' => $teamId, 'enrolment_id' => $enrolmentId, 'backstop_at' => $backstopAt?->format('c')], actor: $by);

        return $id;
    }

    /** Resolve every open exception matching the target (by team or enrolment), with an outcome. */
    public function resolveOpenFor(int $programmeId, string $target, string $targetId, string $resolution, string $status = 'resolved', ?User $by = null, ?string $type = null): void
    {
        $rows = DB::table('team_exceptions')->where('programme_id', $programmeId)->where('status', 'open')
            ->where($target === 'team' ? 'team_id' : 'enrolment_id', $targetId)
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->get();
        foreach ($rows as $row) {
            DB::table('team_exceptions')->where('id', $row->id)->update([
                'status' => $status, 'resolution' => $resolution,
                'resolved_by' => $by?->id, 'resolved_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('team_exception', $row->id, 'team_exception.resolved',
                fromState: 'open', toState: $status, reason: $resolution, programmeId: $programmeId,
                payloadAfter: ['resolution' => $resolution], actor: $by);
        }
    }
}
