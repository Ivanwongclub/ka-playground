<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Consent\ConsentSigningService;
use App\Services\Consent\ConsentTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-TTL-1 — the consent expiry clock and its sweeper.
 *
 * Before this card `consent_requests.expires_at` had no writer anywhere in app/, and `expired` was the one
 * dead value in an otherwise live status enum. These tests pin both halves: the clock is set (and clamped)
 * at the single insert every issuance path funnels through, and the sweeper moves ONLY undecided requests.
 *
 * PART B is here too (same card): ProgrammeController must now REFUSE the two enrolment-window fields,
 * because WizardService::syncBasicsDates is their sole writer.
 */
class ConsentTtlTest extends TestCase
{
    use RefreshDatabase;

    private const TTL_DAYS = 14;

    private User $ops;

    private User $guardian;

    private User $student;

    private string $templateId;

    private Programme $programme; // starts 2027-02-01 — far beyond the TTL, so unclamped

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $cap) {
            DB::table('admin_capabilities')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $this->ops->id,
                'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now(),
            ]);
        }
        $this->guardian = User::factory()->create(['role' => 'guardian']);
        $this->student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $this->student->id,
            'guardian_id' => $this->guardian->id, 'status' => 'active', 'origin' => 'onboarding',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->ops);
        $this->templateId = $this->postJson('/api/admin/consent-templates', [
            'name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲',
        ])->json('id');
        foreach ([
            'en' => '<p>EN {{student_name}} {{programme_name}} {{fee_total}} {{signature}}</p>',
            'zh-TC' => '<p>TC {{student_name}} {{signature}}</p>',
            'zh-SC' => '<p>SC {{student_name}} {{signature}}</p>',
        ] as $lang => $body) {
            $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", [
                'language' => $lang, 'body_html' => $body,
            ])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$vid}/publish")->assertOk();
        }
        $this->programme = $this->publishedProgramme('2027-01-10', '2027-01-20', '2027-02-01');
    }

    /** A published programme selecting the template — the only state in which a request is issuable. */
    private function publishedProgramme(string $closesOn, string $formationOn, string $startsOn): Programme
    {
        $p = Programme::query()->create([
            'code' => 'TTL-'.Str::upper(Str::random(6)), 'name_en' => 'TTL P', 'name_tc' => 'P', 'name_sc' => 'P',
            'jurisdiction' => 'HK',
        ]);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $this->templateId],
                'basics' => ['enrolment_closes_on' => $closesOn, 'starts_on' => $startsOn],
                'team_rules' => ['formation_deadline_on' => $formationOn],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$p->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$p->id}/publish")->assertOk();

        return $p->fresh();
    }

    private function issue(?Programme $programme = null): string
    {
        return $this->issueConsentRequest($this->templateId, ($programme ?? $this->programme)->id,
            $this->student->id, $this->guardian->id, $this->ops);
    }

    private function expiryOf(string $id): Carbon
    {
        return Carbon::parse(DB::table('consent_requests')->where('id', $id)->value('expires_at'));
    }

    // ── the writer ───────────────────────────────────────────────────────────────────────────────────

    public function test_issue_sets_the_ttl_when_the_programme_start_is_far_off(): void
    {
        $id = $this->issue();
        // 2027-02-01 is far beyond now+14d, so the clamp does not bite and the constant governs.
        $this->assertEqualsWithDelta(
            now()->addDays(self::TTL_DAYS)->timestamp,
            $this->expiryOf($id)->timestamp,
            60, // seconds — clock drift across the request, not a fudge factor
        );
    }

    public function test_expiry_is_clamped_to_a_start_inside_the_ttl(): void
    {
        // Starts in 5 days: an unsigned consent is worthless once the programme has begun, so the deadline
        // must not outlive the thing it gates.
        $soon = $this->publishedProgramme(now()->addDays(2)->toDateString(), now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString());
        $expiry = $this->expiryOf($this->issue($soon));

        $this->assertTrue($expiry->lessThan(now()->addDays(self::TTL_DAYS)), 'the clamp did not bite');
        $this->assertSame(
            Carbon::parse($soon->starts_at)->timestamp,
            $expiry->timestamp,
            'clamped expiry must equal the programme start instant',
        );
    }

    public function test_a_started_programme_does_not_mint_a_born_expired_consent(): void
    {
        // Nothing enforces the enrolment window AT enrolment time, so a family can legitimately enrol into a
        // running programme. Clamping to a PAST start would mint a consent that is already expired — the
        // family could never sign it and the sweeper would kill it on the next pass.
        $started = $this->publishedProgramme('2026-01-10', '2026-01-20', '2026-02-01');
        $expiry = $this->expiryOf($this->issue($started));

        $this->assertTrue($expiry->greaterThan(now()), 'a consent must never be born expired');
        $this->assertEqualsWithDelta(now()->addDays(self::TTL_DAYS)->timestamp, $expiry->timestamp, 60);
    }

    public function test_reissue_rearms_the_clock(): void
    {
        $first = $this->issue();
        DB::table('consent_requests')->where('id', $first)->update([
            'expires_at' => now()->subDay(), 'status' => 'expired',
        ]);
        $second = $this->issue();

        $this->assertNotSame($first, $second);
        $this->assertTrue($this->expiryOf($second)->greaterThan(now()), 're-issue must mint a live clock');
    }

    public function test_the_od20a_supersede_fanout_rearms_the_clock(): void
    {
        // The fan-out supersedes the signed request and issues a replacement through the SAME issueRequest,
        // which is why no caller has to know the clock exists.
        $id = $this->issue();
        $this->signAs($id);

        // Driven the real way: publishing a MATERIAL new version of the signed language is what triggers
        // the fan-out (supersedeForLanguage is private and reached only through publishVersion).
        Sanctum::actingAs($this->ops);
        $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", [
            'language' => 'en', 'body_html' => '<p>EN v2 MATERIAL {{student_name}} {{signature}}</p>',
        ])->json('version_id');
        app(ConsentTemplateService::class)
            ->publishVersion($vid, $this->ops, isMaterial: true);

        $replacement = DB::table('consent_requests')
            ->where('student_id', $this->student->id)->where('id', '<>', $id)
            ->whereIn('status', ['sent', 'viewed'])->first();
        $this->assertNotNull($replacement, 'the fan-out issued no replacement request');
        $this->assertNotNull($replacement->expires_at, 'the replacement was minted with no clock');
        $this->assertTrue(Carbon::parse($replacement->expires_at)->greaterThan(now()));
    }

    private function signAs(string $id): void
    {
        $signing = app(ConsentSigningService::class);
        $req = DB::table('consent_requests')->where('id', $id)->first();
        $signing->renderForSigner($req, 'en', $this->guardian);
        $req = DB::table('consent_requests')->where('id', $id)->first();
        $signing->recordScrolledToEnd($req, $this->guardian);
        $req = DB::table('consent_requests')->where('id', $id)->first();
        $signing->sign($req, ['affirmed' => true, 'method' => 'typed', 'typed_name' => $this->guardian->name], $this->guardian, '127.0.0.1', 'phpunit');
    }

    // ── the sweeper ──────────────────────────────────────────────────────────────────────────────────

    /** Force a request into $status with a past expiry — the state the sweeper is meant to find. */
    private function overdue(string $status): string
    {
        $id = (string) Str::uuid7();
        DB::table('consent_requests')->insert([
            'id' => $id, 'template_id' => $this->templateId, 'programme_id' => $this->programme->id,
            'student_id' => $this->student->id, 'signer_id' => $this->guardian->id, 'status' => $status,
            'merge_data' => '{}', 'event_sequence' => '[]', 'expires_at' => now()->subDays(2),
            'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20),
        ]);

        return $id;
    }

    public function test_sweeper_expires_only_undecided_requests(): void
    {
        $moved = ['sent' => $this->overdue('sent'), 'viewed' => $this->overdue('viewed')];
        // Decided requests are terminal and are never touched, whatever their expires_at says. The STATUS is
        // the guard, not the date.
        $untouched = [];
        foreach (['signed', 'declined', 'superseded', 'voided'] as $st) {
            $untouched[$st] = $this->overdue($st);
        }

        $this->assertSame(2, app(ConsentSigningService::class)->expireOverdue());

        foreach ($moved as $was => $id) {
            $this->assertSame('expired', DB::table('consent_requests')->where('id', $id)->value('status'), "{$was} should have expired");
        }
        foreach ($untouched as $st => $id) {
            $this->assertSame($st, DB::table('consent_requests')->where('id', $id)->value('status'), "{$st} must never be swept");
        }
    }

    public function test_sweeper_is_idempotent(): void
    {
        $this->overdue('sent');
        $this->assertSame(1, app(ConsentSigningService::class)->expireOverdue());
        $this->assertSame(0, app(ConsentSigningService::class)->expireOverdue(), 'a second pass must find nothing');
    }

    public function test_sweeper_ignores_a_null_expiry_and_a_live_one(): void
    {
        // NULL = issued before this card. Guessed values are worse than none, so it leaves them alone.
        $legacy = $this->issue();
        DB::table('consent_requests')->where('id', $legacy)->update(['expires_at' => null]);
        $live = $this->issue(); // clock still in the future

        $this->assertSame(0, app(ConsentSigningService::class)->expireOverdue());
        $this->assertSame('sent', DB::table('consent_requests')->where('id', $legacy)->value('status'));
        $this->assertSame('sent', DB::table('consent_requests')->where('id', $live)->value('status'));
    }

    public function test_expiry_audits_with_the_system_actor(): void
    {
        $id = $this->overdue('sent');
        // The scheduler runs with NO authenticated user, which is the condition that makes attribution
        // SYSTEM (OD-64: attribution is never null). setUp leaves an acting admin behind, so forget the
        // guards first — otherwise this would assert the test harness's session, not the cron's behaviour.
        $this->app['auth']->forgetGuards();
        app(ConsentSigningService::class)->expireOverdue();

        $event = DB::table('audit_events')->where('entity_type', 'consent_request')
            ->where('entity_id', $id)->where('action', 'consent_request.expired')->first();
        $this->assertNotNull($event, 'BI-8: every status transition audits');
        $this->assertSame('sent', $event->from_state);
        $this->assertSame('expired', $event->to_state);
        $this->assertSame('system', $event->actor_role);
    }

    public function test_the_sweeper_never_touches_the_enrolment(): void
    {
        // BI-7: nothing writes Withdrawn outside the withdrawal workflow, and releasing a child-safety
        // relationship on a timer is not a sweeper's decision. The enrolment is left exactly where it is.
        $enrolmentId = (string) Str::uuid7();
        DB::table('enrolments')->insert([
            'id' => $enrolmentId, 'programme_id' => $this->programme->id, 'student_id' => $this->student->id,
            'acting_guardian_id' => $this->guardian->id, 'status' => 'pending_consent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->overdue('sent');
        app(ConsentSigningService::class)->expireOverdue();

        $this->assertSame('pending_consent', DB::table('enrolments')->where('id', $enrolmentId)->value('status'));
    }

    public function test_the_sweeper_never_re_issues(): void
    {
        $this->overdue('sent');
        $before = DB::table('consent_requests')->count();
        app(ConsentSigningService::class)->expireOverdue();

        $this->assertSame($before, DB::table('consent_requests')->count(), 'expiry must not mint a replacement');
    }

    public function test_the_command_runs_and_reports(): void
    {
        $this->overdue('sent');
        $this->artisan('consents:expire')->expectsOutputToContain('1 consent request(s) expired')->assertSuccessful();
    }

    // ── PART B: the retired window fields ────────────────────────────────────────────────────────────

    public function test_update_rejects_the_enrolment_window_fields(): void
    {
        Sanctum::actingAs($this->ops);
        $base = ['code' => $this->programme->code, 'name_en' => 'X', 'name_tc' => 'X', 'name_sc' => 'X', 'jurisdiction' => 'HK'];

        foreach (['enrolment_opens_at', 'enrolment_closes_at'] as $field) {
            $this->putJson("/api/admin/programmes/{$this->programme->id}", $base + [$field => '2027-01-01'])
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }
    }

    public function test_update_still_works_without_them_and_the_window_survives(): void
    {
        // The columns are still canonical — syncBasicsDates wrote them at publish, and the retirement must
        // not disturb what is already there.
        $before = DB::table('programmes')->where('id', $this->programme->id)->first();
        $this->assertNotNull($before->enrolment_closes_at, 'the mirror should have written the window at publish');

        Sanctum::actingAs($this->ops);
        $this->putJson("/api/admin/programmes/{$this->programme->id}", [
            'code' => $this->programme->code, 'name_en' => 'Renamed', 'name_tc' => 'X', 'name_sc' => 'X', 'jurisdiction' => 'HK',
        ])->assertOk();

        $after = DB::table('programmes')->where('id', $this->programme->id)->first();
        $this->assertSame('Renamed', $after->name_en);
        $this->assertSame($before->enrolment_opens_at, $after->enrolment_opens_at);
        $this->assertSame($before->enrolment_closes_at, $after->enrolment_closes_at);
    }

    public function test_the_overview_read_still_serves_the_window(): void
    {
        Sanctum::actingAs($this->ops);
        $r = $this->getJson("/api/admin/programmes/{$this->programme->id}/overview")->assertOk();
        $this->assertArrayHasKey('enrolment_opens_at', $r->json());
        $this->assertArrayHasKey('enrolment_closes_at', $r->json());
    }
}
