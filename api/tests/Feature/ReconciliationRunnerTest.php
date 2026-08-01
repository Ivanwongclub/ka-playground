<?php

namespace Tests\Feature;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use App\Services\Reconciliation\ReconciliationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_run_passes_with_registered_assertions(): void
    {
        $this->artisan('reconcile:run')
            ->expectsOutputToContain('PASS  audit.immutability')
            ->expectsOutputToContain('PASS  audit.trigger_enabled')
            ->expectsOutputToContain('PASS  authz.permission_matrix')
            ->expectsOutputToContain('PASS  links.guardian_coverage')
            ->expectsOutputToContain('PASS  authz.consent_sign_exclusive')
            ->expectsOutputToContain('PASS  authz.member_directory_exclusive')
            ->expectsOutputToContain('PASS  scope.coverage')
            ->expectsOutputToContain('PASS  programmes.version_immutability')
            ->expectsOutputToContain('PASS  programmes.published_completeness')
            ->expectsOutputToContain('PASS  teams.one_default_lobby')
            ->expectsOutputToContain('PASS  scope.public_context_confinement')
            ->expectsOutputToContain('PASS  links.activation_audited')
            ->expectsOutputToContain('PASS  links.no_unverified_materialisation')
            ->expectsOutputToContain('PASS  queue.escalation_liveness')
            ->expectsOutputToContain('PASS  account.provenance')
            ->expectsOutputToContain('PASS  links.no_active_without_approval')
            ->expectsOutputToContain('PASS  links.guardian_addition_visibility')
            ->expectsOutputToContain('PASS  links.vouch_scope')
            ->expectsOutputToContain('PASS  batches.scan_gated')
            ->expectsOutputToContain('PASS  batches.row_conservation')
            ->expectsOutputToContain('PASS  batches.no_stuck')
            ->expectsOutputToContain('PASS  obligations.payer_matches_programme')
            ->expectsOutputToContain('RECONCILE PASS — 51 assertion(s), 51 passed, 0 failed')
            ->assertExitCode(0);

        $this->assertSame(51, DB::table('reconciliation_log')->where('passed', true)->where('assertion_key', '!=', '_run')->count());
        $this->assertSame(1, DB::table('reconciliation_log')->where('assertion_key', '_run')->count());
    }

    public function test_tag_filter_runs_matching_assertions(): void
    {
        $this->artisan('reconcile:run', ['--tag' => 'S00'])->assertExitCode(0);
    }

    public function test_empty_match_is_a_failure_not_a_pass(): void
    {
        $this->artisan('reconcile:run', ['--tag' => 'NOSUCHTAG'])
            ->expectsOutputToContain('Zero assertions is a failure, not a pass')
            ->assertExitCode(1);

        $this->assertDatabaseHas('reconciliation_log', ['assertion_key' => '_run', 'passed' => false]);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'reconciliation_run',
            'action' => 'reconciliation.empty_match',
        ]);
    }

    public function test_failing_assertion_fails_run_and_raises_alert_and_audit(): void
    {
        app(ReconciliationRegistry::class)->register(new class implements Assertion
        {
            public function key(): string
            {
                return 'test.always_fails';
            }

            public function proves(): string
            {
                return 'nothing — deliberate failure for the runner test';
            }

            public function cites(): string
            {
                return 'TEST';
            }

            public function tags(): array
            {
                return ['TESTONLY'];
            }

            public function check(): AssertionResult
            {
                return AssertionResult::fail('deliberate failure');
            }
        });

        $this->artisan('reconcile:run', ['--tag' => 'TESTONLY'])
            ->expectsOutputToContain('FAIL  test.always_fails')
            ->assertExitCode(1);

        $this->assertDatabaseHas('reconciliation_log', ['assertion_key' => 'test.always_fails', 'passed' => false]);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'reconciliation_assertion',
            'entity_id' => 'test.always_fails',
            'action' => 'reconciliation.mismatch',
        ]);
    }

    public function test_assertion_writes_are_rolled_back(): void
    {
        app(ReconciliationRegistry::class)->register(new class implements Assertion
        {
            public function key(): string
            {
                return 'test.defective_writer';
            }

            public function proves(): string
            {
                return 'nothing — deliberate write-attempt for the rollback-guard test';
            }

            public function cites(): string
            {
                return 'TEST';
            }

            public function tags(): array
            {
                return ['WRITERTEST'];
            }

            public function check(): AssertionResult
            {
                DB::table('uploads')->insert([
                    'id' => '019f0000-0000-7000-8000-000000000001',
                    'context' => 'document', 'disk' => 'local', 'path' => 'x',
                    'original_name' => 'x', 'mime_type' => 'application/pdf',
                    'size_bytes' => 1, 'sha256' => str_repeat('0', 64),
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return AssertionResult::pass('wrote a row (defect)');
            }
        });

        $this->artisan('reconcile:run', ['--tag' => 'WRITERTEST'])->assertExitCode(0);

        // The defective write must NOT have persisted — the runner rolls back
        $this->assertDatabaseMissing('uploads', ['id' => '019f0000-0000-7000-8000-000000000001']);
    }

    public function test_runner_offers_no_skip_or_disable_affordance(): void
    {
        $command = $this->app->make(\App\Console\Commands\ReconcileRun::class);
        $definition = $command->getDefinition();

        foreach (['skip', 'disable', 'exclude', 'known-failing', 'allow-failure'] as $forbidden) {
            $this->assertFalse(
                $definition->hasOption($forbidden),
                "reconcile:run must not offer --{$forbidden} (CLAUDE.md §2.7)",
            );
        }
    }
}
