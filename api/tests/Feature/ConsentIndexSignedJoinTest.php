<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

/**
 * B8-GUA-CONSENTS — RIDER for the `consent_signatures` additive join on GET /api/consent-requests,
 * and a BEHAVIOUR-SHA of the twelve pre-existing columns.
 *
 * THE JOIN. The guardian's Consents list must show, on a SIGNED row, the three facts the prototype's
 * who-line carries — "Signed 5 Jul · v3.2 · English". None of them lived on `consent_requests`; all three
 * live on `consent_signatures` (`signed_at`, `language`, `template_version_id` → `consent_template_versions.version`).
 * `consent_signatures.request_id` is UNIQUE, so the join is strictly 1:0..1 and CANNOT fan out a row.
 * It is additive in the S-UX2b sense: a LEFT JOIN gated by the joined table's OWN RLS, so each fact resolves
 * iff the caller could already SELECT that signature row, and resolves to NULL otherwise — never a dropped row.
 *
 * WHAT THAT MEANS PER READER, and what this rider proves empirically rather than by reading the policy:
 *   cs_read = system OR signer_id = actor OR academy_admin(operations|audit_read|super_admin)
 *   - GUARDIAN (the signer)  → the three facts RESOLVE. It is their own signature.
 *   - STUDENT (cr_read's own-row arm) → row visible, three facts NULL. The student learns their consent is
 *     signed (that is `r.status`); they do not learn when, in which language, against which version.
 *   - SCHOOL_ADMIN (cr_read's roll arm, for chasing) → row visible, three facts NULL. Same reasoning.
 *   - OPS/AUDIT → resolve, for compliance. Their own surfaces carry their own audit trail.
 * consent_signatures is the strictest table on the platform. Widening the LIST must not widen the TABLE, and
 * this file is the proof that it did not.
 *
 * WHAT THE JOIN DELIBERATELY DOES NOT CARRY: no `template_sha256`, no `rendered_sha256`, no
 * `signature_payload` (the stroke vectors), no `image_upload_id` (the drawn PNG), no `method`, no
 * `ip_address`, no `user_agent`, no `event_sequence`. Three display facts, nothing evidential. Asserted
 * against the RAW BODY, so a nested leak cannot hide behind a key-set check.
 *
 * THE BEHAVIOUR-SHA. Adding columns to a shared RLS-shaped read is the kind of change that silently reorders
 * keys, multiplies rows, or drops a NULL-joined one. The sha pins the twelve pre-existing columns — key set,
 * key ORDER, row COUNT, row ORDER and every non-volatile value — for two readers with different row sets.
 * The constants below were computed at f0004d1, BEFORE the controller changed, and must survive it unchanged.
 */
class ConsentIndexSignedJoinTest extends TestCase
{
    use RefreshDatabase;

    /** The response shape as it stood before the join. Order is load-bearing: the sha pins it. */
    private const LEGACY_KEYS = [
        'id', 'template_id', 'programme_id', 'student_id', 'signer_id', 'status', 'expires_at',
        'programme_name_en', 'programme_name_tc', 'programme_name_sc', 'student_name', 'signer_name',
    ];

    /** The three the join adds — and the ONLY three. */
    private const JOINED_KEYS = ['signed_at', 'signed_language', 'signed_version'];

    /** Every consent_signatures / evidence field the list must NEVER carry. */
    private const FORBIDDEN = [
        'template_sha256', 'rendered_sha256', 'signature_payload', 'image_upload_id',
        'method', 'ip_address', 'user_agent', 'event_sequence', 'merge_data',
    ];

    private const T0 = '2026-08-01 09:00:00';

    private User $ops;

    private User $guardianA;

    private User $guardianB;

    private User $studentA;    // guardianA's child — SIGNED request

    private User $studentA2;   // guardianA's second child — OUTSTANDING request

    private User $studentB;    // guardianB's child — the cross-family probe

    private User $schoolAdminA;

    private Programme $programme;

    private string $templateId;

    private string $reqA1;     // studentA  · guardianA · signed in zh-TC

    private string $reqA2;     // studentA2 · guardianA · sent, expiring

    private string $reqB1;     // studentB  · guardianB · signed in en

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // signing generates the FR038 PDF inline under the sync queue
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        // Frozen and STEPPED: `index()` orders by r.created_at, so a total order on that column is what makes
        // the behaviour-sha's row order reproducible. Ties would make the sha a coin flip.
        Carbon::setTestNow(self::T0);

        $this->ops = User::factory()->create(['role' => 'academy_admin', 'name' => 'Ops Admin']);
        foreach (['configuration', 'operations'] as $cap) {
            DB::table('admin_capabilities')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $this->ops->id,
                'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now(),
            ]);
        }

        $this->guardianA = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian Alpha']);
        $this->guardianB = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian Bravo']);
        $this->studentA = User::factory()->create(['role' => 'student', 'name' => 'Child Alpha']);
        $this->studentA2 = User::factory()->create(['role' => 'student', 'name' => 'Child Alpha Two']);
        $this->studentB = User::factory()->create(['role' => 'student', 'name' => 'Child Bravo']);

        $school = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        $this->schoolAdminA = User::factory()->create(['role' => 'school_admin', 'name' => 'School Admin A']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(),
            'school_admin_id' => $this->schoolAdminA->id, 'school_id' => $school->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([[$this->studentA, $this->guardianA], [$this->studentA2, $this->guardianA], [$this->studentB, $this->guardianB]] as [$student, $guardian]) {
            DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(),
                'student_id' => $student->id, 'guardian_id' => $guardian->id,
                'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        }
        // Only family A is on school A's roll — school_admin B's absence is what makes the roll arm a real probe.
        foreach ([$this->studentA, $this->studentA2] as $student) {
            DB::table('school_links')->insert(['id' => (string) Str::uuid7(),
                'student_id' => $student->id, 'school_id' => $school->id,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->publishedProgramme();

        Carbon::setTestNow(Carbon::parse(self::T0)->addMinutes(1));
        $this->reqA1 = $this->issue($this->studentA, $this->guardianA);
        Carbon::setTestNow(Carbon::parse(self::T0)->addMinutes(2));
        $this->reqA2 = $this->issue($this->studentA2, $this->guardianA);
        Carbon::setTestNow(Carbon::parse(self::T0)->addMinutes(3));
        $this->reqB1 = $this->issue($this->studentB, $this->guardianB);

        // The RENDERED language is what gets recorded — A signs zh-TC, B signs en, so a leak across the
        // family boundary would show up as the wrong language, not merely as an extra row.
        Carbon::setTestNow(Carbon::parse(self::T0)->addMinutes(10));
        $this->sign($this->guardianA, $this->reqA1, 'zh-TC');
        Carbon::setTestNow(Carbon::parse(self::T0)->addMinutes(11));
        $this->sign($this->guardianB, $this->reqB1, 'en');

        // issueRequest leaves expires_at NULL (the hold window is applied on the enrolment path); the
        // outstanding row needs a real deadline for the surface's urgency to mean anything.
        Carbon::setTestNow(Carbon::parse(self::T0)->addMinutes(12));
        DB::table('consent_requests')->where('id', $this->reqA2)
            ->update(['expires_at' => Carbon::parse(self::T0)->addDays(6)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── RIDER ────────────────────────────────────────────────────────────────────────────────────────

    public function test_rider_guardian_reads_own_children_and_the_signature_facts_resolve(): void
    {
        $rows = $this->rowsAs($this->guardianA);
        $this->assertSame([$this->reqA1, $this->reqA2], array_keys($rows), 'guardian A sees exactly their own two rows, in created_at order');

        // The SIGNED row — the three facts the prototype's who-line needs, and they are the SIGNER'S OWN.
        $this->assertSame('signed', $rows[$this->reqA1]['status']);
        $this->assertNotNull($rows[$this->reqA1]['signed_at']);
        $this->assertStringStartsWith('2026-08-01 09:10:00', (string) $rows[$this->reqA1]['signed_at']);
        $this->assertSame('zh-TC', $rows[$this->reqA1]['signed_language'], 'the language RECORDED is the one rendered, not a preference');
        $this->assertSame(1, $rows[$this->reqA1]['signed_version']);

        // The OUTSTANDING row — no signature exists, so all three are NULL and the row still stands.
        $this->assertSame('sent', $rows[$this->reqA2]['status']);
        $this->assertNull($rows[$this->reqA2]['signed_at']);
        $this->assertNull($rows[$this->reqA2]['signed_language']);
        $this->assertNull($rows[$this->reqA2]['signed_version']);
        $this->assertNotNull($rows[$this->reqA2]['expires_at'], 'the outstanding row keeps its deadline — urgency is computed from it');
    }

    public function test_rider_student_reads_own_row_but_never_the_signature_facts(): void
    {
        // cr_read admits the student to their OWN request row; cs_read does not admit them to the signature.
        // So the student learns THAT consent is signed and nothing about the signing act.
        $rows = $this->rowsAs($this->studentA);
        $this->assertSame([$this->reqA1], array_keys($rows), 'the student sees their own row only — not their sibling\'s');
        $this->assertSame('signed', $rows[$this->reqA1]['status'], 'the state is visible');
        foreach (self::JOINED_KEYS as $k) {
            $this->assertArrayHasKey($k, $rows[$this->reqA1], "{$k} is present as a key (the LEFT JOIN never drops the row)");
            $this->assertNull($rows[$this->reqA1][$k], "{$k} must be NULL for a non-signer — cs_read refused it");
        }
    }

    public function test_rider_school_admin_chases_the_row_without_reading_the_signature(): void
    {
        // The roll arm exists so a school can CHASE an outstanding consent. Chasing needs the state, not the act.
        $rows = $this->rowsAs($this->schoolAdminA);
        $this->assertSame([$this->reqA1, $this->reqA2], array_keys($rows), 'school A\'s roll, and only school A\'s roll');
        $this->assertArrayNotHasKey($this->reqB1, $rows, 'CROSS-SCHOOL: family B is not on this roll');
        foreach (self::JOINED_KEYS as $k) {
            $this->assertNull($rows[$this->reqA1][$k], "{$k} must be NULL for a school admin");
        }
    }

    public function test_rider_cross_family_holds_in_both_directions(): void
    {
        $a = $this->rowsAs($this->guardianA);
        $this->assertArrayNotHasKey($this->reqB1, $a, 'guardian A never reaches family B\'s request');

        $b = $this->rowsAs($this->guardianB);
        $this->assertSame([$this->reqB1], array_keys($b), 'guardian B sees their own row only');
        $this->assertSame('en', $b[$this->reqB1]['signed_language']);
        $this->assertArrayNotHasKey($this->reqA1, $b, 'guardian B never reaches family A\'s request');

        // A signature fact of the OTHER family must not appear anywhere in the serialised body — including
        // nested. B signed in `en`, A in `zh-TC`, so a bleed would be legible as the wrong language.
        $this->act($this->guardianB);
        $this->assertStringNotContainsString('zh-TC', $this->getJson('/api/consent-requests')->assertOk()->getContent());
    }

    public function test_rider_a_stranger_sees_nothing_and_the_read_stays_rls_shaped(): void
    {
        $this->assertSame([], $this->rowsAs(User::factory()->create(['role' => 'guardian'])));
        $this->assertSame([], $this->rowsAs(User::factory()->create(['role' => 'teacher'])));
        $this->assertSame([], $this->rowsAs(User::factory()->create(['role' => 'member'])));
    }

    public function test_rider_ops_resolves_the_signature_facts_for_compliance(): void
    {
        $rows = $this->rowsAs($this->ops);
        $this->assertSame([$this->reqA1, $this->reqA2, $this->reqB1], array_keys($rows), 'ops sees all three — the join neither multiplied nor dropped');
        $this->assertSame('zh-TC', $rows[$this->reqA1]['signed_language']);
        $this->assertSame('en', $rows[$this->reqB1]['signed_language']);
        $this->assertSame(1, $rows[$this->reqA1]['signed_version']);
        $this->assertNull($rows[$this->reqA2]['signed_at']);
    }

    public function test_rider_payload_carries_no_signature_image_or_hash_fields(): void
    {
        foreach (['guardian' => $this->guardianA, 'ops' => $this->ops, 'student' => $this->studentA] as $label => $actor) {
            $this->act($actor);
            $body = $this->getJson('/api/consent-requests')->assertOk()->getContent();
            foreach (self::FORBIDDEN as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "{$label}: the list leaked `{$forbidden}` — evidence is not a display fact");
            }
        }

        // Positive control: the columns the join DOES add, and no others beyond the legacy twelve.
        $row = $this->rowsAs($this->guardianA)[$this->reqA1];
        $this->assertSame([...self::LEGACY_KEYS, ...self::JOINED_KEYS], array_keys($row), 'exactly twelve legacy keys then exactly three joined keys');
    }

    public function test_rider_the_join_is_one_to_zero_or_one_and_never_fans_out(): void
    {
        // The structural guarantee: consent_signatures.request_id is UNIQUE, so one request can never
        // acquire a second signature row and the join can never multiply. Asserted at the schema, then observed.
        $this->assertTrue(
            DB::selectOne("SELECT 1 AS ok FROM pg_indexes WHERE tablename = 'consent_signatures'
                AND indexdef ILIKE '%UNIQUE%' AND indexdef ILIKE '%(request_id)%'") !== null,
            'consent_signatures.request_id must carry a UNIQUE index — the join\'s 1:0..1 cardinality rests on it',
        );

        $rows = $this->rowsAs($this->ops);
        $this->assertCount(3, $rows, 'three requests in, three rows out');
        $this->assertSame(2, DB::table('consent_signatures')->count(), 'two signatures across three requests — one request is unsigned');
    }

    // ── BEHAVIOUR-SHA — the twelve pre-existing columns, pinned ───────────────────────────────────────

    public function test_behaviour_sha_of_the_legacy_columns_is_unchanged_for_the_guardian(): void
    {
        $this->assertSame(
            'f2ec85b81f52a75ec53a05bcd6b5ee605c9fa1075814fa1d29df9dc897135e62',
            $this->legacySha($this->guardianA),
            'the twelve pre-existing columns changed for the GUARDIAN — key set, key order, row count, row order or a value',
        );
    }

    public function test_behaviour_sha_of_the_legacy_columns_is_unchanged_for_ops(): void
    {
        $this->assertSame(
            '39ea8fd18e0f531ea2799f41397046472d3b494d42ea0ee359e4990a50ee0e32',
            $this->legacySha($this->ops),
            'the twelve pre-existing columns changed for OPS — key set, key order, row count, row order or a value',
        );
    }

    public function test_behaviour_sha_projection_still_leads_with_the_legacy_key_order(): void
    {
        // The sha would catch a reorder, but only opaquely. This says out loud what it is pinning.
        foreach ($this->rowsAs($this->ops) as $row) {
            $this->assertSame(self::LEGACY_KEYS, array_slice(array_keys($row), 0, count(self::LEGACY_KEYS)));
        }
    }

    /**
     * The twelve legacy columns, with only the genuinely volatile values (uuids, autoincrement ids) mapped to
     * stable labels. Everything else — status, expires_at, all five names — is hashed literally, so a change
     * of VALUE fails as loudly as a change of SHAPE. Row order is preserved, not sorted: it is part of the read.
     */
    private function legacySha(User $actor): string
    {
        $labels = [
            $this->studentA->id => 'STU_A', $this->studentA2->id => 'STU_A2', $this->studentB->id => 'STU_B',
            $this->guardianA->id => 'GUA_A', $this->guardianB->id => 'GUA_B',
        ];
        $requests = [$this->reqA1 => 'REQ_A1', $this->reqA2 => 'REQ_A2', $this->reqB1 => 'REQ_B1'];

        $this->act($actor);
        $projected = [];
        foreach ($this->getJson('/api/consent-requests')->assertOk()->json('data') as $row) {
            $p = [];
            foreach (self::LEGACY_KEYS as $k) {
                $this->assertArrayHasKey($k, $row, "pre-existing key `{$k}` vanished from the read");
                $p[$k] = $row[$k];
            }
            $p['id'] = $requests[$p['id']] ?? 'REQ_UNKNOWN';
            $p['template_id'] = 'TEMPLATE';                                  // uuid, per-run
            $p['programme_id'] = 'PROGRAMME';                                // autoincrement, per-run
            $p['student_id'] = $labels[$p['student_id']] ?? 'USER_UNKNOWN';  // autoincrement, per-run
            $p['signer_id'] = $labels[$p['signer_id']] ?? 'USER_UNKNOWN';
            $projected[] = $p;
        }

        return hash('sha256', (string) json_encode($projected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ── fixture ──────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> request id → row */
    private function rowsAs(User $actor): array
    {
        $this->act($actor);
        $out = [];
        foreach ($this->getJson('/api/consent-requests')->assertOk()->json('data') as $row) {
            $out[$row['id']] = $row;
        }

        return $out;
    }

    private function act(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    private function issue(User $student, User $guardian): string
    {
        return $this->issueConsentRequest($this->templateId, $this->programme->id, $student->id, $guardian->id, $this->ops);
    }

    private function sign(User $signer, string $requestId, string $language): void
    {
        $this->act($signer);
        $this->getJson("/api/consent-requests/{$requestId}/document?language={$language}")->assertOk();
        $this->postJson("/api/consent-requests/{$requestId}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$requestId}/sign", [
            'affirmed' => true, 'method' => 'typed', 'typed_name' => $signer->name,
        ])->assertStatus(201);
    }

    private function publishedProgramme(): void
    {
        $this->act($this->ops);
        $this->templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach (['en' => 'English terms', 'zh-TC' => '繁體條款', 'zh-SC' => '简体条款'] as $lang => $text) {
            $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", [
                'language' => $lang, 'body_html' => "<p>{$text} {{student_name}} {{signature}}</p>",
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$vid}/publish")->assertOk();
        }
        $this->programme = Programme::query()->create([
            'code' => 'B8-CONSENT', 'name_en' => 'P B8', 'name_tc' => 'P B8 TC', 'name_sc' => 'P B8 SC',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $this->templateId],
                'basics' => ['enrolment_closes_on' => '2027-01-10', 'starts_on' => '2027-02-01'],
                'team_rules' => ['formation_deadline_on' => '2027-01-20'],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();
    }
}
