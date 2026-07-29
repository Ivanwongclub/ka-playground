<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\OrderService;
use App\Services\Money\ReceiptService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class OrderReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $guardian;

    private User $coGuardian;

    private User $student;

    private Programme $programme;

    private string $enrolmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations', 'finance'] as $cap) {
            DB::table('admin_capabilities')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $this->ops->id,
                'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now(),
            ]);
        }
        $this->guardian = User::factory()->create(['role' => 'guardian']);
        $this->coGuardian = User::factory()->create(['role' => 'guardian']);
        $this->student = User::factory()->create(['role' => 'student']);
        foreach ([$this->guardian, $this->coGuardian] as $g) {
            DB::table('guardian_links')->insert([
                'id' => (string) Str::uuid7(), 'student_id' => $this->student->id,
                'guardian_id' => $g->id, 'status' => 'active', 'origin' => 'onboarding',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $lang) {
            $vid = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", [
                'language' => $lang, 'body_html' => '<p>x {{signature}}</p>',
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$vid}/publish")->assertOk();
        }
        $this->programme = Programme::query()->create([
            'code' => 'ORD-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        // the PROGRAMME-LEVEL fee — the same for every student (OD-67)
        $this->postJson("/api/admin/programmes/{$this->programme->id}/fee-items", [
            'name_en' => 'Programme fee', 'name_tc' => '課程費用', 'name_sc' => '课程费用',
            'amount_minor' => 250000, 'currency' => 'HKD',
        ])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->enrolmentId = $this->postJson('/api/my/enrolments', [
            'programme_id' => $this->programme->id, 'student_id' => $this->student->id,
        ])->json('id');
        // walk to Confirmed via the system state machine (成團 itself is S05)
        $this->app['auth']->forgetGuards();
        $machine = app(EnrolmentService::class);
        foreach (['in_pool', 'teamed', 'confirmed'] as $to) {
            $machine->transition($this->enrolmentId, $to, $this->ops, 'test fixture walk');
        }
    }

    private function issueOrder(): object
    {
        return app(OrderService::class)->issueForEnrolment($this->enrolmentId, 'guardian', null, $this->ops);
    }

    public function test_order_snapshots_the_uniform_programme_fee_in_minor_units(): void
    {
        $order = $this->issueOrder();

        $this->assertSame(250000, (int) $order->total_amount_minor);
        $this->assertSame('HKD', $order->currency);
        $this->assertSame('guardian', $order->payer_party);
        $this->assertNotNull($order->payment_due_at, 'OD-43: family-paid orders carry the deadline clock');
        $lines = DB::table('order_lines')->where('order_id', $order->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame(250000, (int) $lines->first()->amount_minor);
        $this->assertSame('課程費用', $lines->first()->name_tc);
        // duplicate issuance returns the ORIGINAL (idempotent for the outbox consumer)
        $this->assertSame($order->id, $this->issueOrder()->id);
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_order_requires_a_confirmed_enrolment(): void
    {
        DB::table('orders')->delete();
        app(EnrolmentService::class)->transition($this->enrolmentId, 'active', $this->ops, 'walk');
        // active is fine (late issuance), but a POOL enrolment is refused
        $other = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $other->id,
            'guardian_id' => $this->guardian->id, 'status' => 'active', 'origin' => 'onboarding',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $poolEnrolment = $this->postJson('/api/my/enrolments', [
            'programme_id' => $this->programme->id, 'student_id' => $other->id,
        ])->json('id');
        $this->app['auth']->forgetGuards();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('confirmed enrolments only');
        app(OrderService::class)->issueForEnrolment($poolEnrolment, 'guardian', null, $this->ops);
    }

    public function test_order_lines_are_insert_only_at_the_database(): void
    {
        $order = $this->issueOrder();
        $line = DB::table('order_lines')->where('order_id', $order->id)->first();
        try {
            DB::table('order_lines')->where('id', $line->id)->update(['amount_minor' => 1]);
            $this->fail('order_lines UPDATE must be impossible (BI-5)');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'permission denied') || str_contains($e->getMessage(), 'INSERT-only'),
                $e->getMessage(),
            );
        }
    }

    public function test_receipts_are_gapless_and_serialized_across_connections(): void
    {
        $order = $this->issueOrder();
        $receipts = app(ReceiptService::class);
        for ($i = 0; $i < 50; $i++) {
            $receipts->issue($order->id, $this->ops);
        }
        $numbers = DB::table('receipts')->orderBy('receipt_number')->pluck('receipt_number');
        $this->assertCount(50, $numbers);
        $this->assertSame(range(1, 50), $numbers->map(fn ($n) => (int) $n)->all(), 'gapless 1..50 (BI-2)');
        $this->assertSame(51, (int) DB::table('receipt_sequences')->where('key', 'KAP')->value('next_number'));

        // CROSS-CONNECTION serialization: a second raw connection must BLOCK on
        // the sequence row while the first holds FOR UPDATE (BI-3). Uses its
        // OWN committed row — the test's wrapping transaction holds the 'KAP'
        // row's lock, which is exactly why we can't demo on it.
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '54329'), env('DB_DATABASE', 'kap_test'));
        $a = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'));
        $b = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'));
        foreach ([$a, $b] as $conn) {
            $conn->exec("SELECT set_config('app.context', 'system', false)");
            $conn->exec("SET lock_timeout = '2s'");
            $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        $a->exec("INSERT INTO receipt_sequences (key, next_number) VALUES ('LOCKTEST', 1) ON CONFLICT (key) DO NOTHING"); // autocommit, visible to both
        $a->exec('BEGIN');
        $a->query("SELECT next_number FROM receipt_sequences WHERE key = 'LOCKTEST' FOR UPDATE");
        $b->exec("SET lock_timeout = '300ms'");
        $b->exec('BEGIN');
        $blocked = false;
        try {
            $b->query("SELECT next_number FROM receipt_sequences WHERE key = 'LOCKTEST' FOR UPDATE");
        } catch (\PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'lock timeout');
        }
        $b->exec('ROLLBACK');
        $a->exec('ROLLBACK');
        $this->assertTrue($blocked, 'the sequence row must serialize issuers across connections (BI-2/BI-3)');
    }

    public function test_receipts_are_immutable_at_the_database(): void
    {
        $order = $this->issueOrder();
        $receipt = app(ReceiptService::class)->issue($order->id, $this->ops);
        try {
            DB::table('receipts')->where('id', $receipt->id)->update(['amount_minor' => 1]);
            $this->fail('receipts UPDATE must be impossible');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'permission denied') || str_contains($e->getMessage(), 'immutable'),
                $e->getMessage(),
            );
        }
    }

    public function test_five_branch_isolation_per_od67(): void
    {
        $school = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $order = $this->issueOrder();
        app(ReceiptService::class)->issue($order->id, $this->ops);

        // [1] acting guardian and [2] CO-GUARDIAN both read (OD-67: any active guardian reads and may pay)
        foreach ([$this->guardian, $this->coGuardian] as $g) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($g);
            $this->assertCount(1, $this->getJson('/api/orders')->json('data'), "guardian {$g->id}");
            $this->assertCount(1, $this->getJson("/api/orders/{$order->id}/lines")->json('data'));
            $this->assertCount(1, $this->getJson('/api/receipts')->json('data'));
        }
        // [3] the student reads (read-only, Q1)
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->student);
        $this->assertCount(1, $this->getJson('/api/orders')->json('data'));
        // [4] SCHOOL ADMIN OF THE STUDENT'S SCHOOL: ZERO family orders (OD-67 ruling 3)
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($schoolAdmin);
        $this->assertCount(0, $this->getJson('/api/orders')->json('data'), 'OD-67: school admins never see family orders');
        $this->assertCount(0, $this->getJson('/api/receipts')->json('data'));
        // [5] unrelated guardian zero · finance sees all · Member zero
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->assertCount(0, $this->getJson('/api/orders')->json('data'));
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->assertCount(1, $this->getJson('/api/orders')->json('data'));
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->assertCount(0, $this->getJson('/api/orders')->json('data'));
    }

    public function test_no_user_reachable_path_issues_orders_or_receipts(): void
    {
        // structural: the routes file exposes READ endpoints only for money
        $routes = file_get_contents(base_path('routes/api.php'));
        $this->assertStringNotContainsString("Route::post('/orders", $routes);
        $this->assertStringNotContainsString("Route::post('/receipts", $routes);
        // and the DB refuses a request-context INSERT outright
        $scope = app(\App\Services\Authz\ScopeContext::class);
        $scope->set($this->ops);
        try {
            DB::transaction(fn () => DB::table('orders')->insert([
                'id' => (string) Str::uuid7(), 'enrolment_id' => $this->enrolmentId,
                'programme_id' => $this->programme->id, 'student_id' => $this->student->id,
                'payer_party' => 'guardian', 'status' => 'issued',
                'total_amount_minor' => 1, 'currency' => 'HKD',
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('request-context INSERT into orders must be refused');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('row-level security', $e->getMessage());
        } finally {
            $scope->setSystem();
        }
    }
}
