<?php

namespace Tests\Feature;

use App\Events\PaymentRequested;
use App\Models\Programme;
use App\Models\User;
use App\Services\Consent\ConsentTemplateService;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class TeamConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private Programme $programme;

    private string $templateId;

    private string $lobby;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $c, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        [$this->programme, $this->templateId, $this->lobby] = $this->publishedProgramme(capacity: 3);
    }

    /** @return array{0: Programme, 1: string, 2: string} programme, templateId, lobbyId */
    private function publishedProgramme(?int $capacity, int $minTeam = 1): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => 'CT-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'],
                'eligibility' => $capacity === null ? ['x' => 1] : ['capacity' => $capacity],
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'team_rules' => ['formation_deadline_on' => '2026-06-20', 'min_team_size' => $minTeam],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$k}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/fee-items", ['name_en' => 'Fee', 'name_tc' => '費', 'name_sc' => '费', 'amount_minor' => 250000, 'currency' => 'HKD'])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $lobby = $this->postJson("/api/admin/programmes/{$programme->id}/team-categories", ['name_en' => 'Open', 'name_tc' => '開', 'name_sc' => '开', 'assignment_rule' => 'open', 'is_default' => true])->json('id');
        $this->app['auth']->forgetGuards();

        return [$programme, $templateId, $lobby];
    }

    private function pooledStudent(Programme $programme, string $templateId): User
    {
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
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

    /** Build a SUBMITTED team of $size consented members in the lobby. */
    private function submittedTeam(Programme $programme, string $templateId, string $lobby, int $size): string
    {
        $creator = $this->pooledStudent($programme, $templateId);
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $programme->id, 'category_id' => $lobby, 'name' => 'Team'.Str::random(4)])->json('id');
        $this->app['auth']->forgetGuards();
        for ($i = 1; $i < $size; $i++) {
            $m = $this->pooledStudent($programme, $templateId);
            Sanctum::actingAs($m);
            $this->postJson("/api/teams/{$teamId}/join")->assertOk();
            $this->app['auth']->forgetGuards();
        }
        Sanctum::actingAs($creator);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();

        return $teamId;
    }

    public function test_seizes_seats_confirms_members_and_writes_family_obligations(): void
    {
        Event::fake([PaymentRequested::class]);
        $teamId = $this->submittedTeam($this->programme, $this->templateId, $this->lobby, 2);
        Sanctum::actingAs($this->ops);
        $result = $this->postJson("/api/teams/{$teamId}/confirm")->assertOk()->json();

        $this->assertSame(2, $result['seats_claimed']);
        $this->assertSame(2, (int) DB::table('programme_capacity')->where('programme_id', $this->programme->id)->value('claimed'));
        $this->assertSame('confirmed', DB::table('teams')->where('id', $teamId)->value('status'));
        // members teamed → confirmed
        $memberEnrolments = DB::table('team_members')->where('team_id', $teamId)->pluck('enrolment_id');
        foreach ($memberEnrolments as $eid) {
            $this->assertSame('confirmed', DB::table('enrolments')->where('id', $eid)->value('status'));
        }
        // one obligation per member, family-paid (payer_party = guardian), written IN the tx
        $obligations = DB::table('payment_obligations')->whereIn('enrolment_id', $memberEnrolments)->get();
        $this->assertCount(2, $obligations);
        $this->assertTrue($obligations->every(fn ($o) => $o->payer_party === 'guardian'));
        // consumer (dispatched after commit, sync) issued the orders + fired PaymentRequested
        $this->assertSame(2, DB::table('orders')->whereIn('enrolment_id', $memberEnrolments)->count());
        Event::assertDispatched(PaymentRequested::class);
    }

    public function test_partial_claim_is_impossible_over_capacity_refuses_whole_team(): void
    {
        // capacity 3; team A (2) confirms → claimed 2; team B (2) needs 2 → 2+2>3 → refused whole
        $teamA = $this->submittedTeam($this->programme, $this->templateId, $this->lobby, 2);
        $teamB = $this->submittedTeam($this->programme, $this->templateId, $this->lobby, 2);
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamA}/confirm")->assertOk();
        $this->postJson("/api/teams/{$teamB}/confirm")->assertStatus(409);
        // B fully rolled back: still submitted, members still teamed, NO obligations, claimed unchanged
        $this->assertSame('submitted', DB::table('teams')->where('id', $teamB)->value('status'));
        $bEnrolments = DB::table('team_members')->where('team_id', $teamB)->pluck('enrolment_id');
        foreach ($bEnrolments as $eid) {
            $this->assertSame('teamed', DB::table('enrolments')->where('id', $eid)->value('status'));
        }
        $this->assertSame(0, DB::table('payment_obligations')->whereIn('enrolment_id', $bEnrolments)->count());
        $this->assertSame(2, (int) DB::table('programme_capacity')->where('programme_id', $this->programme->id)->value('claimed'));
    }

    public function test_capacity_row_serializes_claimants_across_connections(): void
    {
        // The twin-team lock proof, like the receipt-sequence race: two teams reach
        // for the SAME capacity row. A holds it FOR UPDATE, B BLOCKS, and after A
        // commits its claim B sees the incremented `claimed` — no lost update, so the
        // Team Formation seat check-and-increment can never be interleaved. The fixture is a
        // COMMITTED synthetic row (outside RefreshDatabase's tx) so both raw
        // connections can see and contend on it, then it is cleaned up.
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '54329'), env('DB_DATABASE', 'kap_test'));
        $a = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $b = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach ([$a, $b] as $c) {
            $c->exec("SELECT set_config('app.context','system',false)"); // session-level; both writes need it (pc_insert is system-only)
        }
        // committed fixture: a synthetic published programme + its capacity row (claimed 0, capacity 5)
        $a->exec("DELETE FROM programme_capacity WHERE programme_id IN (SELECT id FROM programmes WHERE code = 'CAPRACE')");
        $a->exec("DELETE FROM programmes WHERE code = 'CAPRACE'");
        $pid = (int) $a->query("INSERT INTO programmes (code, name_en, name_tc, name_sc, status) VALUES ('CAPRACE', 'R', 'R', 'R', 'published') RETURNING id")->fetchColumn();
        $a->exec("INSERT INTO programme_capacity (programme_id, capacity, claimed, created_at, updated_at) VALUES ({$pid}, 5, 0, now(), now())");

        try {
            $a->exec('BEGIN');
            $a->query("SELECT claimed FROM programme_capacity WHERE programme_id = {$pid} FOR UPDATE");
            // B arrives while A holds the row: blocks, then times out
            $b->exec("SET lock_timeout = '400ms'");
            $b->exec('BEGIN');
            $blocked = false;
            try {
                $b->query("SELECT claimed FROM programme_capacity WHERE programme_id = {$pid} FOR UPDATE");
            } catch (\PDOException $e) {
                $blocked = str_contains($e->getMessage(), 'lock timeout');
            }
            $b->exec('ROLLBACK');
            // A claims 3 seats and commits
            $a->exec("UPDATE programme_capacity SET claimed = claimed + 3 WHERE programme_id = {$pid}");
            $a->exec('COMMIT');
            // B now sees A's committed claim
            $b->exec('BEGIN');
            $seen = (int) $b->query("SELECT claimed FROM programme_capacity WHERE programme_id = {$pid} FOR UPDATE")->fetchColumn();
            $b->exec('COMMIT');
        } finally {
            $a->exec("DELETE FROM programme_capacity WHERE programme_id = {$pid}");
            $a->exec("DELETE FROM programmes WHERE id = {$pid}");
        }

        $this->assertTrue($blocked, 'B must block on the capacity row while A holds FOR UPDATE');
        $this->assertSame(3, $seen, 'after A commits, B sees the updated claimed — no lost update');
    }

    public function test_supersede_before_成團_refuses_stale_confirm(): void
    {
        $teamId = $this->submittedTeam($this->programme, $this->templateId, $this->lobby, 1);
        // a material consent change supersedes the member's signed request BEFORE Team Formation
        Sanctum::actingAs($this->ops);
        $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", ['language' => 'en', 'body_html' => '<p>v2 material {{signature}}</p>'])->json('version_id');
        $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$vid}/publish", ['material' => true])->assertOk();
        // Team Formation re-checks consent under the FOR SHARE lock → member no longer satisfied → refused
        $this->postJson("/api/teams/{$teamId}/confirm")->assertStatus(422);
        $this->assertSame('submitted', DB::table('teams')->where('id', $teamId)->value('status'));
        $this->assertSame(0, (int) DB::table('programme_capacity')->where('programme_id', $this->programme->id)->value('claimed'));
    }

    public function test_成團_before_supersede_confirms_against_valid_consent(): void
    {
        $teamId = $this->submittedTeam($this->programme, $this->templateId, $this->lobby, 1);
        Sanctum::actingAs($this->ops);
        // Team Formation first — consent valid at this instant → confirms
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $this->assertSame('confirmed', DB::table('teams')->where('id', $teamId)->value('status'));
        // a later material change supersedes; the confirmed team stands (re-consent is a fresh request)
        $vid = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", ['language' => 'en', 'body_html' => '<p>v2 later {{signature}}</p>'])->json('version_id');
        $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$vid}/publish", ['material' => true])->assertOk();
        $this->assertSame('confirmed', DB::table('teams')->where('id', $teamId)->value('status'), 'a post-成團 supersede does not un-confirm — no torn state');
    }

    public function test_unconsented_member_refuses_成團(): void
    {
        // build a team then break a member's consent by voiding their signed request
        $teamId = $this->submittedTeam($this->programme, $this->templateId, $this->lobby, 1);
        $enrolmentId = DB::table('team_members')->where('team_id', $teamId)->value('enrolment_id');
        $studentId = DB::table('enrolments')->where('id', $enrolmentId)->value('student_id');
        // remove the signed status (simulate an unconsented member at Team Formation)
        DB::table('consent_requests')->where('student_id', $studentId)->where('status', 'signed')->update(['status' => 'declined']);
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertStatus(422);
    }

    public function test_confirm_refused_without_capacity_configured(): void
    {
        // capacity unset at publish is a WARNING (publishes), but Team Formation has no counter to claim
        [$p, $tid, $lobby] = $this->publishedProgramme(capacity: null);
        $this->assertSame(0, DB::table('programme_capacity')->where('programme_id', $p->id)->count());
        $teamId = $this->submittedTeam($p, $tid, $lobby, 1);
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertStatus(422);
    }

    public function test_lower_capacity_below_claimed_is_refused(): void
    {
        $teamId = $this->submittedTeam($this->programme, $this->templateId, $this->lobby, 2);
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk(); // claimed = 2
        // lowering capacity to 1 (< claimed 2) is refused; raising to 5 is fine
        $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/eligibility", ['status' => 'complete', 'data' => ['capacity' => 1]])
            ->assertStatus(422)->assertJsonPath('errors.capacity.0', fn ($m) => str_contains($m, 'already claimed'));
        $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/eligibility", ['status' => 'complete', 'data' => ['capacity' => 5]])->assertOk();
        $this->assertSame(5, (int) DB::table('programme_capacity')->where('programme_id', $this->programme->id)->value('capacity'));
    }

    public function test_non_approver_cannot_confirm(): void
    {
        $teamId = $this->submittedTeam($this->programme, $this->templateId, $this->lobby, 1);
        // an audit_read admin CAN see the team (opsAudit read set) but is read-only:
        // assertApprover (OD-39) admits only academy operations/super or the lobby's
        // school admin — so this is a true authority refusal (403), not a 404 blind spot.
        $auditor = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $auditor->id, 'capability' => 'audit_read', 'granted_by' => $auditor->id, 'granted_at' => now()]);
        Sanctum::actingAs($auditor);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertStatus(403);
        $this->assertSame('submitted', DB::table('teams')->where('id', $teamId)->value('status'));
    }
}
