<?php

namespace App\Services\Sessions;

use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;

/**
 * S06-7 — the SYSTEM clock for sessions (2.3). A scheduled job advances sessions
 * past their time so the lifecycle isn't stuck at Published forever: a
 * published/full session whose start has passed → In Progress; an In Progress
 * session whose end has passed → Completed. This is the mechanism that makes
 * `sessions.no_stale_published` reachable (a past session becomes terminal).
 * Attendance windows (In Progress/Completed) open automatically as a result.
 */
class SessionAdvancementService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    public function run(?\DateTimeInterface $asOf = null): array
    {
        $now = $asOf ?? now();

        return $this->scope->asSystem(
            'Session advancement job (S06-7, 2.3): the SYSTEM advances sessions past their time — published/full → in_progress at start, in_progress → completed at end. programme_sessions.status is a system-only write; this is the scheduled actor. Only sessions whose time has passed are touched.',
            function () use ($now): array {
                $started = 0;
                foreach (DB::table('programme_sessions')->whereIn('status', ['published', 'full'])->where('starts_at', '<=', $now)->orderBy('id')->get(['id', 'programme_id', 'status']) as $s) {
                    DB::table('programme_sessions')->where('id', $s->id)->update(['status' => 'in_progress', 'updated_at' => now()]);
                    $this->audit->record('session', $s->id, 'session.in_progress', fromState: $s->status, toState: 'in_progress',
                        reason: 'session start reached (2.3)', programmeId: (int) $s->programme_id);
                    $started++;
                }
                $completed = 0;
                foreach (DB::table('programme_sessions')->where('status', 'in_progress')->where('ends_at', '<=', $now)->orderBy('id')->get(['id', 'programme_id']) as $s) {
                    DB::table('programme_sessions')->where('id', $s->id)->update(['status' => 'completed', 'updated_at' => now()]);
                    $this->audit->record('session', $s->id, 'session.completed', fromState: 'in_progress', toState: 'completed',
                        reason: 'session end reached (2.3)', programmeId: (int) $s->programme_id);
                    $completed++;
                }

                return ['started' => $started, 'completed' => $completed];
            },
        );
    }
}
