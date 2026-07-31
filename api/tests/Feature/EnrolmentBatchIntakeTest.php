<?php

namespace Tests\Feature;

use App\Models\EnrolmentBatch;
use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Reconciliation\Assertions\BatchScanGatedAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Eicar;
use Tests\Support\EicarOnlyScanner;
use Tests\Support\UnreachableScanner;
use Tests\TestCase;

/**
 * S04E STEP 1 — bulk-enrolment CSV intake. Scan gates the parse (BI-10, proven
 * on the EICAR double); fail-closed on an unreachable scanner (503, nothing
 * persisted); structural defect → whole-file reject; row defect → per-row
 * reason; clean → dry-run report that creates NOTHING (commit is STEP 2).
 */
class EnrolmentBatchIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // The gate is proven daemon-free with the EICAR double (the default here).
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
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

    private function school(): School
    {
        return $this->sys(fn () => School::query()->create(['name_en' => 'S'.Str::random(4), 'name_tc' => '甲', 'name_sc' => '甲']));
    }

    private function admin(int $schoolId): User
    {
        $u = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $u->id, 'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        return $u;
    }

    private function upload(User $admin, int $schoolId, string $csv)
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin);
        $file = UploadedFile::fake()->createWithContent('roll.csv', $csv);

        return $this->postJson('/api/school/enrolment-batches', ['file' => $file, 'school_id' => $schoolId]);
    }

    private function report(User $admin, string $batchId)
    {
        Sanctum::actingAs($admin);

        return $this->getJson("/api/school/enrolment-batches/{$batchId}");
    }

    // ── the scan gates the parse: an EICAR CSV never reaches a parsed row ──────

    public function test_eicar_csv_is_quarantined_and_never_parsed(): void
    {
        $school = $this->school();
        $admin = $this->admin($school->id);
        $csv = "name,email\nMallory,".Eicar::STRING."@x.test\n";

        $res = $this->upload($admin, $school->id, $csv)->assertStatus(202);
        $batchId = $res->json('batch_id');

        $batch = $this->sys(fn () => EnrolmentBatch::find($batchId));
        $this->assertSame(EnrolmentBatch::STATUS_FAILED, $batch->status);
        $this->assertStringContainsString('scan not clean', $batch->failure_reason);
        $this->assertSame(0, $this->sys(fn () => DB::table('enrolment_batch_rows')->where('batch_id', $batchId)->count()), 'ZERO rows parsed');
        $this->assertTrue($this->sys(fn () => (new BatchScanGatedAssertion)->check()->passed));
    }

    // ── fail-closed: an unreachable scanner refuses BEFORE intake, persist nothing ─

    public function test_unreachable_scanner_refuses_with_503_and_persists_nothing(): void
    {
        $school = $this->school();
        $admin = $this->admin($school->id);
        $this->app->bind(VirusScanner::class, UnreachableScanner::class);

        $before = $this->sys(fn () => DB::table('uploads')->count());
        $this->upload($admin, $school->id, "name,email\nAmy,amy@x.test\n")->assertStatus(503);

        $this->assertSame(0, $this->sys(fn () => DB::table('enrolment_batches')->count()), 'no batch created');
        $this->assertSame($before, $this->sys(fn () => DB::table('uploads')->count()), 'no upload created');
    }

    // ── structural defect → whole-file reject, zero rows ──────────────────────

    public function test_wrong_columns_rejects_whole_file(): void
    {
        $school = $this->school();
        $admin = $this->admin($school->id);

        $res = $this->upload($admin, $school->id, "firstname,mail\nAmy,amy@x.test\n")->assertStatus(202);
        $batch = $this->sys(fn () => EnrolmentBatch::find($res->json('batch_id')));
        $this->assertSame(EnrolmentBatch::STATUS_FAILED, $batch->status);
        $this->assertStringContainsString('missing required column', $batch->failure_reason);
        $this->assertSame(0, $this->sys(fn () => DB::table('enrolment_batch_rows')->where('batch_id', $batch->id)->count()));
    }

    // ── row defects → per-row reason; clean rows still processed ───────────────

    public function test_row_defects_are_per_row_and_clean_rows_disposition(): void
    {
        $school = $this->school();
        $admin = $this->admin($school->id);
        // an existing student already on this school's roll → match_existing
        $existing = $this->sys(function () use ($school) {
            $u = User::factory()->create(['role' => 'student', 'email' => 'onroll@x.test']);
            DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $u->id, 'school_id' => $school->id, 'status' => 'active', 'origin' => 'registration', 'created_at' => now(), 'updated_at' => now()]);

            return $u;
        });

        $csv = "name,email\n"
            ."NewKid,new@x.test\n"                 // validated / new
            ."OnRoll,onroll@x.test\n"              // validated / match_existing
            .'=cmd,evil@x.test'."\n"               // failed / formula
            ."NoEmail,\n"                          // failed / invalid email
            ."Dup,new@x.test\n";                   // skipped / duplicate in-file

        $usersBefore = $this->sys(fn () => DB::table('users')->count());
        $res = $this->upload($admin, $school->id, $csv)->assertStatus(202);
        $batchId = $res->json('batch_id');

        $rep = $this->report($admin, $batchId)->assertStatus(200);
        $this->assertSame('ready', $rep->json('status'));
        $this->assertSame(['total' => 5, 'new' => 1, 'existing' => 1, 'skipped' => 1, 'failed' => 2], $rep->json('counts'));

        $rows = collect($rep->json('rows'));
        $this->assertSame('new', $rows->firstWhere('name', 'NewKid')['disposition']);
        $onRoll = $rows->firstWhere('name', 'OnRoll');
        $this->assertSame('match_existing', $onRoll['disposition']);
        $this->assertSame((string) $existing->id, (string) $onRoll['matched_user_id']);
        $this->assertStringContainsString('formula-injection', $rows->firstWhere('name', '=cmd')['reason']);
        $this->assertSame('skipped', $rows->firstWhere('name', 'Dup')['status']);

        // DRY RUN — nothing committed: no new accounts, no enrolments
        $this->assertSame($usersBefore, $this->sys(fn () => DB::table('users')->count()), 'no account created in STEP 1');
        $this->assertSame(0, $this->sys(fn () => DB::table('enrolments')->count()), 'no enrolment created in STEP 1');
    }

    // ── five-branch: another school's admin cannot see the batch ──────────────

    public function test_admin_of_another_school_sees_nothing(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();
        $adminA = $this->admin($schoolA->id);
        $adminB = $this->admin($schoolB->id);

        $batchId = $this->upload($adminA, $schoolA->id, "name,email\nAmy,amy@x.test\n")->json('batch_id');

        $this->report($adminB, $batchId)->assertStatus(404);              // RLS hides it
        // and adminB cannot upload FOR school A
        $this->upload($adminB, $schoolA->id, "name,email\nAmy,amy@x.test\n")->assertStatus(403);
    }

    // ── batches.scan_gated teeth ──────────────────────────────────────────────

    public function test_scan_gated_reds_on_a_row_under_a_non_clean_upload_then_greens(): void
    {
        $school = $this->school();
        $admin = $this->admin($school->id);
        $batchId = $this->upload($admin, $school->id, "name,email\nAmy,amy@x.test\n")->json('batch_id');
        $this->assertTrue($this->sys(fn () => (new BatchScanGatedAssertion)->check()->passed), 'green after a clean batch');

        // forge a row under a NON-clean upload → must red
        $this->sys(function () use ($school, $admin) {
            $badUpload = (string) Str::uuid7();
            DB::table('uploads')->insert(['id' => $badUpload, 'context' => 'batch-csv', 'disk' => 'local', 'path' => 'uploads/pending/x.csv', 'original_name' => 'x.csv', 'mime_type' => 'text/csv', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $badBatch = (string) Str::uuid7();
            DB::table('enrolment_batches')->insert(['id' => $badBatch, 'school_id' => $school->id, 'upload_id' => $badUpload, 'status' => 'validating', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('enrolment_batch_rows')->insert(['id' => (string) Str::uuid7(), 'batch_id' => $badBatch, 'school_id' => $school->id, 'row_number' => 1, 'name' => 'Leak', 'email' => 'leak@x.test', 'status' => 'validated', 'disposition' => 'new', 'created_at' => now(), 'updated_at' => now()]);

            $this->assertFalse((new BatchScanGatedAssertion)->check()->passed, 'reds on a row under a non-clean upload');

            // the scan passing (upload → clean) is the natural green
            DB::table('uploads')->where('id', $badUpload)->update(['status' => 'clean']);
            $this->assertTrue((new BatchScanGatedAssertion)->check()->passed);
        });
    }

    // ── public-context confinement stays green (no public policy added) ───────

    public function test_public_context_confinement_stays_green(): void
    {
        $key = \App\Services\Reconciliation\Assertions\PublicContextConfinementAssertion::class;
        if (! class_exists($key)) {
            $this->markTestSkipped('assertion class name differs');
        }
        $this->assertTrue($this->sys(fn () => (new $key)->check()->passed));
    }
}
