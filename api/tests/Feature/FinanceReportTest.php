<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Reconciliation\Assertions\BudgetActualsMatchAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S07 STEP 4 — the Team Finance Verification Report (P&L, budget/actual/verified,
 * aging, evidence drill-down) + finance.budget_actuals_match.
 */
class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    private int $programmeId;

    private string $lobby;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->programmeId = $this->sys(fn () => DB::table('programmes')->insertGetId(['code' => 'FR'.Str::random(4), 'name_en' => 'a', 'name_tc' => 'a', 'name_sc' => 'a', 'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]));
        $this->lobby = $this->sys(function () {
            $l = (string) Str::uuid7();
            DB::table('team_categories')->insert(['id' => $l, 'programme_id' => $this->programmeId, 'name_en' => 'O', 'name_tc' => 'O', 'name_sc' => 'O', 'assignment_rule' => 'open', 'school_id' => null, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

            return $l;
        });
    }

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->setSystem();
        }
    }

    private function cleanUpload(int $by): string
    {
        return $this->sys(function () use ($by) {
            $id = (string) Str::uuid7();
            DB::table('uploads')->insert(['id' => $id, 'context' => 'evidence', 'disk' => 'local', 'path' => 'uploads/clean/x.jpg', 'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'status' => 'clean', 'uploaded_by' => $by, 'scanned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    /** A confirmed team with an active member, an active budget + line, and verified income/expense. */
    private function seedTeamWithFinance(): array
    {
        return $this->sys(function () {
            $member = User::factory()->create(['role' => 'student']);
            $verifier = User::factory()->create(['role' => 'student']);
            $team = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $team, 'programme_id' => $this->programmeId, 'category_id' => $this->lobby, 'name' => 'T', 'status' => 'confirmed', 'created_by' => $member->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([$member, $verifier] as $u) {
                $eid = (string) Str::uuid7();
                DB::table('enrolments')->insert(['id' => $eid, 'programme_id' => $this->programmeId, 'student_id' => $u->id, 'acting_guardian_id' => $u->id, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'team_id' => $team, 'enrolment_id' => $eid, 'category_id' => $this->lobby, 'student_id' => $u->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
            $bid = (string) Str::uuid7();
            DB::table('team_budgets')->insert(['id' => $bid, 'team_id' => $team, 'status' => 'active', 'currency' => 'HKD', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            app(\App\Services\Audit\AuditService::class)->record('team_budget', $bid, 'team_budget.approved', toState: 'active', payloadAfter: ['team_id' => $team]);
            $line = (string) Str::uuid7();
            DB::table('budget_lines')->insert(['id' => $line, 'budget_id' => $bid, 'team_id' => $team, 'category' => 'materials', 'name' => 'M', 'planned_amount_minor' => 50000, 'currency' => 'HKD', 'created_at' => now(), 'updated_at' => now()]);
            $ev = $this->cleanUpload($member->id);
            // a VERIFIED expense (30000) against the line + a VERIFIED income (40000)
            DB::table('team_transactions')->insert(['id' => (string) Str::uuid7(), 'team_id' => $team, 'type' => 'expense', 'amount_minor' => 30000, 'currency' => 'HKD', 'budget_line_id' => $line, 'description' => 'Poster', 'occurred_on' => '2026-05-01', 'status' => 'verified', 'recorded_by' => $member->id, 'verified_by' => $verifier->id, 'evidence_upload_id' => $ev, 'recorded_at' => now(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_transactions')->insert(['id' => (string) Str::uuid7(), 'team_id' => $team, 'type' => 'income', 'amount_minor' => 40000, 'currency' => 'HKD', 'description' => 'Sponsor', 'occurred_on' => '2026-05-01', 'status' => 'verified', 'recorded_by' => $member->id, 'verified_by' => $verifier->id, 'evidence_upload_id' => $ev, 'recorded_at' => now(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return ['team' => $team, 'member' => $member, 'line' => $line];
        });
    }

    // ── the report: P&L + budget/actual/verified + evidence drill-down ────────

    public function test_finance_report_shows_pnl_and_actuals_with_evidence(): void
    {
        $f = $this->seedTeamWithFinance();
        Sanctum::actingAs($f['member']);
        $r = $this->getJson("/api/teams/{$f['team']}/finance-report")->assertStatus(200);

        $r->assertJsonPath('pnl.verified_income_minor', 40000)
            ->assertJsonPath('pnl.verified_expense_minor', 30000)
            ->assertJsonPath('pnl.net_minor', 10000);
        $line = collect($r->json('budget.lines'))->firstWhere('name', 'M');
        $this->assertSame(50000, $line['planned_minor']);
        $this->assertSame(30000, $line['actual_minor']);
        $this->assertSame(30000, $line['verified_minor']);
        // every transaction carries a drill-down handle to its evidence
        $this->assertTrue(collect($r->json('transactions'))->every(fn ($t) => $t['evidence_upload_id'] !== null));
    }

    // ── five-branch: a non-member sees no team-finance data (RLS) ─────────────

    public function test_a_non_member_sees_empty_finance_data(): void
    {
        $f = $this->seedTeamWithFinance();
        $stranger = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($stranger);
        $r = $this->getJson("/api/teams/{$f['team']}/finance-report")->assertStatus(200);
        // RLS hides the team's budget + transactions from a non-member
        $this->assertNull($r->json('budget'));
        $this->assertSame([], $r->json('transactions'));
        $this->assertSame(0, $r->json('pnl.verified_income_minor'));
    }

    // ── budget_actuals_match teeth ────────────────────────────────────────────

    public function test_budget_actuals_match_reds_on_a_cross_team_or_unapproved_line(): void
    {
        $f = $this->seedTeamWithFinance();
        $this->assertTrue($this->sys(fn () => (new BudgetActualsMatchAssertion)->check()->passed), 'green: recorded spend against own active budget');

        // forge a recorded expense referencing the line but under a DIFFERENT team → red
        $this->sys(function () use ($f) {
            $otherMember = User::factory()->create(['role' => 'student']);
            $otherTeam = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $otherTeam, 'programme_id' => $this->programmeId, 'category_id' => $this->lobby, 'name' => 'T2', 'status' => 'confirmed', 'created_by' => $otherMember->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_transactions')->insert(['id' => (string) Str::uuid7(), 'team_id' => $otherTeam, 'type' => 'expense', 'amount_minor' => 1000, 'currency' => 'HKD', 'budget_line_id' => $f['line'], 'description' => 'leak', 'occurred_on' => '2026-05-01', 'status' => 'recorded', 'recorded_by' => $otherMember->id, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->assertFalse($this->sys(fn () => (new BudgetActualsMatchAssertion)->check()->passed), 'reds: a recorded txn against another team\'s budget line');
    }
}
