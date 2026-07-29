<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S04C STEP 2 — approval creates the account (born UNVERIFIED), activation is a
 * single verify-and-set-password act, decline needs a reason, and every decision
 * is audited to the reviewer. Authorisation is RLS visibility: a reviewer decides
 * only the requests they can see.
 */
class RegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush(); // reset throttle:auth counters (activate/login share it)
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

    private function ops(): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        $this->sys(fn () => DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => 'operations',
            'granted_by' => $u->id, 'granted_at' => now(),
        ]));

        return $u;
    }

    private function school(): int
    {
        return (int) $this->sys(fn () => DB::table('schools')->insertGetId([
            'name_en' => 'S'.Str::random(4), 'name_tc' => '校', 'name_sc' => '校', 'public_listing' => true,
        ]));
    }

    private function schoolAdmin(int $schoolId): User
    {
        $u = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert([
            'id' => (string) Str::uuid7(), 'school_admin_id' => $u->id, 'school_id' => $schoolId,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $u;
    }

    private function submittedRequest(string $kind = 'guardian', ?int $schoolId = null, array $extra = []): string
    {
        $id = (string) Str::uuid7();
        $this->sys(fn () => DB::table('registration_requests')->insert(array_merge([
            'id' => $id, 'kind' => $kind, 'applicant_name' => 'Applicant '.Str::random(4),
            'applicant_email' => 'appl-'.Str::random(6).'@example.com', 'preferred_language' => 'en',
            'routing' => $schoolId ? 'school' : 'academy', 'school_id' => $schoolId,
            'status' => 'submitted', 'reference' => Str::upper(Str::random(10)),
            'created_at' => now(), 'updated_at' => now(),
        ], $extra)));

        return $id;
    }

    private function sanctumAs(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    // ── approval → unverified account + activation + audits ────────────────────

    public function test_ops_approves_a_direct_registration_creating_an_unverified_account(): void
    {
        Notification::fake();
        $ops = $this->ops();
        $reqId = $this->submittedRequest('guardian'); // direct (academy)

        $this->sanctumAs($ops);
        $resp = $this->postJson("/api/admin/registration-requests/{$reqId}/approve")->assertStatus(201);
        $accountId = $resp->json('account_id');
        $this->assertFalse($resp->json('verified'));

        $user = User::find($accountId);
        $this->assertNotNull($user);
        $this->assertFalse($user->hasVerifiedEmail(), 'account is born UNVERIFIED (OD-29)');
        $this->assertSame('guardian', $user->role);

        // request marked approved + linked to the account, audited to the reviewer
        [$req, $approved, $created] = $this->sys(fn () => [
            DB::table('registration_requests')->where('id', $reqId)->first(),
            DB::table('audit_events')->where('action', 'registration.approved')->where('entity_id', $reqId)->first(),
            DB::table('audit_events')->where('action', 'user.created')->where('entity_id', (string) $accountId)->first(),
        ]);
        $this->assertSame('approved', $req->status);
        $this->assertSame($accountId, $req->created_account_id);
        $this->assertSame((string) $ops->id, (string) $approved->actor_id);
        $this->assertSame((string) $ops->id, (string) $created->actor_id);

        Notification::assertSentTo($user, AccountActivationNotification::class);
    }

    public function test_activation_sets_password_and_verifies_then_login_works(): void
    {
        Notification::fake();
        $ops = $this->ops();
        $reqId = $this->submittedRequest('guardian');
        $this->sanctumAs($ops);
        $accountId = $this->postJson("/api/admin/registration-requests/{$reqId}/approve")->json('account_id');
        $user = User::find($accountId);

        // pre-activation: login is refused — the account has no usable credential yet
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'guess-whatever'])
            ->assertStatus(422);

        // capture the single-use activation token from the notification
        $token = null;
        Notification::assertSentTo($user, AccountActivationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->assertNotNull($token);

        // activate: one act sets password AND verifies
        $this->postJson('/api/register/activate', [
            'token' => $token, 'password' => 'ChosenPass1!', 'password_confirmation' => 'ChosenPass1!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($this->sys(fn () => DB::table('users')->where('id', $accountId)->value('activation_token_hash')), 'token burned');

        // post-activation: login succeeds with the chosen password
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'ChosenPass1!'])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_activation_refused_for_invalid_or_burned_token(): void
    {
        Notification::fake();
        $ops = $this->ops();
        $reqId = $this->submittedRequest('student');
        $this->sanctumAs($ops);
        $accountId = $this->postJson("/api/admin/registration-requests/{$reqId}/approve")->json('account_id');
        $token = null;
        Notification::assertSentTo(User::find($accountId), AccountActivationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        // wrong token → refused
        $this->postJson('/api/register/activate', ['token' => 'not-a-real-token', 'password' => 'ChosenPass1!', 'password_confirmation' => 'ChosenPass1!'])
            ->assertStatus(422);
        // real token once → ok
        $this->postJson('/api/register/activate', ['token' => $token, 'password' => 'ChosenPass1!', 'password_confirmation' => 'ChosenPass1!'])->assertOk();
        // real token again (burned) → refused
        $this->postJson('/api/register/activate', ['token' => $token, 'password' => 'Another1!', 'password_confirmation' => 'Another1!'])
            ->assertStatus(422);
    }

    // ── decline ────────────────────────────────────────────────────────────────

    public function test_decline_requires_a_reason(): void
    {
        $ops = $this->ops();
        $reqId = $this->submittedRequest('guardian');
        $this->sanctumAs($ops);
        $this->postJson("/api/admin/registration-requests/{$reqId}/decline", ['reason' => ''])->assertStatus(422);
        $this->postJson("/api/admin/registration-requests/{$reqId}/decline", [])->assertStatus(422);

        // with a reason → declined + audited
        $this->postJson("/api/admin/registration-requests/{$reqId}/decline", ['reason' => 'Duplicate of an existing family'])->assertOk();
        [$req, $audit] = $this->sys(fn () => [
            DB::table('registration_requests')->where('id', $reqId)->first(),
            DB::table('audit_events')->where('action', 'registration.declined')->where('entity_id', $reqId)->first(),
        ]);
        $this->assertSame('declined', $req->status);
        $this->assertSame('Duplicate of an existing family', $req->decline_reason);
        $this->assertSame((string) $ops->id, (string) $audit->actor_id);
    }

    // ── authorisation is RLS visibility ────────────────────────────────────────

    public function test_school_admin_approves_own_routed_request_but_not_another_schools(): void
    {
        Notification::fake();
        $schoolA = $this->school();
        $schoolB = $this->school();
        $adminA = $this->schoolAdmin($schoolA);
        $ownReq = $this->submittedRequest('student', $schoolA);
        $otherReq = $this->submittedRequest('student', $schoolB);

        $this->sanctumAs($adminA);
        // own routed request → approves
        $this->postJson("/api/admin/registration-requests/{$ownReq}/approve")->assertStatus(201);
        // another school's request is invisible (RLS) → 404, never decided
        $this->postJson("/api/admin/registration-requests/{$otherReq}/approve")->assertStatus(404);
        $this->assertSame('submitted', $this->sys(fn () => DB::table('registration_requests')->where('id', $otherReq)->value('status')));
    }

    public function test_a_family_role_cannot_reach_the_review_surface(): void
    {
        $guardian = User::factory()->create(['role' => 'guardian']);
        $reqId = $this->submittedRequest('student');
        $this->sanctumAs($guardian);
        $this->postJson("/api/admin/registration-requests/{$reqId}/approve")->assertStatus(403);
    }

    // ── activation is a CAS: exactly one activation wins ───────────────────────

    public function test_two_activations_on_one_token_one_wins_one_refuses_password_set_once(): void
    {
        // an approved-but-unverified account carrying a known activation token
        $token = Str::random(64);
        $email = 'race-'.Str::random(6).'@example.test';
        $this->sys(fn () => DB::table('users')->insert([
            'name' => 'Race', 'email' => $email, 'password' => 'x', 'role' => 'guardian',
            'activation_token_hash' => hash('sha256', $token), 'activation_expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $svc = app(\App\Services\Identity\AccountActivationService::class);

        // first activation wins; second (same token, now burned) is refused
        $winner = $svc->activate($token, 'WinningPass1!');
        $this->assertTrue($winner->hasVerifiedEmail());

        try {
            $svc->activate($token, 'LoserPass2!');
            $this->fail('the second activation on a burned token must be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('invalid or has expired', $e->getMessage());
        }

        // exactly one password was set — the winner's; the loser never overwrote it
        $hash = $this->sys(fn () => DB::table('users')->where('email', $email)->value('password'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('WinningPass1!', $hash));
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('LoserPass2!', $hash), 'the losing activation must not have overwritten the password');
    }

    public function test_activation_burn_is_a_cas_across_connections(): void
    {
        // In-process: the conditional burn admits exactly one claimant.
        $token = Str::random(64);
        $hash = hash('sha256', $token);
        $this->sys(fn () => DB::table('users')->insert([
            'name' => 'CAS', 'email' => 'cas-'.Str::random(6).'@example.test', 'password' => 'x', 'role' => 'guardian',
            'activation_token_hash' => $hash, 'activation_expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $burn = "UPDATE users SET password='y', email_verified_at=now(), activation_token_hash=NULL, activation_expires_at=NULL, updated_at=now() WHERE activation_token_hash = ? AND activation_expires_at > now()";
        $this->assertSame([1, 0], [
            $this->sys(fn () => DB::update($burn, [$hash])),
            $this->sys(fn () => DB::update($burn, [$hash])),
        ], 'conditional burn: exactly one activation wins, the replay matches zero rows');

        // CROSS-CONNECTION (the receipt-sequence proof, activation-shaped): a
        // COMMITTED fixture two raw connections contend on. A holds the row lock
        // mid-burn; B blocks, then after A commits finds ZERO rows.
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '54329'), env('DB_DATABASE', 'kap_test'));
        $a = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $b = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach ([$a, $b] as $conn) {
            $conn->exec("SELECT set_config('app.context', 'system', false)");
            $conn->exec("SET lock_timeout = '2s'");
        }
        $suffix = strtolower(Str::random(6));
        $raceHash = hash('sha256', 'race-token-'.$suffix);
        $uid = $a->query("INSERT INTO users (name, email, password, role, activation_token_hash, activation_expires_at, created_at, updated_at) VALUES ('Race', 'race-cc-{$suffix}@example.test', 'x', 'guardian', '{$raceHash}', now() + interval '1 day', now(), now()) RETURNING id")->fetchColumn();

        $burnCc = "UPDATE users SET password='z', email_verified_at=now(), activation_token_hash=NULL, activation_expires_at=NULL, updated_at=now() WHERE activation_token_hash = '{$raceHash}' AND activation_expires_at > now()";
        $a->exec('BEGIN');
        $wonA = $a->exec($burnCc);
        // (1) B blocks on the row lock while A holds it uncommitted
        $b->exec("SET lock_timeout = '300ms'");
        $b->exec('BEGIN');
        $blocked = false;
        try {
            $b->exec($burnCc);
        } catch (\PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'lock timeout');
        }
        $b->exec('ROLLBACK');
        // (2) after A commits, B's identical burn matches ZERO rows
        $a->exec('COMMIT');
        $b->exec('BEGIN');
        $wonB = $b->exec($burnCc);
        $b->exec('COMMIT');

        $this->assertSame(1, $wonA, 'A wins the activation');
        $this->assertTrue($blocked, 'B blocks on the row lock while A is mid-activation');
        $this->assertSame(0, $wonB, 'after A commits, B finds no pending token — exactly one activation wins');

        // cleanup the committed fixture
        $a->exec("DELETE FROM users WHERE id = {$uid}");
        $a = null;
        $b = null;
    }

    public function test_a_second_approve_never_creates_a_second_account(): void
    {
        // The invariant (BI-4): however approve is retried, exactly one account results.
        // Sequential retry → the early "already decided" guard (422); a CONCURRENT retry
        // that slips past the guard is caught by the FOR UPDATE recheck (returns the
        // original). Either way: never a second account.
        Notification::fake();
        $ops = $this->ops();
        $reqId = $this->submittedRequest('guardian');
        $this->sanctumAs($ops);
        $first = $this->postJson("/api/admin/registration-requests/{$reqId}/approve")->assertStatus(201)->json('account_id');
        $email = User::find($first)->email;

        // sequential re-approve → refused, already decided
        $this->postJson("/api/admin/registration-requests/{$reqId}/approve")->assertStatus(422);

        // the real invariant: exactly ONE account for this applicant, request still points at it
        $this->assertSame(1, $this->sys(fn () => DB::table('users')->where('email', $email)->count()));
        $this->assertSame($first, $this->sys(fn () => DB::table('registration_requests')->where('id', $reqId)->value('created_account_id')));
    }
}
