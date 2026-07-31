<?php

namespace Tests\Feature;

use App\Models\EnrolmentBatch;
use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Reconciliation\Assertions\BatchRowConservationAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S04E STEP 2 — batch commit. Intent only (OD-31): guardian-having rows enrol
 * through the EXISTING EnrolmentService (submitted → pending_consent, consent
 * issued); guardian-less rows land not_enrolled (D-8). Idempotent at the DB
 * (unique (student,programme) + committed flag); guardian eligibility is
 * re-evaluated LIVE at commit, not the frozen dry-run verdict.
 */
class EnrolmentBatchCommitTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private School $school;

    private User $admin;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'super_admin'] as $cap) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        $this->school = $this->sys(fn () => School::create(['name_en' => 'Sch'.Str::random(3), 'name_tc' => '甲', 'name_sc' => '甲']));
        $this->admin = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $this->admin->id, 'school_id' => $this->school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
        $this->programme = $this->publishedProgramme();
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

    private function publishedProgramme(): Programme
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en' => 'terms', 'zh-TC' => '條款', 'zh-SC' => '条款'] as $lang => $text) {
            $vid = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $lang, 'body_html' => "<p>{$text} {{student_name}} {{signature}}</p>"])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$vid}/publish")->assertOk();
        }
        $programme = $this->sys(fn () => Programme::create(['code' => 'BC-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']));
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();

        return $programme;
    }

    /** A student on this school's roll, optionally with an active guardian. */
    private function student(string $email, bool $withGuardian): int
    {
        return $this->sys(function () use ($email, $withGuardian) {
            $u = User::factory()->create(['role' => 'student', 'email' => $email]);
            DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $u->id, 'school_id' => $this->school->id, 'status' => 'active', 'origin' => 'registration', 'created_at' => now(), 'updated_at' => now()]);
            if ($withGuardian) {
                $g = User::factory()->create(['role' => 'guardian']);
                DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $u->id, 'guardian_id' => $g->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
            }

            return $u->id;
        });
    }

    /** Seed a READY batch with validated rows: [ [name,email,disposition], ... ]. */
    private function readyBatch(array $rows): string
    {
        return $this->sys(function () use ($rows) {
            $batchId = (string) Str::uuid7();
            DB::table('enrolment_batches')->insert([
                'id' => $batchId, 'school_id' => $this->school->id, 'programme_id' => $this->programme->id,
                'status' => 'ready', 'total_rows' => count($rows), 'created_by' => $this->admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($rows as $i => [$name, $email, $disp]) {
                DB::table('enrolment_batch_rows')->insert([
                    'id' => (string) Str::uuid7(), 'batch_id' => $batchId, 'school_id' => $this->school->id,
                    'row_number' => $i + 1, 'name' => $name, 'email' => $email, 'status' => 'validated',
                    'disposition' => $disp, 'committed' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $batchId;
        });
    }

    private function commit(string $batchId)
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/school/enrolment-batches/{$batchId}/commit");
    }

    // ── mixed batch: guardian-having enrols, guardian-less not_enrolled ────────

    public function test_mixed_batch_enrols_guardian_rows_and_marks_the_rest_not_enrolled(): void
    {
        $this->student('has@x.test', withGuardian: true);
        $this->student('noguard@x.test', withGuardian: false);
        $usersBefore = $this->sys(fn () => DB::table('users')->count());

        $batchId = $this->readyBatch([
            ['Has', 'has@x.test', 'match_existing'],     // → enrolled
            ['NoGuard', 'noguard@x.test', 'match_existing'], // → not_enrolled
            ['Fresh', 'fresh@x.test', 'new'],            // → account created, no guardian → not_enrolled
        ]);

        $res = $this->commit($batchId)->assertStatus(200);
        $this->assertSame(['enrolled' => 1, 'not_enrolled' => 2, 'skipped' => 0, 'failed' => 0, 'total' => 3], $res->json('counts'));
        $this->assertSame('partially_complete', $res->json('status'));

        // the enrolled row is INDISTINGUISHABLE from an individual enrolment:
        $sid = $this->sys(fn () => DB::table('users')->where('email', 'has@x.test')->value('id'));
        $enr = $this->sys(fn () => DB::table('enrolments')->where('student_id', $sid)->where('programme_id', $this->programme->id)->first());
        $this->assertNotNull($enr);
        $this->assertSame('pending_consent', $enr->status, 'same submitted→pending_consent path');
        $this->assertSame(1, $this->sys(fn () => DB::table('consent_requests')->where('programme_id', $this->programme->id)->where('student_id', $sid)->count()), 'consent issued to the guardian');

        // a NEW account was minted (Fresh) but not enrolled; guardian-less rows reasoned
        $this->assertSame($usersBefore + 1, $this->sys(fn () => DB::table('users')->count()), 'Fresh minted');
        $report = $this->sys(fn () => DB::table('enrolment_batch_rows')->where('batch_id', $batchId)->get()->keyBy('email'));
        $this->assertSame('not_enrolled', $report['noguard@x.test']->status);
        $this->assertSame('awaiting guardian & consent', $report['noguard@x.test']->reason);
        $this->assertSame('not_enrolled', $report['fresh@x.test']->status);
    }

    // ── idempotency is the DB's job: re-commit is a clean no-op ────────────────

    public function test_recommit_is_idempotent_no_duplicate_accounts_or_enrolments(): void
    {
        $this->student('a@x.test', withGuardian: true);
        $batchId = $this->readyBatch([['A', 'a@x.test', 'match_existing'], ['B', 'newb@x.test', 'new']]);

        $first = $this->commit($batchId)->assertStatus(200)->json('counts');
        $usersAfterFirst = $this->sys(fn () => DB::table('users')->count());
        $enrAfterFirst = $this->sys(fn () => DB::table('enrolments')->count());

        // double-commit (retry / double-click)
        $second = $this->commit($batchId)->assertStatus(200)->json('counts');

        $this->assertSame($first, $second, 'same counts');
        $this->assertSame($usersAfterFirst, $this->sys(fn () => DB::table('users')->count()), 'no duplicate accounts');
        $this->assertSame($enrAfterFirst, $this->sys(fn () => DB::table('enrolments')->count()), 'no duplicate enrolments');
        // exactly one live enrolment for the pair — the DB unique is the guarantee
        $sid = $this->sys(fn () => DB::table('users')->where('email', 'a@x.test')->value('id'));
        $this->assertSame(1, $this->sys(fn () => DB::table('enrolments')->where('student_id', $sid)->where('programme_id', $this->programme->id)->count()));
    }

    // ── the DB unique constraint (not just the app check) enforces no-dup ──────

    public function test_the_unique_constraint_itself_blocks_a_second_live_enrolment(): void
    {
        $sid = $this->student('u@x.test', withGuardian: true);
        $this->assertTrueUniqueBlocksSecond($sid);
    }

    private function assertTrueUniqueBlocksSecond(int $sid): void
    {
        $this->sys(function () use ($sid) {
            $gid = DB::table('guardian_links')->where('student_id', $sid)->value('guardian_id');
            DB::table('enrolments')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $this->programme->id, 'student_id' => $sid, 'acting_guardian_id' => $gid, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);
            try {
                // a savepoint so the violation rolls back only this insert, not the test's outer txn
                DB::transaction(fn () => DB::table('enrolments')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $this->programme->id, 'student_id' => $sid, 'acting_guardian_id' => $gid, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]));
                $this->fail('a second live enrolment should violate the (student_id, programme_id) unique index');
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $this->assertTrue(true);
            }
        });
    }

    // ── guardian eligibility is re-evaluated LIVE at commit ───────────────────

    public function test_a_student_who_gains_a_guardian_after_preview_enrols_on_commit(): void
    {
        $sid = $this->student('later@x.test', withGuardian: false); // no guardian at "preview"
        $batchId = $this->readyBatch([['Later', 'later@x.test', 'match_existing']]);

        // gains a guardian between preview and commit
        $this->sys(function () use ($sid) {
            $g = User::factory()->create(['role' => 'guardian']);
            DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $sid, 'guardian_id' => $g->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        });

        $res = $this->commit($batchId)->assertStatus(200);
        $this->assertSame(1, $res->json('counts.enrolled'), 'live re-eval picked up the new guardian');
    }

    public function test_a_student_who_loses_the_guardian_after_preview_does_not_enrol(): void
    {
        $sid = $this->student('gone@x.test', withGuardian: true); // guardian at "preview"
        $batchId = $this->readyBatch([['Gone', 'gone@x.test', 'match_existing']]);

        // guardian revoked between preview and commit
        $this->sys(fn () => DB::table('guardian_links')->where('student_id', $sid)->update(['status' => 'revoked']));

        $res = $this->commit($batchId)->assertStatus(200);
        $this->assertSame(0, $res->json('counts.enrolled'));
        $this->assertSame(1, $res->json('counts.not_enrolled'), 'stale disposition not trusted — no active guardian now');
    }

    // ── row_conservation teeth ────────────────────────────────────────────────

    public function test_row_conservation_reds_on_an_undispositioned_committed_row_then_greens(): void
    {
        $this->student('c@x.test', withGuardian: true);
        $batchId = $this->readyBatch([['C', 'c@x.test', 'match_existing']]);
        $this->commit($batchId)->assertStatus(200);
        $this->assertTrue($this->sys(fn () => (new BatchRowConservationAssertion)->check()->passed), 'green after a clean commit');

        // forge an undispositioned row under this committed batch → red
        $this->sys(function () use ($batchId) {
            DB::table('enrolment_batch_rows')->insert(['id' => (string) Str::uuid7(), 'batch_id' => $batchId, 'school_id' => $this->school->id, 'row_number' => 99, 'name' => 'X', 'email' => 'x@x.test', 'status' => 'validated', 'committed' => false, 'created_at' => now(), 'updated_at' => now()]);
            $this->assertFalse((new BatchRowConservationAssertion)->check()->passed, 'reds on an undispositioned committed row');
            // give it a terminal reasoned outcome → greens
            DB::table('enrolment_batch_rows')->where('batch_id', $batchId)->where('row_number', 99)->update(['status' => 'not_enrolled', 'reason' => 'awaiting guardian & consent']);
            $this->assertTrue((new BatchRowConservationAssertion)->check()->passed);
        });
    }
}
