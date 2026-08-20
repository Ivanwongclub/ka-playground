<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Consent\ConsentSigningService;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Identity\LinkageService;
use App\Services\Reconciliation\Assertions\ConsentIssuanceCompletenessAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

/**
 * S-FIX-consent-reissue — a newly-active guardian is issued consent for the student's pre-confirm
 * enrolments, from BOTH activation seams (approveLink, schoolVouch), idempotently, with the D3 reopen.
 */
class ConsentReissueOnGuardianActivationTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $guardianA;

    private User $studentA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin', 'name' => 'Ops']);
        foreach (['configuration', 'operations'] as $cap) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $cap, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        $this->guardianA = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian A']);
        $this->studentA = User::factory()->create(['role' => 'student', 'name' => 'Student A']);
        $this->activeLink($this->studentA->id, $this->guardianA->id);
    }

    // ── 1. Sam regression: approveLink reissues; issuance_completeness green BECAUSE of the fix ──────

    public function test_approvelink_reissues_and_issuance_completeness_is_green_because_of_the_fix(): void
    {
        [$programme, $templateId] = $this->publishedProgramme('SAM', requiresAll: false);
        $this->enrol($this->guardianA, $programme);

        // a 2nd guardian awaiting approval; before approval, no request for them
        [$guardianB, $linkId] = $this->pendingGuardian($this->studentA->id);
        $this->assertFalse($this->hasRequest($programme->id, $guardianB->id));

        // approve the link → the event fires → the listener re-issues (synchronously)
        app(LinkageService::class)->approveLink($linkId, $this->ops);
        $this->assertTrue($this->hasRequest($programme->id, $guardianB->id), 'the newly-active guardian was issued a consent request');

        // age the enrolment past the 10-min grace so the assertion is in scope, then it is GREEN
        $this->age($programme->id);
        $this->assertTrue($this->sys(fn () => (new ConsentIssuanceCompletenessAssertion)->check()->passed), 'issuance_completeness green — the new guardian has a request');

        // TEETH: delete the reissued request (the pre-fix state) → the assertion REDS. Proves the fix is why it is green.
        $this->sys(fn () => DB::table('consent_requests')->where('programme_id', $programme->id)->where('signer_id', $guardianB->id)->delete());
        $this->assertFalse($this->sys(fn () => (new ConsentIssuanceCompletenessAssertion)->check()->passed), 'without the reissued request (pre-fix), issuance_completeness reds');
    }

    // ── 2 + 3. requires_all: dead-loop broken; D3 reopen (in_pool regresses; re-satisfies on sign) ──

    public function test_requires_all_reissue_breaks_dead_loop_and_reopens_the_gate(): void
    {
        [$programme, $templateId] = $this->publishedProgramme('ALL', requiresAll: true);
        $this->enrol($this->guardianA, $programme);
        $this->sign($this->guardianA, $programme); // only active guardian signs → satisfied → in_pool
        $this->assertSame('in_pool', $this->enrolStatus($programme->id));

        // add a 2nd guardian and activate → reissue + D3 reopen
        [$guardianB, $linkId] = $this->pendingGuardian($this->studentA->id);
        app(LinkageService::class)->approveLink($linkId, $this->ops);

        // dead-loop broken: guardian B has a request (can now sign — otherwise consent could never complete)
        $this->assertTrue($this->hasRequest($programme->id, $guardianB->id), 'requires_all: the new guardian is issued a request → signable, not a dead loop');
        // D3 reopen: consent no longer satisfied (B unsigned) → enrolment regressed in_pool → pending_consent
        $this->assertFalse($this->sys(fn () => app(ConsentSigningService::class)->consentSatisfied($programme->id, $this->studentA->id)), 'requires_all: unsigned new guardian → not satisfied');
        $this->assertSame('pending_consent', $this->enrolStatus($programme->id), 'D3 reopen: in_pool regressed to pending_consent until the new guardian signs');

        // the new guardian signs → satisfied again → gate re-closes → in_pool (Team Formation could then proceed)
        $this->sign($guardianB, $programme);
        $this->sys(fn () => app(EnrolmentService::class)->evaluateConsentGate($programme->id, $this->studentA->id, $this->ops, 're-check'));
        $this->assertTrue($this->sys(fn () => app(ConsentSigningService::class)->consentSatisfied($programme->id, $this->studentA->id)));
        $this->assertSame('in_pool', $this->enrolStatus($programme->id), 'gate re-satisfied after the new guardian signs — 成團 unblocked');
    }

    // ── 4. schoolVouch seam also reissues ───────────────────────────────────────────────────────────

    public function test_schoolvouch_seam_reissues_consent(): void
    {
        [$programme, $templateId] = $this->publishedProgramme('VCH', requiresAll: false);
        $this->enrol($this->guardianA, $programme);

        // put the student on a school roll and vouch a 2nd guardian
        $school = School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S']);
        $this->sys(fn () => DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->studentA->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
        $guardianB = User::factory()->create(['role' => 'guardian', 'name' => 'Vouched B', 'email' => 'vouchedb@ex.test']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($schoolAdmin);
        $this->postJson('/api/school/guardian-links', ['student_id' => $this->studentA->id, 'guardian_email' => 'vouchedb@ex.test'])->assertStatus(201);

        $this->assertTrue($this->hasRequest($programme->id, $guardianB->id), 'schoolVouch activation re-issued consent to the vouched guardian');
    }

    // ── 5. idempotency: a re-dispatch issues exactly one request ─────────────────────────────────────

    public function test_reissue_is_idempotent(): void
    {
        [$programme] = $this->publishedProgramme('IDMP', requiresAll: false);
        $this->enrol($this->guardianA, $programme);
        [$guardianB, $linkId] = $this->pendingGuardian($this->studentA->id);

        app(LinkageService::class)->approveLink($linkId, $this->ops);
        // fire the event again directly — the listener must not double-issue
        \App\Events\GuardianLinkActivated::dispatch($this->studentA->id, $guardianB->id, $linkId, 'onboarding', $this->ops->id);

        $count = DB::table('consent_requests')->where('programme_id', $programme->id)->where('signer_id', $guardianB->id)->count();
        $this->assertSame(1, $count, 'exactly one request per (student, enrolment, guardian) — no duplicate');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    private function activeLink(int $studentId, int $guardianId): void
    {
        $id = (string) Str::uuid7();
        DB::table('guardian_links')->insert(['id' => $id, 'student_id' => $studentId, 'guardian_id' => $guardianId, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        app(\App\Services\Audit\AuditService::class)->record('guardian_link', $id, 'guardian_link.activated', toState: 'active', payloadAfter: ['student_id' => $studentId, 'guardian_id' => $guardianId]);
    }

    /** @return array{0: User, 1: string} */
    private function pendingGuardian(int $studentId): array
    {
        $g = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian B '.Str::random(3)]);
        $id = (string) Str::uuid7();
        $this->sys(fn () => DB::table('guardian_links')->insert(['id' => $id, 'student_id' => $studentId, 'guardian_id' => $g->id, 'status' => 'pending_approval', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]));

        return [$g, $id];
    }

    private function hasRequest(int $programmeId, int $signerId): bool
    {
        return $this->sys(fn () => DB::table('consent_requests')->where('programme_id', $programmeId)->where('signer_id', $signerId)->whereIn('status', ['sent', 'viewed', 'signed'])->exists());
    }

    private function enrolStatus(int $programmeId): string
    {
        return (string) $this->sys(fn () => DB::table('enrolments')->where('programme_id', $programmeId)->where('student_id', $this->studentA->id)->value('status'));
    }

    private function age(int $programmeId): void
    {
        $this->sys(fn () => DB::table('enrolments')->where('programme_id', $programmeId)->where('student_id', $this->studentA->id)->update(['updated_at' => now()->subMinutes(11)]));
    }

    private function enrol(User $guardian, Programme $programme): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $this->postJson('/api/my/enrolments', ['programme_id' => $programme->id, 'student_id' => $this->studentA->id])->assertStatus(201);
    }

    private function sign(User $signer, Programme $programme): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($signer);
        $request = $this->sys(fn () => DB::table('consent_requests')->where('programme_id', $programme->id)->where('signer_id', $signer->id)->whereIn('status', ['sent', 'viewed'])->first());
        $this->assertNotNull($request, 'an open request exists for the signer');
        $this->getJson("/api/consent-requests/{$request->id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$request->id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$request->id}/sign", ['affirmed' => true, 'method' => 'typed', 'typed_name' => 'Signed'])->assertStatus(201);
    }

    /** @return array{0: Programme, 1: string} */
    private function publishedProgramme(string $prefix, bool $requiresAll): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en' => 'English terms', 'zh-TC' => '繁體條款', 'zh-SC' => '简体条款'] as $lang => $text) {
            $vid = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $lang, 'body_html' => "<p>{$text} {{student_name}} {{signature}}</p>"])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$vid}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => $prefix.'-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId, 'requires_all_guardians' => $requiresAll],
                'basics' => ['enrolment_closes_on' => '2027-01-10', 'starts_on' => '2027-02-01'], 'team_rules' => ['formation_deadline_on' => '2027-01-20'], default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();

        return [$programme, $templateId];
    }

    private function sys(callable $fn): mixed
    {
        $s = app(\App\Services\Authz\ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->setSystem();
        }
    }
}
