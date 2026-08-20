<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Consent\ConsentSigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-UX3-3a STEP 1 — the per-member consent-status read endpoint (child-safety-adjacent).
 * The four mandatory tests: privacy tooth (+ key allowlist), five-branch authority,
 * teamed-member-unsatisfied blocker, single-source agreement.
 */
class TeamConsentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $super;

    private User $lobbyAdmin;   // school-admin of the team's lobby school (authorised)

    private User $otherAdmin;   // school-admin of a DIFFERENT school (unaffiliated)

    private User $member;       // the team member (student)

    private User $gSigned;      // guardian who signed

    private User $gPending;     // guardian who has NOT signed (requires_all → unsatisfied)

    private int $programmeId;

    private string $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops = $this->admin(['operations']);
        $this->super = $this->admin(['super_admin']);

        [$s1, $this->lobbyAdmin] = $this->schoolWithAdmin();
        [, $this->otherAdmin] = $this->schoolWithAdmin();

        // requires_all programme
        $this->programmeId = $this->sys(fn () => DB::table('programmes')->insertGetId(['code' => 'T'.Str::random(4), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]));
        $templateId = (string) Str::uuid7();
        $this->sys(fn () => DB::table('wizard_sections')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $this->programmeId, 'section_key' => 'consent', 'status' => 'complete', 'data' => json_encode(['requires_all_guardians' => true, 'template_ref' => $templateId]), 'created_at' => now(), 'updated_at' => now()]));

        // member with TWO active guardians, distinctively named (the privacy-leak-prone path)
        $this->member = User::factory()->create(['role' => 'student', 'name' => 'Team Member']);
        $this->gSigned = User::factory()->create(['role' => 'guardian', 'name' => 'Zeta Guardian']);
        $this->gPending = User::factory()->create(['role' => 'guardian', 'name' => 'Omega Guardian']);
        $enrolmentId = (string) Str::uuid7();
        $this->sys(function () use ($s1, $templateId, $enrolmentId) {
            DB::table('consent_templates')->insert(['id' => $templateId, 'name_en' => 'T', 'name_tc' => 'T', 'name_sc' => 'T', 'created_by' => $this->ops->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([$this->gSigned, $this->gPending] as $g) {
                DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->member->id, 'guardian_id' => $g->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->member->id, 'school_id' => $s1->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('enrolments')->insert(['id' => $enrolmentId, 'programme_id' => $this->programmeId, 'student_id' => $this->member->id, 'acting_guardian_id' => $this->gSigned->id, 'status' => 'teamed', 'created_at' => now(), 'updated_at' => now()]);
            // consent: Zeta signed, Omega pending → requires_all NOT satisfied
            DB::table('consent_requests')->insert(['id' => (string) Str::uuid7(), 'template_id' => $templateId, 'programme_id' => $this->programmeId, 'student_id' => $this->member->id, 'signer_id' => $this->gSigned->id, 'status' => 'signed', 'merge_data' => json_encode([]), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('consent_requests')->insert(['id' => (string) Str::uuid7(), 'template_id' => $templateId, 'programme_id' => $this->programmeId, 'student_id' => $this->member->id, 'signer_id' => $this->gPending->id, 'status' => 'sent', 'merge_data' => json_encode([]), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
            // a submitted team in $s1's lobby, member = the student
            $lobby = (string) Str::uuid7();
            DB::table('team_categories')->insert(['id' => $lobby, 'programme_id' => $this->programmeId, 'name_en' => 'Lobby', 'name_tc' => 'Lobby', 'name_sc' => 'Lobby', 'assignment_rule' => 'open', 'school_id' => $s1->id, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->teamId = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $this->teamId, 'programme_id' => $this->programmeId, 'category_id' => $lobby, 'name' => 'Alpha', 'status' => 'submitted', 'created_by' => $this->member->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'team_id' => $this->teamId, 'programme_id' => $this->programmeId, 'enrolment_id' => $enrolmentId, 'category_id' => $lobby, 'student_id' => $this->member->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    // ── (a) Privacy tooth + key allowlist ────────────────────────────────────────────────────────

    public function test_response_never_leaks_a_guardian_identity_and_matches_the_key_allowlist(): void
    {
        $this->act($this->ops);
        $res = $this->getJson("/api/teams/{$this->teamId}/consent-status")->assertOk();
        $raw = $res->getContent();

        // no guardian NAME
        $this->assertStringNotContainsString('Zeta Guardian', $raw);
        $this->assertStringNotContainsString('Omega Guardian', $raw);
        // no guardian ID
        $this->assertStringNotContainsString('"'.$this->gSigned->id.'"', $raw);
        $this->assertStringNotContainsString(':'.$this->gSigned->id.',', $raw);
        $this->assertStringNotContainsString(':'.$this->gPending->id.',', $raw);
        // no guardian/signer KEY anywhere
        $this->assertStringNotContainsStringIgnoringCase('guardian_id', $raw);
        $this->assertStringNotContainsStringIgnoringCase('signer', $raw);
        $this->assertStringNotContainsStringIgnoringCase('signed_at', $raw);

        // exact key allowlist
        $body = $res->json();
        $this->assertEqualsCanonicalizing(['team_id', 'mode', 'all_satisfied', 'blocking_count', 'members'], array_keys($body));
        $this->assertEqualsCanonicalizing(
            ['student_id', 'student_name', 'satisfied', 'signed_count', 'guardian_count', 'blocker'],
            array_keys($body['members'][0]),
        );
    }

    // ── (b) Five-branch authority ────────────────────────────────────────────────────────────────

    public function test_authority_is_five_branch(): void
    {
        $this->act($this->ops);
        $this->getJson("/api/teams/{$this->teamId}/consent-status")->assertOk();          // academy ops
        $this->act($this->super);
        $this->getJson("/api/teams/{$this->teamId}/consent-status")->assertOk();          // super
        $this->act($this->lobbyAdmin);
        $this->getJson("/api/teams/{$this->teamId}/consent-status")->assertOk();          // lobby school-admin
        $this->act($this->otherAdmin);
        $this->getJson("/api/teams/{$this->teamId}/consent-status")->assertNotFound();    // unaffiliated → RLS absence 404
        $this->act($this->gSigned);
        $this->getJson("/api/teams/{$this->teamId}/consent-status")->assertForbidden();   // guardian → 403 (not their surface)
        $this->act($this->member);
        $this->getJson("/api/teams/{$this->teamId}/consent-status")->assertForbidden();   // student member → 403
    }

    // ── (c) Teamed-member-unsatisfied blocker (the dead-loop, made visible) ───────────────────────

    public function test_teamed_member_with_unsigned_requires_all_guardian_is_a_blocker(): void
    {
        $this->act($this->ops);
        $body = $this->getJson("/api/teams/{$this->teamId}/consent-status")->assertOk()->json();

        $this->assertSame('requires_all', $body['mode']);
        $this->assertFalse($body['all_satisfied']);
        $this->assertSame(1, $body['blocking_count']);
        $m = collect($body['members'])->firstWhere('student_id', $this->member->id);
        $this->assertNotNull($m);
        $this->assertSame('Team Member', $m['student_name']);
        $this->assertFalse($m['satisfied']);
        $this->assertSame(1, $m['signed_count']);   // Zeta signed
        $this->assertSame(2, $m['guardian_count']); // of 2 active
        $this->assertSame('awaiting_signature', $m['blocker']); // Omega has a sent-but-unsigned request
    }

    // ── (d) Single-source agreement — endpoint satisfied == consentSatisfied ──────────────────────

    public function test_satisfied_delegates_to_consent_satisfied(): void
    {
        $this->act($this->ops);
        $body = $this->getJson("/api/teams/{$this->teamId}/consent-status")->assertOk()->json();
        $m = collect($body['members'])->firstWhere('student_id', $this->member->id);

        $authoritative = $this->sys(fn () => app(ConsentSigningService::class)->consentSatisfied($this->programmeId, $this->member->id));
        $this->assertSame($authoritative, $m['satisfied'], 'the endpoint never re-derives the gate');
        $this->assertFalse($authoritative); // sanity: requires_all with an unsigned guardian
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    private function admin(array $caps): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        foreach ($caps as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => $c, 'granted_by' => $u->id, 'granted_at' => now()]);
        }

        return $u;
    }

    /** @return array{0: School, 1: User} */
    private function schoolWithAdmin(): array
    {
        $school = School::query()->create(['name_en' => 'S'.Str::random(3), 'name_tc' => 'S', 'name_sc' => 'S']);
        $admin = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $admin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        return [$school, $admin];
    }

    private function act(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
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
