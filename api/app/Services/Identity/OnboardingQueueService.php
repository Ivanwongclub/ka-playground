<?php

namespace App\Services\Identity;

use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S04C STEP 4 — the ONE queue (2.28 Q4/Q5). Approval latency is the product's
 * front door, so pending work is ONE surface per approver: pending accounts
 * (submitted registrations), pending links (guardian_links awaiting the link
 * decision), and held claims. Every row shows its age. What a reviewer sees is
 * scoped by RLS (school admin = own routed; academy ops = all) — this service
 * only queries; the policies do the scoping.
 *
 * A daily sweep escalates anything past the threshold into onboarding_exceptions
 * ("a school not keeping up is visible to the academy"); queue.escalation_liveness
 * proves the sweep keeps up.
 */
class OnboardingQueueService
{
    /** Days a queue item may wait before it escalates (configurable default). */
    public const ESCALATION_THRESHOLD_DAYS = 7;

    public function __construct(private readonly AuditService $audit) {}

    /** The per-approver queue, RLS-scoped, every row aged. Runs in the reviewer's context. */
    public function queue(): array
    {
        $age = fn ($ts) => (int) now()->diffInDays($ts, absolute: true);

        $accounts = DB::table('registration_requests')->where('status', 'submitted')->orderBy('created_at')
            ->get(['id', 'kind', 'routing', 'applicant_name', 'created_at'])
            ->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'routing' => $r->routing, 'applicant_name' => $r->applicant_name, 'age_days' => $age($r->created_at)])->all();

        // S-UX3-1: additive student_name/guardian_name (LEFT — never drop a pending row) so the
        // approver names both parties before granting access (OD-28 decision-safety, not cosmetics).
        $links = DB::table('guardian_links as gl')
            ->leftJoin('users as s', 's.id', '=', 'gl.student_id')
            ->leftJoin('users as g', 'g.id', '=', 'gl.guardian_id')
            ->where('gl.status', 'pending_approval')->orderBy('gl.created_at')
            ->get(['gl.id', 'gl.student_id', 'gl.guardian_id', 'gl.origin', 'gl.created_at', 's.name as student_name', 'g.name as guardian_name'])
            ->map(fn ($r) => ['id' => $r->id, 'student_id' => $r->student_id, 'guardian_id' => $r->guardian_id, 'student_name' => $r->student_name, 'guardian_name' => $r->guardian_name, 'origin' => $r->origin, 'age_days' => $age($r->created_at)])->all();

        // S-FIX-UX-1 D6: additive counterpart_name + expires_at (deadline) so the held-claims table
        // can name the party and render a deadline via formatHkt — display-only, no held-link state change.
        $held = DB::table('held_links')->where('status', 'held')->orderBy('created_at')
            ->get(['id', 'counterpart_email', 'counterpart_name', 'expires_at', 'created_at'])
            ->map(fn ($r) => ['id' => $r->id, 'counterpart_email' => $r->counterpart_email, 'counterpart_name' => $r->counterpart_name, 'expires_at' => $r->expires_at, 'age_days' => $age($r->created_at)])->all();

        return ['threshold_days' => self::ESCALATION_THRESHOLD_DAYS, 'accounts' => $accounts, 'links' => $links, 'held' => $held];
    }

    /**
     * The daily sweep (console/system): raise an open exception for any pending
     * item past the threshold that has none. Idempotent — the partial unique index
     * makes a second raise a no-op. @return int newly raised exceptions
     */
    public function escalate(): int
    {
        $cutoff = now()->subDays(self::ESCALATION_THRESHOLD_DAYS);
        $raised = 0;

        $sets = [
            'registration_request' => DB::table('registration_requests')->where('status', 'submitted')->where('created_at', '<', $cutoff)->pluck('created_at', 'id'),
            'guardian_link' => DB::table('guardian_links')->where('status', 'pending_approval')->where('created_at', '<', $cutoff)->pluck('created_at', 'id'),
            'held_link' => DB::table('held_links')->where('status', 'held')->where('created_at', '<', $cutoff)->pluck('created_at', 'id'),
        ];

        foreach ($sets as $type => $items) {
            foreach ($items as $id => $createdAt) {
                $open = DB::table('onboarding_exceptions')->where('subject_type', $type)->where('subject_id', $id)->where('status', 'open')->exists();
                if ($open) {
                    continue;
                }
                $ageDays = (int) now()->diffInDays($createdAt, absolute: true);
                $exId = (string) Str::uuid7();
                DB::table('onboarding_exceptions')->insert([
                    'id' => $exId, 'subject_type' => $type, 'subject_id' => $id, 'age_days' => $ageDays,
                    'reason' => "Pending {$type} unactioned for {$ageDays} days (threshold ".self::ESCALATION_THRESHOLD_DAYS.')',
                    'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record('onboarding_exception', $exId, 'onboarding.escalated',
                    toState: 'open', payloadAfter: ['subject_type' => $type, 'subject_id' => $id, 'age_days' => $ageDays]);
                $raised++;
            }
        }

        return $raised;
    }
}
