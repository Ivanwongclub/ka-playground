<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Reconciliation\Assertions\PublicContextConfinementAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S04C STEP 1 — SECURITY CORE (VERIFY pass 1). The anonymous write is the
 * platform's most exposed surface; these prove it is boxed in by construction:
 * structural confinement (one policy, write-only, status-pinned, forced), no
 * privilege escalation through the insert, no read (no oracle), and one constant
 * response shape across every enumeration case.
 */
class PublicRegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // reset rate-limiter counters between tests (array store bleeds otherwise)
    }

    private function pub(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setPublic();
        try {
            return $fn();
        } finally {
            $s->reset();
        }
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

    /** A nonce that looks like a human filled the form (issued 5s ago). */
    private function humanNonce(): string
    {
        return Crypt::encryptString((string) (now()->timestamp - 5));
    }

    /** @return array<string, mixed> a minimally-valid guardian registration body */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'guardian',
            'applicant_name' => 'A Parent',
            'applicant_email' => 'new-'.Str::random(6).'@example.com',
            'preferred_language' => 'en',
            'form_nonce' => $this->humanNonce(),
        ], $overrides);
    }

    // ── (a)–(d) structural confinement ────────────────────────────────────────

    public function test_confinement_assertion_passes_green(): void
    {
        $r = (new PublicContextConfinementAssertion)->check();
        $this->assertTrue($r->passed, $r->details);
        $this->assertStringContainsString('one public policy platform-wide', $r->details);
    }

    public function test_confinement_reds_when_a_second_table_admits_public(): void
    {
        // TEETH: plant a policy admitting the public context to a DIFFERENT table.
        DB::unprepared("CREATE POLICY tmp_public_leak ON guardian_links FOR INSERT WITH CHECK (current_setting('app.context', true) = 'public')");
        try {
            $red = (new PublicContextConfinementAssertion)->check();
            $this->assertFalse($red->passed, 'a second table admitting public must red');
            $this->assertStringContainsString('admitted by 2 policies', $red->details);
        } finally {
            DB::unprepared('DROP POLICY IF EXISTS tmp_public_leak ON guardian_links');
        }
        // green again once the leak is removed
        $this->assertTrue((new PublicContextConfinementAssertion)->check()->passed);
    }

    public function test_public_context_can_insert_a_submitted_request(): void
    {
        $id = (string) Str::uuid7();
        $this->pub(fn () => DB::table('registration_requests')->insert([
            'id' => $id, 'kind' => 'guardian', 'applicant_name' => 'P', 'applicant_email' => 'p@example.com',
            'preferred_language' => 'en', 'routing' => 'academy', 'status' => 'submitted',
            'reference' => Str::random(10), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertSame(1, $this->sys(fn () => DB::table('registration_requests')->where('id', $id)->count()));
    }

    public function test_public_context_cannot_insert_a_privileged_row(): void
    {
        // WITH CHECK pins status='submitted' and null reviewer — an approved row is refused at the DB.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->pub(fn () => DB::table('registration_requests')->insert([
            'id' => (string) Str::uuid7(), 'kind' => 'guardian', 'applicant_name' => 'P', 'applicant_email' => 'p@example.com',
            'preferred_language' => 'en', 'routing' => 'academy', 'status' => 'approved', // ← escalation attempt
            'reference' => Str::random(10), 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_public_context_reads_nothing_it_wrote(): void
    {
        $id = (string) Str::uuid7();
        $this->pub(fn () => DB::table('registration_requests')->insert([
            'id' => $id, 'kind' => 'guardian', 'applicant_name' => 'P', 'applicant_email' => 'p@example.com',
            'preferred_language' => 'en', 'routing' => 'academy', 'status' => 'submitted',
            'reference' => Str::random(10), 'created_at' => now(), 'updated_at' => now(),
        ]));
        // it wrote the row (system confirms) but the public context can read NOTHING back — no oracle
        $this->assertSame(1, $this->sys(fn () => DB::table('registration_requests')->count()));
        $this->assertSame(0, $this->pub(fn () => DB::table('registration_requests')->count()));
        // and public sees zero of any other scoped table
        $this->assertSame(0, $this->pub(fn () => DB::table('guardian_links')->count()));
    }

    // ── constant shape across ALL FOUR enumeration cases ───────────────────────

    public function test_constant_shape_202_across_all_four_enumeration_cases(): void
    {
        $existingRegistrant = User::factory()->create(['email' => 'known-registrant@example.com']);
        $existingCounterpart = User::factory()->create(['email' => 'known-counterpart@example.com', 'role' => 'student']);

        $cases = [
            'registrant-new' => $this->body(),
            'registrant-exists' => $this->body(['applicant_email' => $existingRegistrant->email]),
            'counterpart-new' => $this->body(['counterpart_email' => 'unknown-counterpart-'.Str::random(5).'@example.com']),
            'counterpart-exists' => $this->body(['counterpart_email' => $existingCounterpart->email]),
        ];

        $shapes = [];
        foreach ($cases as $label => $payload) {
            $resp = $this->postJson('/api/register', $payload);
            $resp->assertStatus(202);
            $keys = array_keys($resp->json());
            sort($keys);
            $shapes[$label] = ['status' => $resp->status(), 'keys' => $keys, 'body_status' => $resp->json('status')];
        }

        // every case collapses to ONE observed shape — the counterpart-email is the
        // subtle oracle and it must NOT leak that the address is a known account
        $distinct = array_unique(array_map(fn ($s) => json_encode($s), $shapes));
        $this->assertCount(1, $distinct, 'enumeration cases differ in shape: '.json_encode($shapes));
        $this->assertSame(['reference', 'status'], $shapes['registrant-new']['keys']);
        $this->assertSame('received', $shapes['registrant-new']['body_status']);
    }

    // ── bot confinement: silent drop, still constant shape ─────────────────────

    public function test_honeypot_fill_silently_drops_but_returns_the_same_shape(): void
    {
        $resp = $this->postJson('/api/register', $this->body(['website' => 'http://spam.example']));
        $resp->assertStatus(202)->assertJsonStructure(['status', 'reference']);
        $this->assertSame(0, $this->sys(fn () => DB::table('registration_requests')->count()));
    }

    public function test_too_fast_submission_silently_drops(): void
    {
        // a fresh nonce (0s elapsed) is below the human fill-time floor → dropped
        $resp = $this->postJson('/api/register', $this->body(['form_nonce' => Crypt::encryptString((string) now()->timestamp)]));
        $resp->assertStatus(202)->assertJsonStructure(['status', 'reference']);
        $this->assertSame(0, $this->sys(fn () => DB::table('registration_requests')->count()));
    }

    public function test_a_genuine_submission_is_recorded_and_attributed_public(): void
    {
        $this->postJson('/api/register', $this->body(['applicant_name' => 'Real Parent']))->assertStatus(202);
        [$rows, $audit] = $this->sys(fn () => [
            DB::table('registration_requests')->count(),
            DB::table('audit_events')->where('action', 'registration.submitted')->first(),
        ]);
        $this->assertSame(1, $rows);
        $this->assertNotNull($audit);
        $this->assertSame('public', $audit->actor_role); // attribution never a hole (OD-64 extended)
        $this->assertNull($audit->actor_id);
    }

    // ── throttle ───────────────────────────────────────────────────────────────

    public function test_registration_is_throttled_429(): void
    {
        $last = null;
        for ($i = 0; $i < 12; $i++) {
            $last = $this->postJson('/api/register', $this->body());
        }
        $last->assertStatus(429); // 10/min/IP limiter
    }
}
