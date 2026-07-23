<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditService;
use App\Services\Reconciliation\ReconciliationRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The nightly reconciliation suite (Spec P3 / SR010) — the platform's spine.
 *
 * Exit codes (CI depends on these):
 *   0  at least one assertion ran AND all passed
 *   1  any failure, any assertion error, or an EMPTY MATCH (a typo'd --tag
 *      must never silently pass a sprint gate)
 *
 * There is deliberately no skip/disable/known-failing option (CLAUDE.md §2.7).
 * Every check runs read-only inside an always-rolled-back transaction.
 */
class ReconcileRun extends Command
{
    protected $signature = 'reconcile:run {--tag= : Run only assertions carrying this sprint tag}';

    protected $description = 'Run the nightly reconciliation assertions (all, or one sprint tag)';

    public function handle(ReconciliationRegistry $registry, AuditService $audit): int
    {
        $tag = $this->option('tag');
        $runId = (string) Str::uuid7();
        $ranAt = now();
        $assertions = $registry->matching($tag);

        if ($assertions === []) {
            $scope = $tag === null ? 'registry is empty' : "tag '{$tag}' matches no registered assertion";
            $this->error("RECONCILE FAIL — {$scope}. Zero assertions is a failure, not a pass.");
            $this->logRow($runId, '_run', $tag, false, "empty match: {$scope}", null, 0, $ranAt);
            $audit->record(
                entityType: 'reconciliation_run',
                entityId: $runId,
                action: 'reconciliation.empty_match',
                reason: $scope,
            );
            Log::critical('Reconciliation run matched zero assertions', ['run_id' => $runId, 'tag' => $tag]);

            return self::FAILURE;
        }

        $failures = 0;
        foreach ($assertions as $assertion) {
            $start = hrtime(true);
            try {
                // Read-only guard: whatever the check does is rolled back
                DB::beginTransaction();
                try {
                    $result = $assertion->check();
                } finally {
                    DB::rollBack();
                }
            } catch (Throwable $e) {
                $result = \App\Services\Reconciliation\AssertionResult::fail(
                    'assertion threw: '.$e->getMessage()
                );
            }
            $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

            $this->logRow(
                $runId,
                $assertion->key(),
                $tag,
                $result->passed,
                $result->details,
                $assertion->cites(),
                $durationMs,
                $ranAt,
                $assertion->tags(),
            );

            if ($result->passed) {
                $this->info(sprintf('  PASS  %-28s [%s] %s', $assertion->key(), $assertion->cites(), $assertion->proves()));
            } else {
                $failures++;
                $this->error(sprintf('  FAIL  %-28s [%s] %s', $assertion->key(), $assertion->cites(), $assertion->proves()));
                $this->error("        {$result->details}");
                // SR010: mismatch → audit event + Academy Admin alert. The
                // dashboard alert reads reconciliation_log; the K-engine
                // notification event lands in S09.
                $audit->record(
                    entityType: 'reconciliation_assertion',
                    entityId: $assertion->key(),
                    action: 'reconciliation.mismatch',
                    reason: $result->details,
                    payloadAfter: ['cites' => $assertion->cites(), 'run_id' => $runId],
                );
                Log::critical('Reconciliation assertion failed', [
                    'assertion' => $assertion->key(),
                    'cites' => $assertion->cites(),
                    'details' => $result->details,
                    'run_id' => $runId,
                ]);
            }
        }

        $total = count($assertions);
        $summary = sprintf('%d assertion(s), %d passed, %d failed', $total, $total - $failures, $failures);
        $this->logRow($runId, '_run', $tag, $failures === 0, $summary, null, 0, $ranAt);
        $this->newLine();
        $this->line(($failures === 0 ? 'RECONCILE PASS — ' : 'RECONCILE FAIL — ').$summary);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function logRow(
        string $runId,
        string $key,
        ?string $tag,
        bool $passed,
        string $details,
        ?string $cites,
        int $durationMs,
        \Illuminate\Support\Carbon $ranAt,
        array $tags = [],
    ): void {
        DB::table('reconciliation_log')->insert([
            'id' => (string) Str::uuid7(),
            'run_id' => $runId,
            'assertion_key' => $key,
            'tags' => json_encode($tags !== [] ? $tags : ($tag !== null ? [$tag] : [])),
            'passed' => $passed,
            'details' => $details,
            'cites' => $cites,
            'duration_ms' => $durationMs,
            'ran_at' => $ranAt,
        ]);
    }
}
