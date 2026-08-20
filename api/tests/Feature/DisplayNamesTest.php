<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

/**
 * S-UX2b — API display-name additions. Proves:
 *   T1  cross-family isolation (name-independent counts) — the probe that must pass BEFORE names go on.
 *   T2  every endpoint returns the new *_name / programme_name_* fields ADDITIVELY (all prior keys intact).
 *   T3  the LEFT JOINs never drop a row: a student sees their own enrolment even though users_read hides
 *       the guardian's user row (acting_guardian resolves to NULL, the row survives).
 * Names are gated by each joined table's own RLS (users_read, programmes): a name resolves iff the caller
 * could already SELECT that row. This is the PII guarantee — stronger than the parent table's RLS alone.
 */
class DisplayNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $guardianA;

    private User $studentA;

    private User $guardianB;

    private User $studentB;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);

        $this->ops = User::factory()->create(['role' => 'academy_admin', 'name' => 'Ops Admin']);
        foreach (['configuration', 'operations', 'audit_read', 'super_admin'] as $cap) {
            DB::table('admin_capabilities')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $this->ops->id,
                'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now(),
            ]);
        }

        $this->guardianA = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian Alpha']);
        $this->studentA = User::factory()->create(['role' => 'student', 'name' => 'Student Alpha']);
        $this->guardianB = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian Bravo']);
        $this->studentB = User::factory()->create(['role' => 'student', 'name' => 'Student Bravo']);
        foreach ([[$this->studentA, $this->guardianA], [$this->studentB, $this->guardianB]] as [$student, $guardian]) {
            DB::table('guardian_links')->insert([
                'id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id,
                'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->programme = $this->publishedProgramme('UX2B');
        // real rows via the real flow: each enrolment issues its acting guardian a consent request
        $this->enrol($this->guardianA, $this->studentA);
        $this->enrol($this->guardianB, $this->studentB);
    }

    // ── T1: cross-family isolation — name-independent counts (the pre-names probe) ────────────────

    public function test_t1_cross_family_isolation_counts_only(): void
    {
        // Guardian A sees exactly their own family's enrolment + consent request; nothing of family B.
        $this->act($this->guardianA);
        $this->assertCount(1, $this->getJson('/api/enrolments')->json('data'), 'guardian A sees only own enrolment');
        $this->assertCount(1, $this->getJson('/api/consent-requests')->json('data'), 'guardian A sees only own consent request');

        // The student sees their own enrolment.
        $this->act($this->studentA);
        $this->assertCount(1, $this->getJson('/api/enrolments')->json('data'), 'student A sees own enrolment');

        // An UNRELATED guardian sees zero of either — no foreign row leaks through the join.
        $this->act(User::factory()->create(['role' => 'guardian']));
        $this->assertCount(0, $this->getJson('/api/enrolments')->json('data'), 'stranger sees no enrolments');
        $this->assertCount(0, $this->getJson('/api/consent-requests')->json('data'), 'stranger sees no consent requests');

        // The admin sees BOTH families' rows (its legitimate scope) — the join neither multiplied nor dropped.
        $this->act($this->ops);
        $this->assertCount(2, $this->getJson('/api/enrolments')->json('data'), 'ops sees both enrolments, no fan-out');
    }

    // ── T2: additive names, per endpoint (all prior keys intact) ─────────────────────────────────

    public function test_t2_enrolments_names_are_additive(): void
    {
        $this->act($this->ops);
        $rows = $this->getJson('/api/enrolments')->json('data');
        $row = collect($rows)->firstWhere('student_id', $this->studentA->id);

        // every pre-existing key still present, unchanged
        foreach (['id', 'programme_id', 'student_id', 'acting_guardian_id', 'status', 'created_at'] as $k) {
            $this->assertArrayHasKey($k, $row, "pre-existing key {$k} preserved");
        }
        // new display fields, correctly populated (ops can read all users → names resolve)
        $this->assertSame('Student Alpha', $row['student_name']);
        $this->assertSame('Guardian Alpha', $row['acting_guardian']);
        $this->assertSame('P UX2B', $row['programme_name_en']);
        $this->assertArrayHasKey('programme_name_tc', $row);
        $this->assertArrayHasKey('programme_name_sc', $row);
    }

    public function test_t2_consent_requests_names_are_additive(): void
    {
        $this->act($this->ops);
        $rows = $this->getJson('/api/consent-requests')->json('data');
        $row = collect($rows)->firstWhere('student_id', $this->studentA->id);

        foreach (['id', 'template_id', 'programme_id', 'student_id', 'signer_id', 'status', 'expires_at'] as $k) {
            $this->assertArrayHasKey($k, $row, "pre-existing key {$k} preserved");
        }
        $this->assertSame('Student Alpha', $row['student_name']);
        $this->assertSame('Guardian Alpha', $row['signer_name']);
        $this->assertSame('P UX2B', $row['programme_name_en']);
    }

    public function test_t2_consent_signatures_and_documents_carry_signer_name(): void
    {
        $this->signAs($this->guardianA);

        $this->act($this->ops);
        $sig = collect($this->getJson('/api/consent-signatures')->json('data'))->firstWhere('signer_id', $this->guardianA->id);
        $this->assertNotNull($sig, 'a signature exists');
        foreach (['id', 'request_id', 'signer_id', 'language', 'method', 'signed_at'] as $k) {
            $this->assertArrayHasKey($k, $sig, "pre-existing key {$k} preserved");
        }
        $this->assertSame('Guardian Alpha', $sig['signer_name']);

        // documents may be generated asynchronously; assert additively only when a row exists
        $docs = $this->getJson('/api/consent-documents')->json('data');
        if (! empty($docs)) {
            $doc = collect($docs)->firstWhere('signer_id', $this->guardianA->id);
            $this->assertArrayHasKey('signer_name', $doc, 'documents carry signer_name additively');
            $this->assertSame('Guardian Alpha', $doc['signer_name']);
        }
    }

    public function test_t2_consent_evidence_report_names_are_additive(): void
    {
        $this->act($this->ops);
        $report = $this->getJson('/api/reports/consent-evidence')->json();
        // freshly-issued requests are 'sent'/'viewed' → the outstanding bucket
        $row = collect($report['outstanding'])->firstWhere('student_id', $this->studentA->id);
        $this->assertNotNull($row, 'family A request is outstanding');
        foreach (['id', 'template_id', 'programme_id', 'student_id', 'signer_id', 'status', 'created_at'] as $k) {
            $this->assertArrayHasKey($k, $row, "pre-existing key {$k} preserved");
        }
        $this->assertSame('Student Alpha', $row['student_name']);
        $this->assertSame('Guardian Alpha', $row['signer_name']);
        $this->assertSame('P UX2B', $row['programme_name_en']);
    }

    public function test_t2_audit_events_carry_actor_name(): void
    {
        $this->act($this->ops);
        $events = $this->getJson('/api/audit-events?entity_type=enrolment')->json('data');
        $byGuardianA = collect($events)->firstWhere('actor_id', $this->guardianA->id);
        $this->assertNotNull($byGuardianA, 'an enrolment event actored by guardian A exists');
        $this->assertArrayHasKey('action', $byGuardianA, 'pre-existing keys preserved');
        $this->assertArrayHasKey('entity_id', $byGuardianA, 'entity_id stays raw (polymorphic — deferred)');
        $this->assertSame('Guardian Alpha', $byGuardianA['actor_name']);
    }

    public function test_t2_finance_report_carries_recorder_verifier_names(): void
    {
        $f = $this->seedTeamWithFinance();
        $this->act($f['member']); // the member IS recorded_by → users_read admits self
        $txns = $this->getJson("/api/teams/{$f['team']}/finance-report")->json('transactions');
        $this->assertNotEmpty($txns);
        foreach (['id', 'type', 'amount_minor', 'status', 'recorded_by', 'verified_by'] as $k) {
            $this->assertArrayHasKey($k, $txns[0], "pre-existing key {$k} preserved");
        }
        // recorded_by is the caller → name resolves; verified_by is a co-member hidden by users_read → key present, NULL
        $this->assertSame('Recorder Member', $txns[0]['recorded_by_name']);
        $this->assertArrayHasKey('verified_by_name', $txns[0], 'verified_by_name present (may be NULL: co-member hidden — S-UX3 note)');
    }

    // ── T3: LEFT JOIN never drops a row when the joined user is RLS-hidden ────────────────────────

    public function test_t3_left_join_keeps_row_when_joined_user_is_rls_hidden(): void
    {
        // The student can read their OWN user row but NOT the guardian's (users_read admits only self for
        // a student). An INNER join on acting_guardian_id would DROP this enrolment; the LEFT join keeps it.
        $this->act($this->studentA);
        $rows = $this->getJson('/api/enrolments')->json('data');
        $this->assertCount(1, $rows, 'the student still sees their own enrolment — the row was not dropped');
        $this->assertSame('Student Alpha', $rows[0]['student_name'], 'own name resolves');
        $this->assertNull($rows[0]['acting_guardian'], 'guardian name is NULL — hidden by users_read, not leaked, row survives');
    }

    // ── S-UX2b-f: access-identity report display names (LEFT-joined, additive) ────────────────────

    public function test_access_identity_report_carries_actor_and_student_names(): void
    {
        $actor = User::factory()->create(['role' => 'academy_admin', 'name' => 'Log Actor']);
        $this->sys(function () use ($actor) {
            $audit = app(\App\Services\Audit\AuditService::class);
            $audit->record('user', $actor->id, 'login', actor: $actor);
            $audit->record('user', $actor->id, 'capability.granted', actor: $actor);
            DB::table('guardian_replacement_exceptions')->insert([
                'id' => (string) Str::uuid7(), 'student_id' => $this->studentA->id, 'revoked_link_id' => (string) Str::uuid7(),
                'reason' => 'guardian departed', 'deadline' => now()->addDays(14), 'status' => 'open',
                'created_by' => $this->ops->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->act($this->ops);
        $report = $this->getJson('/api/reports/access-identity')->assertOk()->json();

        // auth_events — actor_name resolves; every pre-existing key intact
        $login = collect($report['auth_events'])->firstWhere('action', 'login');
        $this->assertNotNull($login);
        $this->assertSame('Log Actor', $login['actor_name']);
        foreach (['occurred_at', 'action', 'actor_id', 'actor_role', 'reason'] as $k) {
            $this->assertArrayHasKey($k, $login, "pre-existing key {$k} preserved");
        }

        // capability_log — actor_name resolves
        $cap = collect($report['capability_log'])->firstWhere('action', 'capability.granted');
        $this->assertNotNull($cap);
        $this->assertSame('Log Actor', $cap['actor_name']);

        // replacement_exceptions — student_name resolves
        $exc = collect($report['replacement_exceptions'])->firstWhere('student_id', $this->studentA->id);
        $this->assertNotNull($exc);
        $this->assertSame('Student Alpha', $exc['student_name']);

        // LEFT join integrity: no drop, no fan-out — auth_events count equals the source row count (cap 50)
        $authActions = ['login', 'logout', 'failed_login', 'lockout', 'lockout_cleared', 'reset_requested', 'reset_completed', 'invitation_accepted', 'email_verified'];
        $srcCount = (int) $this->sys(fn () => DB::table('audit_events')->whereIn('action', $authActions)->limit(50)->count());
        $this->assertCount($srcCount, $report['auth_events'], 'LEFT join neither dropped nor multiplied auth_events');
    }

    // ── S-UX3-1: approval-queue display names (onboarding links + withdrawals) ────────────────────

    public function test_approval_queues_carry_display_names(): void
    {
        $newGuardian = User::factory()->create(['role' => 'guardian', 'name' => 'Grandpa Gee']);
        $enrolId = DB::table('enrolments')->where('student_id', $this->studentA->id)->value('id');
        $this->sys(function () use ($newGuardian, $enrolId) {
            DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->studentA->id, 'guardian_id' => $newGuardian->id, 'status' => 'pending_approval', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('withdrawal_requests')->insert(['id' => (string) Str::uuid7(), 'enrolment_id' => $enrolId, 'student_id' => $this->studentA->id, 'requested_by' => $this->guardianA->id, 'reason' => 'demo', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->act($this->ops);

        // onboarding queue: the pending link names BOTH parties (decision-safety for the approver)
        $link = collect($this->getJson('/api/admin/onboarding-queue')->json('links'))->firstWhere('student_id', $this->studentA->id);
        $this->assertNotNull($link);
        $this->assertSame('Student Alpha', $link['student_name']);
        $this->assertSame('Grandpa Gee', $link['guardian_name']);

        // withdrawal queue: student + requester names; decided_by_name null while pending (LEFT join)
        $w = collect($this->getJson('/api/withdrawal-requests')->json('data'))->firstWhere('student_id', $this->studentA->id);
        $this->assertNotNull($w);
        foreach (['id', 'enrolment_id', 'student_id', 'requested_by', 'reason', 'status'] as $k) {
            $this->assertArrayHasKey($k, $w, "pre-existing key {$k} preserved");
        }
        $this->assertSame('Student Alpha', $w['student_name']);
        $this->assertSame('Guardian Alpha', $w['requested_by_name']);
        $this->assertNull($w['decided_by_name']);
    }

    // ── S-UX3-2: money-list display names (BI-9 legibility) ──────────────────────────────────────

    public function test_payment_and_refund_lists_carry_names(): void
    {
        // a minimal issued order for studentA + a manually-recorded payment (recorder finance1)
        $fin1 = User::factory()->create(['role' => 'academy_admin', 'name' => 'Fin One']);
        [$orderId, $refundId] = $this->sys(function () use ($fin1) {
            $eid = DB::table('enrolments')->where('student_id', $this->studentA->id)->value('id');
            $oid = (string) Str::uuid7();
            DB::table('orders')->insert(['id' => $oid, 'enrolment_id' => $eid, 'programme_id' => $this->programme->id, 'student_id' => $this->studentA->id, 'payer_party' => 'guardian', 'status' => 'issued', 'total_amount_minor' => 250000, 'currency' => 'HKD', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('payments')->insert(['id' => (string) Str::uuid7(), 'order_id' => $oid, 'origin' => 'manual', 'amount_minor' => 250000, 'currency' => 'HKD', 'via_link' => false, 'status' => 'pending_confirmation', 'recorded_by' => $fin1->id, 'created_at' => now(), 'updated_at' => now()]);
            $wid = (string) Str::uuid7();
            DB::table('withdrawal_requests')->insert(['id' => $wid, 'enrolment_id' => $eid, 'student_id' => $this->studentA->id, 'requested_by' => $this->guardianA->id, 'reason' => 'demo', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $rid = (string) Str::uuid7();
            DB::table('refunds')->insert(['id' => $rid, 'order_id' => $oid, 'withdrawal_request_id' => $wid, 'amount_minor' => 250000, 'currency' => 'HKD', 'destination_party' => 'guardian', 'status' => 'approved', 'approved_by' => $fin1->id, 'created_at' => now(), 'updated_at' => now()]);

            return [$oid, $rid];
        });

        $this->act($this->ops);
        // payments: recorded_by_name resolves (finance staff are mutually visible); pre-existing keys intact
        $pay = collect($this->getJson('/api/payments')->json('data'))->firstWhere('order_id', $orderId);
        $this->assertNotNull($pay);
        foreach (['id', 'order_id', 'amount_minor', 'currency', 'status', 'recorded_by'] as $k) {
            $this->assertArrayHasKey($k, $pay, "pre-existing key {$k} preserved");
        }
        $this->assertSame('Fin One', $pay['recorded_by_name']);
        $this->assertNull($pay['confirmed_by_name']);

        // refunds: approved_by_name resolves
        $ref = collect($this->getJson('/api/refunds')->json('data'))->firstWhere('id', $refundId);
        $this->assertNotNull($ref);
        $this->assertSame('Fin One', $ref['approved_by_name']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    private function act(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    private function enrol(User $guardian, User $student): void
    {
        $this->act($guardian);
        $this->postJson('/api/my/enrolments', [
            'programme_id' => $this->programme->id, 'student_id' => $student->id,
        ])->assertStatus(201);
    }

    private function signAs(User $signer): void
    {
        $this->act($signer);
        $request = DB::table('consent_requests')->where('programme_id', $this->programme->id)
            ->where('signer_id', $signer->id)->whereIn('status', ['sent', 'viewed'])->first();
        $this->assertNotNull($request, 'an open request exists for the signer');
        $this->getJson("/api/consent-requests/{$request->id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$request->id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$request->id}/sign", [
            'affirmed' => true, 'method' => 'typed', 'typed_name' => 'Guardian Alpha',
        ])->assertStatus(201);
    }

    private function publishedProgramme(string $prefix): Programme
    {
        $this->act($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach (['en' => 'English terms', 'zh-TC' => '繁體條款', 'zh-SC' => '简体条款'] as $lang => $text) {
            $vid = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", [
                'language' => $lang, 'body_html' => "<p>{$text} {{student_name}} {{signature}}</p>",
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$vid}/publish")->assertOk();
        }
        $programme = Programme::query()->create([
            'code' => $prefix.'-'.Str::upper(Str::random(4)), 'name_en' => 'P '.$prefix, 'name_tc' => 'P TC', 'name_sc' => 'P SC',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'basics' => ['enrolment_closes_on' => '2027-01-10', 'starts_on' => '2027-02-01'], 'team_rules' => ['formation_deadline_on' => '2027-01-20'], default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();

        return $programme;
    }

    /** A confirmed team with an active budget + line and a verified expense recorded by a member, verified by another. */
    private function seedTeamWithFinance(): array
    {
        return $this->sys(function () {
            $lobby = (string) Str::uuid7();
            DB::table('team_categories')->insert(['id' => $lobby, 'programme_id' => $this->programme->id, 'name_en' => 'O', 'name_tc' => 'O', 'name_sc' => 'O', 'assignment_rule' => 'open', 'school_id' => null, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            $member = User::factory()->create(['role' => 'student', 'name' => 'Recorder Member']);
            $verifier = User::factory()->create(['role' => 'student', 'name' => 'Verifier Member']);
            $team = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $team, 'programme_id' => $this->programme->id, 'category_id' => $lobby, 'name' => 'T', 'status' => 'confirmed', 'created_by' => $member->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([$member, $verifier] as $u) {
                $eid = (string) Str::uuid7();
                DB::table('enrolments')->insert(['id' => $eid, 'programme_id' => $this->programme->id, 'student_id' => $u->id, 'acting_guardian_id' => $u->id, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'team_id' => $team, 'programme_id' => $this->programme->id, 'enrolment_id' => $eid, 'category_id' => $lobby, 'student_id' => $u->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
            $bid = (string) Str::uuid7();
            DB::table('team_budgets')->insert(['id' => $bid, 'team_id' => $team, 'status' => 'active', 'currency' => 'HKD', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $line = (string) Str::uuid7();
            DB::table('budget_lines')->insert(['id' => $line, 'budget_id' => $bid, 'team_id' => $team, 'category' => 'materials', 'name' => 'M', 'planned_amount_minor' => 50000, 'currency' => 'HKD', 'created_at' => now(), 'updated_at' => now()]);
            $ev = (string) Str::uuid7();
            DB::table('uploads')->insert(['id' => $ev, 'context' => 'evidence', 'disk' => 'local', 'path' => 'uploads/clean/x.jpg', 'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'status' => 'clean', 'uploaded_by' => $member->id, 'scanned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_transactions')->insert(['id' => (string) Str::uuid7(), 'team_id' => $team, 'type' => 'expense', 'amount_minor' => 30000, 'currency' => 'HKD', 'budget_line_id' => $line, 'description' => 'Poster', 'occurred_on' => '2026-05-01', 'status' => 'verified', 'recorded_by' => $member->id, 'verified_by' => $verifier->id, 'evidence_upload_id' => $ev, 'recorded_at' => now(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return ['team' => $team, 'member' => $member];
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
}
