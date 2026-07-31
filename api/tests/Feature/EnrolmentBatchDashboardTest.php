<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Reconciliation\Assertions\BatchNoStuckAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S04E STEP 3 — batch dashboard (H4). Lists a school's batches; drill-down
 * enriches enrolled rows with LIVE enrolment status (a second source, not a
 * re-derivation); the not_enrolled rows are the S04D join-back; failed batches
 * are the FR066 ledger (D-13). RLS scopes it to the owning school.
 */
class EnrolmentBatchDashboardTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->school = $this->sys(fn () => School::create(['name_en' => 'S'.Str::random(4), 'name_tc' => '甲', 'name_sc' => '甲']));
        $this->admin = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $this->admin->id, 'school_id' => $this->school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
        $this->programme = $this->sys(fn () => Programme::create(['code' => 'DB-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK', 'status' => 'published']));
    }

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->reset();
        }
    }

    private function batch(string $status, ?string $failureReason = null, ?School $school = null): string
    {
        return $this->sys(function () use ($status, $failureReason, $school) {
            $id = (string) Str::uuid7();
            DB::table('enrolment_batches')->insert([
                'id' => $id, 'school_id' => ($school ?? $this->school)->id, 'programme_id' => $this->programme->id,
                'status' => $status, 'failure_reason' => $failureReason, 'total_rows' => 0,
                'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    private function as(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    // ── index lists the school's batches; failed ones are the exceptions ──────

    public function test_index_lists_batches_and_surfaces_failed_as_exceptions(): void
    {
        $ok = $this->batch('ready');
        $bad = $this->batch('failed', 'programme is not open for enrolment');

        $this->as($this->admin);
        $res = $this->getJson('/api/school/enrolment-batches')->assertStatus(200);
        $this->assertEqualsCanonicalizing([$ok, $bad], collect($res->json('batches'))->pluck('batch_id')->all());
        $this->assertSame([$bad], collect($res->json('exceptions'))->pluck('batch_id')->all(), 'failed batch is the FR066 ledger');
        $this->assertSame('programme is not open for enrolment', $res->json('exceptions.0.reason'));
    }

    // ── drill-down enriches enrolled rows with LIVE enrolment status ──────────

    public function test_show_enriches_enrolled_rows_with_live_enrolment_status_and_lists_not_enrolled(): void
    {
        $batchId = $this->batch('partially_complete');
        // one enrolled row (with a real enrolment) + one not_enrolled row
        $enrolmentId = $this->sys(function () use ($batchId) {
            $student = User::factory()->create(['role' => 'student']);
            $guardian = User::factory()->create(['role' => 'guardian']);
            $eid = (string) Str::uuid7();
            DB::table('enrolments')->insert(['id' => $eid, 'programme_id' => $this->programme->id, 'student_id' => $student->id, 'acting_guardian_id' => $guardian->id, 'status' => 'pending_consent', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('enrolment_batch_rows')->insert(['id' => (string) Str::uuid7(), 'batch_id' => $batchId, 'school_id' => $this->school->id, 'row_number' => 1, 'name' => 'Ann', 'email' => 'ann@x.test', 'status' => 'enrolled', 'disposition' => 'match_existing', 'matched_user_id' => $student->id, 'enrolment_id' => $eid, 'committed' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('enrolment_batch_rows')->insert(['id' => (string) Str::uuid7(), 'batch_id' => $batchId, 'school_id' => $this->school->id, 'row_number' => 2, 'name' => 'Ben', 'email' => 'ben@x.test', 'status' => 'not_enrolled', 'reason' => 'awaiting guardian & consent', 'committed' => false, 'created_at' => now(), 'updated_at' => now()]);

            return $eid;
        });

        $this->as($this->admin);
        $res = $this->getJson("/api/school/enrolment-batches/{$batchId}")->assertStatus(200);
        $enrolledRow = collect($res->json('rows'))->firstWhere('name', 'Ann');
        $this->assertSame('pending_consent', $enrolledRow['enrolment_status'], 'live enrolment status enriched');
        // the not_enrolled join-back list
        $this->assertSame(['ben@x.test'], collect($res->json('not_enrolled'))->pluck('email')->all());
        $this->assertSame('awaiting guardian & consent', $res->json('not_enrolled.0.reason'));

        // the live source is separate from the stored disposition: advance the enrolment → the read follows
        $this->sys(fn () => DB::table('enrolments')->where('id', $enrolmentId)->update(['status' => 'in_pool']));
        $res2 = $this->getJson("/api/school/enrolment-batches/{$batchId}")->assertStatus(200);
        $this->assertSame('in_pool', collect($res2->json('rows'))->firstWhere('name', 'Ann')['enrolment_status'], 'reads live, not the frozen row');
    }

    // ── five-branch: another school's admin sees nothing ──────────────────────

    public function test_another_schools_admin_sees_no_batches(): void
    {
        $mine = $this->batch('ready');
        $otherSchool = $this->sys(fn () => School::create(['name_en' => 'Other'.Str::random(3), 'name_tc' => '乙', 'name_sc' => '乙']));
        $otherAdmin = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $otherAdmin->id, 'school_id' => $otherSchool->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        $this->as($otherAdmin);
        $this->assertSame([], $this->getJson('/api/school/enrolment-batches')->assertStatus(200)->json('batches'), 'RLS: no cross-school batches');
        $this->getJson("/api/school/enrolment-batches/{$mine}")->assertStatus(404);
    }

    // ── batches.no_stuck teeth (with a reachable healthy state) ───────────────

    public function test_no_stuck_reds_on_a_stale_transient_batch_then_greens(): void
    {
        $this->assertTrue($this->sys(fn () => (new BatchNoStuckAssertion)->check()->passed), 'green with a fresh batch');
        $stuckId = $this->batch('committing');
        // a fresh committing batch is HEALTHY — must not false-red
        $this->assertTrue($this->sys(fn () => (new BatchNoStuckAssertion)->check()->passed), 'a legitimately-long batch does not false-red');

        // age it past the window → red
        $this->sys(fn () => DB::table('enrolment_batches')->where('id', $stuckId)->update(['updated_at' => now()->subMinutes(31)]));
        $this->assertFalse($this->sys(fn () => (new BatchNoStuckAssertion)->check()->passed), 'stuck past the window reds');

        // it completes → green (reaches a terminal state)
        $this->sys(fn () => DB::table('enrolment_batches')->where('id', $stuckId)->update(['status' => 'complete']));
        $this->assertTrue($this->sys(fn () => (new BatchNoStuckAssertion)->check()->passed));
    }
}
