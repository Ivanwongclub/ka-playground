<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\OrderService;
use App\Services\Money\PaymentLinkService;
use App\Services\Reconciliation\Assertions\PaymentLinkNoPiiAssertion;
use App\Services\Reconciliation\Assertions\PaymentLinkSingleReaderAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentLinkTest extends TestCase
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
        $this->student = User::factory()->create(['role' => 'student', 'name' => 'Wing Yan Chan']);
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
            'code' => 'LNK-'.Str::upper(Str::random(4)), 'name_en' => 'Link P', 'name_tc' => '課程', 'name_sc' => '课程',
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
        $this->app['auth']->forgetGuards();
        $machine = app(EnrolmentService::class);
        foreach (['in_pool', 'teamed', 'confirmed'] as $to) {
            $machine->transition($this->enrolmentId, $to, $this->ops, 'link test walk');
        }
        app(OrderService::class)->issueForEnrolment($this->enrolmentId, 'guardian', null, $this->ops);
    }

    private function mint(?User $guardian = null): array
    {
        $order = DB::table('orders')->where('enrolment_id', $this->enrolmentId)->first();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian ?? $this->guardian);
        $response = $this->postJson("/api/my/orders/{$order->id}/payment-link")->assertStatus(201)->json();
        $this->app['auth']->forgetGuards();
        $token = basename(parse_url($response['url'], PHP_URL_PATH));

        return [$response, $token];
    }

    public function test_mint_returns_plaintext_once_and_stores_hash_only(): void
    {
        [$response, $token] = $this->mint();
        $this->assertSame(64, strlen($token));
        $row = DB::table('payment_links')->first();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame('W.Y.C.', $row->student_initials, 'frozen at mint, never joined live');
        // no plaintext column exists at all
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS c FROM information_schema.columns WHERE table_name='payment_links' AND column_name='token'")->c);
    }

    public function test_anonymous_view_is_multi_view_and_initials_only(): void
    {
        [, $token] = $this->mint();
        for ($i = 0; $i < 2; $i++) { // ruling 2: forwardable = viewable repeatedly
            $payload = $this->getJson("/api/pay/{$token}")->assertOk()->json();
            $this->assertEqualsCanonicalizing(
                ['order_reference', 'amount_minor', 'currency', 'programme_name', 'student_initials'],
                array_keys($payload),
            );
            $this->assertSame('W.Y.C.', $payload['student_initials']);
            $this->assertSame(250000, $payload['amount_minor']);
            $this->assertStringNotContainsString('Wing', json_encode($payload));
        }
    }

    public function test_constant_shape_404_for_unknown_expired_paid_and_paying(): void
    {
        [, $token] = $this->mint();
        $bodies = [];
        $bodies['unknown'] = $this->getJson('/api/pay/'.Str::random(64))->assertStatus(404)->getContent();

        DB::table('payment_links')->update(['status' => 'paying']);
        $bodies['paying'] = $this->getJson("/api/pay/{$token}")->assertStatus(404)->getContent();
        DB::table('payment_links')->update(['status' => 'paid']);
        $bodies['paid'] = $this->getJson("/api/pay/{$token}")->assertStatus(404)->getContent();
        DB::table('payment_links')->update(['status' => 'active', 'expires_at' => now()->subMinute()]);
        $bodies['expired'] = $this->getJson("/api/pay/{$token}")->assertStatus(404)->getContent();

        $this->assertCount(1, array_unique($bodies), 'unknown/expired/paid/paying must be byte-identical');
        // confirm gets the same shape too
        $this->assertSame($bodies['unknown'], $this->postJson("/api/pay/{$token}/confirm")->assertStatus(404)->getContent());
    }

    public function test_confirm_pays_issues_receipt_and_kills_the_link(): void
    {
        [, $token] = $this->mint();
        $result = $this->postJson("/api/pay/{$token}/confirm")->assertOk()->json();
        $this->assertTrue($result['paid']);
        $this->assertSame(1, $result['receipt_number']);

        $order = DB::table('orders')->where('enrolment_id', $this->enrolmentId)->first();
        $this->assertSame('paid', $order->status);
        $payment = DB::table('payments')->first();
        $this->assertSame(['provider', 'mock', 'confirmed'], [$payment->origin, $payment->provider, $payment->status]);
        $this->assertTrue((bool) $payment->via_link);
        $this->assertNull($payment->confirmed_by ?? null, 'OD-47: provider payments self-confirm — no human confirmer');
        $this->assertSame('paid', DB::table('payment_links')->first()->status);
        // the link is dead: view AND re-confirm both 404
        $this->getJson("/api/pay/{$token}")->assertStatus(404);
        $this->postJson("/api/pay/{$token}/confirm")->assertStatus(404);
        foreach (['payment.confirmed', 'order.paid', 'payment_link.paid', 'receipt.issued'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action]);
        }
    }

    public function test_paid_link_race_exactly_one_payment(): void
    {
        [, $token] = $this->mint();
        $hash = hash('sha256', $token);

        // In-process: the CAS admits exactly one claimant
        $first = DB::update("UPDATE payment_links SET status='paying' WHERE token_hash = ? AND status = 'active' AND expires_at > now()", [$hash]);
        $second = DB::update("UPDATE payment_links SET status='paying' WHERE token_hash = ? AND status = 'active' AND expires_at > now()", [$hash]);
        $this->assertSame([1, 0], [$first, $second], 'compare-and-set: one winner');
        DB::table('payment_links')->where('token_hash', $hash)->update(['status' => 'active']);

        // CROSS-CONNECTION (the receipt-sequence proof, link-shaped): build a
        // COMMITTED minimal fixture two raw connections can contend on.
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '54329'), env('DB_DATABASE', 'kap_test'));
        $a = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $b = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach ([$a, $b] as $conn) {
            $conn->exec("SELECT set_config('app.context', 'system', false)");
            $conn->exec("SET lock_timeout = '2s'");
        }
        $mk = fn (string $sql) => $a->query($sql)->fetchColumn();
        $suffix = strtolower(Str::random(6));
        $sid = $mk("INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES ('Race Student', 'race-s-{$suffix}@example.test', 'x', 'student', now(), now()) RETURNING id");
        $gid = $mk("INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES ('Race Guardian', 'race-g-{$suffix}@example.test', 'x', 'guardian', now(), now()) RETURNING id");
        $pid = $mk("INSERT INTO programmes (code, name_en, name_tc, name_sc, jurisdiction, status, created_at, updated_at) VALUES ('RACE-{$suffix}', 'R', 'R', 'R', 'HK', 'draft', now(), now()) RETURNING id");
        $eid = $mk("INSERT INTO enrolments (id, programme_id, student_id, acting_guardian_id, status, created_at, updated_at) VALUES (gen_random_uuid(), {$pid}, {$sid}, {$gid}, 'confirmed', now(), now()) RETURNING id");
        $oid = $mk("INSERT INTO orders (id, enrolment_id, programme_id, student_id, payer_party, status, total_amount_minor, currency, created_at, updated_at) VALUES (gen_random_uuid(), '{$eid}', {$pid}, {$sid}, 'guardian', 'issued', 1000, 'HKD', now(), now()) RETURNING id");
        $raceHash = hash('sha256', 'race-token-'.$suffix);
        $lid = $mk("INSERT INTO payment_links (id, order_id, student_id, minted_by, token_hash, status, order_reference, amount_minor, currency, programme_name_en, programme_name_tc, programme_name_sc, student_initials, expires_at, created_at, updated_at) VALUES (gen_random_uuid(), '{$oid}', {$sid}, {$gid}, '{$raceHash}', 'active', 'RACEREF', 1000, 'HKD', 'R', 'R', 'R', 'R.S.', now() + interval '1 hour', now(), now()) RETURNING id");

        $claim = "UPDATE payment_links SET status='paying' WHERE token_hash = '{$raceHash}' AND status = 'active' AND expires_at > now()";
        $a->exec('BEGIN');
        $wonA = $a->exec($claim);
        // B arrives while A's claim is uncommitted: blocks on the row lock,
        // then sees status='paying' → matches ZERO rows. Exactly one winner.
        $b->exec("SET lock_timeout = '5s'");
        $b->exec('BEGIN');
        $start = microtime(true);
        $a2 = null;
        // commit A from a register_shutdown-free path: run commit after issuing B's update via a short async trick is
        // not possible single-threaded — so prove the two halves separately:
        // (1) B BLOCKS while A holds the lock:
        $b->exec("SET lock_timeout = '300ms'");
        $blocked = false;
        try {
            $b->exec($claim);
        } catch (\PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'lock timeout');
        }
        $b->exec('ROLLBACK');
        // (2) after A commits, B's identical claim matches ZERO rows:
        $a->exec('COMMIT');
        $b->exec('BEGIN');
        $wonB = $b->exec($claim);
        $b->exec('COMMIT');
        $this->assertSame(1, $wonA, 'A claims the link');
        $this->assertTrue($blocked, 'B blocks on the row lock while A is mid-claim (serialized, like the receipt sequence)');
        $this->assertSame(0, $wonB, 'after A commits, B finds no active link — exactly one payment can proceed');

        // cleanup the committed fixture (system context; leftover users/programme are inert)
        $a->exec("DELETE FROM payment_links WHERE id = '{$lid}'");
        $a->exec("DELETE FROM orders WHERE id = '{$oid}'");
        $a->exec("DELETE FROM enrolments WHERE id = '{$eid}'");
        $a->exec("DELETE FROM programmes WHERE id = {$pid}");
        try { $a->exec("DELETE FROM users WHERE id IN ({$sid}, {$gid})"); } catch (\PDOException) { /* users delete may be policy-blocked; rows are inert */ }
    }

    public function test_co_guardian_sees_state_but_cannot_obtain_a_token(): void
    {
        [, $token] = $this->mint(); // minted by guardian A
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->coGuardian);

        // existence + state: visible via their branch (ruling 6)
        $rows = $this->getJson('/api/my/payment-links')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('active', $rows[0]['status']);
        // but NOTHING readable/forwardable: no token, no hash, no url in the payload
        $this->assertEqualsCanonicalizing(
            ['id', 'order_id', 'minted_by', 'status', 'amount_minor', 'currency', 'expires_at', 'paid_at'],
            array_keys($rows[0]),
        );
        $this->assertStringNotContainsString($token, json_encode($rows[0]));
        $this->assertStringNotContainsString(hash('sha256', $token), json_encode($rows[0]));
        // re-sharing = re-minting from their OWN action (allowed — OD-67 any guardian pays)
        $order = DB::table('orders')->where('enrolment_id', $this->enrolmentId)->first();
        $this->postJson("/api/my/orders/{$order->id}/payment-link")->assertStatus(201);
        // an unrelated guardian can neither list nor mint
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->assertCount(0, $this->getJson('/api/my/payment-links')->json('data'));
        $this->postJson("/api/my/orders/{$order->id}/payment-link")->assertStatus(404);
    }

    public function test_school_settled_orders_cannot_mint_links(): void
    {
        $school = \App\Models\School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S']);
        DB::table('orders')->update(['payer_party' => 'school', 'payer_school_id' => $school->id]);
        $order = DB::table('orders')->first();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian);
        $this->postJson("/api/my/orders/{$order->id}/payment-link")->assertStatus(422);
    }

    public function test_assertion_teeth_no_pii_and_single_reader(): void
    {
        $this->mint();
        $this->assertTrue((new PaymentLinkNoPiiAssertion)->check()->passed);
        $this->assertTrue((new PaymentLinkSingleReaderAssertion)->check()->passed);

        // no_pii teeth: a full name smuggled into the frozen payload → red
        DB::table('payment_links')->update(['student_initials' => 'Wing Yan Chan']);
        $result = (new PaymentLinkNoPiiAssertion)->check();
        $this->assertFalse($result->passed);
        $this->assertStringContainsString('more than initials', $result->details);
        DB::table('payment_links')->update(['student_initials' => 'W.Y.C.']);

        // single_reader teeth (part b): a public-context policy on a money table → red
        DB::statement("SAVEPOINT teeth");
        DB::unprepared("CREATE POLICY teeth_bad ON payments FOR SELECT USING (current_setting('app.context', true) = 'public')");
        $result = (new PaymentLinkSingleReaderAssertion)->check();
        $this->assertFalse($result->passed);
        $this->assertStringContainsString('references the public context', $result->details);
        DB::statement("ROLLBACK TO SAVEPOINT teeth");
        $this->assertTrue((new PaymentLinkSingleReaderAssertion)->check()->passed);
    }

    public function test_initials_derivation_covers_cjk_names(): void
    {
        $this->assertSame('W.Y.C.', PaymentLinkService::initials('Wing Yan Chan'));
        // STEP 3b (Leo): CJK → GIVEN name, family name hidden — never surname-only
        $this->assertSame('詠恩', PaymentLinkService::initials('陳詠恩'));
        $this->assertSame('詠', PaymentLinkService::initials('陳詠'));
        $this->assertSame('陳', PaymentLinkService::initials('陳'));
    }

    public function test_cjk_link_payload_hides_the_family_name(): void
    {
        $this->student->update(['name' => '陳詠恩']);
        [, $token] = $this->mint();
        $payload = $this->getJson("/api/pay/{$token}")->assertOk()->json();
        $this->assertSame('詠恩', $payload['student_initials']);
        $this->assertStringNotContainsString('陳', json_encode($payload, JSON_UNESCAPED_UNICODE),
            'the searchable family name must never appear in the anonymous payload');
        $this->assertStringNotContainsString('陳', (string) DB::table('payment_links')->orderByDesc('created_at')->value('student_initials'));
    }
}
