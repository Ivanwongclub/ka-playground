<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Identity\OnboardingQueueService;
use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04C STEP 4 — the approval queue is the product; a liveness assertion (like
 * no_stale_published) proves the escalation sweep keeps up. Any pending item past
 * the threshold MUST carry an open onboarding_exception — otherwise a family is
 * stuck at the front door with nobody flagged. Its "healthy" state is reachable:
 * the daily held-links/escalation sweep raises the exceptions.
 */
class QueueEscalationLivenessAssertion implements Assertion
{
    public function key(): string
    {
        return 'queue.escalation_liveness';
    }

    public function proves(): string
    {
        return 'no pending onboarding item (submitted registration, pending link, held claim) older than the threshold is left without an open escalation exception — the queue sweep keeps up';
    }

    public function cites(): string
    {
        return '2.28 Q5 · OD-23 · S04C STEP 4';
    }

    public function tags(): array
    {
        return ['S04C'];
    }

    public function check(): AssertionResult
    {
        $t = OnboardingQueueService::ESCALATION_THRESHOLD_DAYS;
        $cutoff = now()->subDays($t)->toDateTimeString();

        $sets = [
            'registration_request' => "SELECT id FROM registration_requests WHERE status = 'submitted' AND created_at < ?",
            'guardian_link' => "SELECT id FROM guardian_links WHERE status = 'pending_approval' AND created_at < ?",
            'held_link' => "SELECT id FROM held_links WHERE status = 'held' AND created_at < ?",
        ];

        $stuck = [];
        foreach ($sets as $type => $sql) {
            foreach (DB::select($sql, [$cutoff]) as $row) {
                $hasEx = DB::selectOne(
                    "SELECT 1 AS ok FROM onboarding_exceptions WHERE subject_type = ? AND subject_id = ? AND status = 'open'",
                    [$type, $row->id]
                );
                if ($hasEx === null) {
                    $stuck[] = "{$type}:{$row->id}";
                }
            }
        }

        if ($stuck !== []) {
            return AssertionResult::fail(
                count($stuck)." pending item(s) older than {$t}d with no open escalation — the queue sweep is behind (".implode(', ', array_slice($stuck, 0, 5)).')'
            );
        }

        return AssertionResult::pass("every pending item older than {$t}d carries an open escalation exception");
    }
}
