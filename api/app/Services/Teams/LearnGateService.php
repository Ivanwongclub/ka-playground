<?php

namespace App\Services\Teams;

use Illuminate\Support\Facades\DB;

/**
 * S06-4 (OD-12) — the Learn gate's computed eligibility. Per-student: a member
 * qualifies when their attended ratio (attended / marked) across the programme's
 * sessions meets `certification_rules.attendance_threshold_pct`. Per-team: the
 * team is Learn-eligible when the share of qualifying active members meets
 * `team_gate_pass_pct`. A team with NO marked attendance is 0/0 — NOT yet
 * assessable (never silently "eligible"). This computes; the gate (Option B,
 * TrackerService::approveGate) uses it as a HARD PRECONDITION to a teacher's
 * approval — it does not auto-pass.
 */
class LearnGateService
{
    public const DEFAULT_ATTENDANCE_PCT = 70;

    public const DEFAULT_TEAM_GATE_PCT = 60;

    /** @return array{eligible: bool, assessable: bool, qualifying: int, active_members: int, attendance_threshold_pct: int, team_gate_pass_pct: int} */
    public function eligibility(object $team): array
    {
        $rules = DB::table('certification_rules')->where('programme_id', $team->programme_id)->first();
        $attendancePct = (int) ($rules->attendance_threshold_pct ?? self::DEFAULT_ATTENDANCE_PCT);
        $teamGatePct = (int) ($rules->team_gate_pass_pct ?? self::DEFAULT_TEAM_GATE_PCT);

        // PRODUCT RULING (Leo, 2026-07-29): SUSPENDED members are EXCLUDED from the
        // Learn denominator — a non-payment lapse must not penalise the team's learning
        // pass rate. Only status='active' members count (removed are already out).
        $studentIds = DB::table('team_members')->where('team_id', $team->id)->where('status', 'active')->pluck('student_id');
        $activeMembers = $studentIds->count();

        $base = [
            'assessable' => false, 'eligible' => false, 'qualifying' => 0, 'active_members' => $activeMembers,
            'attendance_threshold_pct' => $attendancePct, 'team_gate_pass_pct' => $teamGatePct,
        ];
        if ($activeMembers === 0) {
            return $base;
        }

        // per-member attended / marked across the programme's sessions
        $rows = DB::table('session_bookings as sb')
            ->join('programme_sessions as ps', function ($j) use ($team): void {
                $j->on('ps.id', '=', 'sb.session_id')->where('ps.programme_id', '=', $team->programme_id);
            })
            ->whereIn('sb.student_id', $studentIds)
            ->selectRaw("sb.student_id,
                COUNT(*) FILTER (WHERE sb.status = 'attended') AS attended,
                COUNT(*) FILTER (WHERE sb.status IN ('attended','no_show')) AS marked")
            ->groupBy('sb.student_id')->get()->keyBy('student_id');

        $anyMarked = false;
        $qualifying = 0;
        foreach ($studentIds as $sid) {
            $r = $rows->get($sid);
            $marked = (int) ($r->marked ?? 0);
            $attended = (int) ($r->attended ?? 0);
            if ($marked > 0) {
                $anyMarked = true;
                if ($attended * 100 >= $attendancePct * $marked) {
                    $qualifying++;
                }
            }
        }

        // 0/0 (no attendance recorded anywhere on the team) → not yet assessable
        if (! $anyMarked) {
            return $base;
        }
        $eligible = $qualifying * 100 >= $teamGatePct * $activeMembers;

        return array_merge($base, ['assessable' => true, 'eligible' => $eligible, 'qualifying' => $qualifying]);
    }
}
