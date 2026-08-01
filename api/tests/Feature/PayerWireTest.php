<?php

namespace Tests\Feature;

use App\Events\PaymentRequested;
use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\PayerResolver;
use App\Services\Money\UnresolvablePayerException;
use App\Services\Reconciliation\Assertions\ObligationPayerMatchesProgrammeAssertion;
use App\Services\Teams\TeamResolutionService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

/**
 * S04F STEP 1 — the E6-payer wire. Both 成團 obligation sites
 * (TeamConfirmationService, TeamResolutionService) resolve the payer from the
 * programme's E6 payer_party through ONE helper; a school-paid programme mints a
 * school obligation (never a silent guardian), and a roll-less school student is
 * a LOUD failure (D-18).
 */
class PayerWireTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $c, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        $this->school = $this->sys(fn () => School::create(['name_en' => 'Sch'.Str::random(3), 'name_tc' => '甲', 'name_sc' => '甲']));
    }

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            // restore the harness's ambient SYSTEM context (not empty) — the
            // scaffolding does direct writes between sys() calls (TestCase setUp
            // + the call() override keep system ambient).
            $s->setSystem();
        }
    }

    /** @return array{0: Programme, 1: string, 2: string} programme, templateId, lobbyId */
    private function publishedProgramme(string $payerParty): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = $this->sys(fn () => Programme::create(['code' => 'PW-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']));
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'],
                'eligibility' => ['capacity' => 5],
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'team_rules' => ['formation_deadline_on' => '2026-06-20', 'min_team_size' => 1],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$k}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/fee-items", ['name_en' => 'Fee', 'name_tc' => '費', 'name_sc' => '费', 'amount_minor' => 250000, 'currency' => 'HKD'])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $lobby = $this->postJson("/api/admin/programmes/{$programme->id}/team-categories", ['name_en' => 'Open', 'name_tc' => '開', 'name_sc' => '开', 'assignment_rule' => 'open', 'is_default' => true])->json('id');
        $this->app['auth']->forgetGuards();
        // E6 payer set directly (single source of truth is the programme column).
        $this->sys(fn () => DB::table('programmes')->where('id', $programme->id)->update(['payer_party' => $payerParty]));

        return [$programme, $templateId, $lobby];
    }

    private function pooledStudent(Programme $programme, bool $onRoll): User
    {
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        if ($onRoll) {
            $this->sys(fn () => DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $this->school->id, 'status' => 'active', 'origin' => 'registration', 'created_at' => now(), 'updated_at' => now()]));
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $this->postJson('/api/my/enrolments', ['programme_id' => $programme->id, 'student_id' => $student->id]);
        $req = DB::table('consent_requests')->where('student_id', $student->id)->where('signer_id', $guardian->id)->whereIn('status', ['sent', 'viewed'])->first();
        $this->getJson("/api/consent-requests/{$req->id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/sign", ['affirmed' => true, 'method' => 'typed', 'typed_name' => 'G'])->assertStatus(201);
        app(EnrolmentService::class)->evaluateConsentGate($programme->id, $student->id, $guardian);
        $this->app['auth']->forgetGuards();

        return $student;
    }

    private function submittedTeam(Programme $programme, string $lobby, bool $onRoll): string
    {
        $creator = $this->pooledStudent($programme, $onRoll);
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $programme->id, 'category_id' => $lobby, 'name' => 'Team'.Str::random(4)])->json('id');
        $m = $this->pooledStudent($programme, $onRoll);
        Sanctum::actingAs($m);
        $this->postJson("/api/teams/{$teamId}/join")->assertOk();
        Sanctum::actingAs($creator);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();

        return $teamId;
    }

    // ── the shared resolver: total mapping + loud throws ──────────────────────

    public function test_resolver_maps_each_e6_and_throws_on_unresolvable_school(): void
    {
        $r = app(PayerResolver::class);
        $onRoll = $this->sys(function () {
            $s = User::factory()->create(['role' => 'student']);
            DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $s->id, 'school_id' => $this->school->id, 'status' => 'active', 'origin' => 'registration', 'created_at' => now(), 'updated_at' => now()]);

            return $s->id;
        });
        $noRoll = User::factory()->create(['role' => 'student'])->id;

        [$parent] = $this->sys(fn () => [DB::table('programmes')->insertGetId(['code' => 'R1'.Str::random(3), 'name_en' => 'a', 'name_tc' => 'a', 'name_sc' => 'a', 'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()])]);
        [$student] = $this->sys(fn () => [DB::table('programmes')->insertGetId(['code' => 'R2'.Str::random(3), 'name_en' => 'a', 'name_tc' => 'a', 'name_sc' => 'a', 'jurisdiction' => 'HK', 'payer_party' => 'student', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()])]);
        [$schoolProg] = $this->sys(fn () => [DB::table('programmes')->insertGetId(['code' => 'R3'.Str::random(3), 'name_en' => 'a', 'name_tc' => 'a', 'name_sc' => 'a', 'jurisdiction' => 'HK', 'payer_party' => 'school', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()])]);

        $this->assertSame(['payer_party' => 'guardian', 'payer_school_id' => null], $this->sys(fn () => $r->resolve($parent, $onRoll)));
        $this->assertSame(['payer_party' => 'student', 'payer_school_id' => null], $this->sys(fn () => $r->resolve($student, $onRoll)));
        $this->assertSame(['payer_party' => 'school', 'payer_school_id' => $this->school->id], $this->sys(fn () => $r->resolve($schoolProg, $onRoll)));

        // roll-less school → LOUD failure, never a guardian fallback
        $this->expectException(UnresolvablePayerException::class);
        $this->sys(fn () => $r->resolve($schoolProg, $noRoll));
    }

    // ── SITE 1: 成團 confirm writes school obligations ─────────────────────────

    public function test_confirm_school_programme_writes_school_obligations(): void
    {
        Event::fake([PaymentRequested::class]);
        [$programme, , $lobby] = $this->publishedProgramme('school');
        $teamId = $this->submittedTeam($programme, $lobby, onRoll: true);
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();

        $obligations = $this->sys(fn () => DB::table('payment_obligations')->where('programme_id', $programme->id)->get());
        $this->assertCount(2, $obligations);
        $this->assertTrue($obligations->every(fn ($o) => $o->payer_party === 'school' && (int) $o->payer_school_id === $this->school->id), 'both obligations school-paid with the roll');
        // and the orders the consumer issued inherit school payer
        $this->assertTrue($this->sys(fn () => DB::table('orders')->where('programme_id', $programme->id)->get())->every(fn ($o) => $o->payer_party === 'school'));
        $this->assertTrue($this->sys(fn () => (new ObligationPayerMatchesProgrammeAssertion)->check()->passed));
    }

    // ── SITE 1 negative: parent programme unchanged ───────────────────────────

    public function test_confirm_parent_programme_still_writes_guardian(): void
    {
        Event::fake([PaymentRequested::class]);
        [$programme, , $lobby] = $this->publishedProgramme('parent');
        $teamId = $this->submittedTeam($programme, $lobby, onRoll: false);
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();

        $obligations = $this->sys(fn () => DB::table('payment_obligations')->where('programme_id', $programme->id)->get());
        $this->assertCount(2, $obligations);
        $this->assertTrue($obligations->every(fn ($o) => $o->payer_party === 'guardian' && $o->payer_school_id === null));
    }

    // ── SITE 1: roll-less school student → LOUD failure, no obligation ─────────

    public function test_confirm_rollless_school_student_is_a_loud_failure(): void
    {
        [$programme, , $lobby] = $this->publishedProgramme('school');
        $teamId = $this->submittedTeam($programme, $lobby, onRoll: false); // students NOT on any roll
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertStatus(500); // UnresolvablePayerException aborts 成團

        // no silent guardian obligation, team not confirmed (rolled back)
        $this->assertSame(0, $this->sys(fn () => DB::table('payment_obligations')->where('programme_id', $programme->id)->count()));
        $this->assertSame('submitted', $this->sys(fn () => DB::table('teams')->where('id', $teamId)->value('status')));
    }

    // ── SITE 2: below-min ASSIGN writes a school obligation ───────────────────

    public function test_assign_school_programme_writes_school_obligation(): void
    {
        $ids = $this->sys(function () {
            $prog = DB::table('programmes')->insertGetId(['code' => 'AS'.Str::random(3), 'name_en' => 'a', 'name_tc' => 'a', 'name_sc' => 'a', 'jurisdiction' => 'HK', 'payer_party' => 'school', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('programme_capacity')->insert(['programme_id' => $prog, 'capacity' => 5, 'claimed' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $lobby = (string) Str::uuid7();
            DB::table('team_categories')->insert(['id' => $lobby, 'programme_id' => $prog, 'name_en' => 'Open', 'name_tc' => '開', 'name_sc' => '开', 'assignment_rule' => 'open', 'school_id' => null, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            $teamId = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $teamId, 'programme_id' => $prog, 'category_id' => $lobby, 'name' => 'T', 'status' => 'confirmed', 'created_by' => $this->ops->id, 'created_at' => now(), 'updated_at' => now()]);
            $guardian = User::factory()->create(['role' => 'guardian']);
            $student = User::factory()->create(['role' => 'student']);
            DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $this->school->id, 'status' => 'active', 'origin' => 'registration', 'created_at' => now(), 'updated_at' => now()]);
            $eid = (string) Str::uuid7();
            DB::table('enrolments')->insert(['id' => $eid, 'programme_id' => $prog, 'student_id' => $student->id, 'acting_guardian_id' => $guardian->id, 'status' => 'in_pool', 'created_at' => now(), 'updated_at' => now()]);

            return ['prog' => $prog, 'team' => $teamId, 'enrolment' => $eid];
        });

        app(TeamResolutionService::class)->assign($ids['team'], $ids['enrolment'], $this->ops);

        $ob = $this->sys(fn () => DB::table('payment_obligations')->where('enrolment_id', $ids['enrolment'])->first());
        $this->assertSame('school', $ob->payer_party, 'the OTHER call site is wired too');
        $this->assertSame($this->school->id, (int) $ob->payer_school_id);
    }

    // ── assertion teeth ───────────────────────────────────────────────────────

    public function test_payer_matches_programme_assertion_reds_on_a_mismap_then_greens(): void
    {
        $this->assertTrue($this->sys(fn () => (new ObligationPayerMatchesProgrammeAssertion)->check()->passed), 'green on a clean DB');
        $obId = $this->sys(function () {
            $prog = DB::table('programmes')->insertGetId(['code' => 'MM'.Str::random(3), 'name_en' => 'a', 'name_tc' => 'a', 'name_sc' => 'a', 'jurisdiction' => 'HK', 'payer_party' => 'school', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
            $student = User::factory()->create(['role' => 'student']);
            $eid = (string) Str::uuid7();
            DB::table('enrolments')->insert(['id' => $eid, 'programme_id' => $prog, 'student_id' => $student->id, 'acting_guardian_id' => $student->id, 'status' => 'in_pool', 'created_at' => now(), 'updated_at' => now()]);
            $oid = (string) Str::uuid7();
            // a school programme carrying a GUARDIAN obligation — the exact bug the wire prevents
            DB::table('payment_obligations')->insert(['id' => $oid, 'enrolment_id' => $eid, 'programme_id' => $prog, 'student_id' => $student->id, 'payer_party' => 'guardian', 'payer_school_id' => null, 'created_at' => now()]);

            return $oid;
        });
        $this->assertFalse($this->sys(fn () => (new ObligationPayerMatchesProgrammeAssertion)->check()->passed), 'reds on a school programme with a guardian obligation');

        $this->sys(fn () => DB::table('payment_obligations')->where('id', $obId)->update(['payer_party' => 'school', 'payer_school_id' => $this->school->id]));
        $this->assertTrue($this->sys(fn () => (new ObligationPayerMatchesProgrammeAssertion)->check()->passed));
    }
}
