<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\AuthorityGrantService;
use App\Services\Authz\ScopeContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A-2 — the delegation grant tables + AuthorityGrantService. Proves: a delegable capability grants (and is
 * audited); a never-capability is REFUSED by the A-1 catalogue and never persists; an edge operator cannot
 * write the tables directly (RLS system-only); and cross-school isolation on the new tables (RIDER-1).
 */
class AuthorityGrantTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private User $platform; // the acting platform admin (granted_by / audit actor)

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolA = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        $this->schoolB = School::query()->create(['name_en' => 'School B', 'name_tc' => '乙校', 'name_sc' => '乙校']);
        $this->platform = User::factory()->create(['role' => 'academy_admin']);
    }

    private function schoolAdmin(int $schoolId): User
    {
        $admin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert([
            'id' => (string) Str::uuid7(), 'school_admin_id' => $admin->id,
            'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $admin;
    }

    private function service(): AuthorityGrantService
    {
        return app(AuthorityGrantService::class);
    }

    private function makeProgramme(): int
    {
        return DB::table('programmes')->insertGetId([
            'code' => 'A2-'.Str::upper(Str::random(6)), 'name_en' => 'P', 'name_tc' => '課', 'name_sc' => '课',
            'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'draft', 'is_template' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_grant_a_delegable_capability_succeeds_and_is_audited(): void
    {
        $this->service()->grant($this->platform, $this->schoolA->id, 'teams.approve');

        $this->assertDatabaseHas('school_authority_grants', [
            'school_id' => $this->schoolA->id, 'capability' => 'teams.approve',
            'granted_by' => $this->platform->id, 'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'school', 'entity_id' => (string) $this->schoolA->id, 'action' => 'authority_grant.granted',
        ]);

        // idempotent — a second grant creates no second active row
        $this->service()->grant($this->platform, $this->schoolA->id, 'teams.approve');
        $this->assertSame(1, DB::table('school_authority_grants')
            ->where('school_id', $this->schoolA->id)->where('capability', 'teams.approve')->whereNull('revoked_at')->count());
    }

    public function test_grant_consent_sign_is_rejected_by_the_a1_catalogue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->grant($this->platform, $this->schoolA->id, 'consent.sign');
    }

    public function test_grant_finance_confirm_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->grant($this->platform, $this->schoolA->id, 'finance.confirm');
    }

    public function test_no_never_capability_ever_persists(): void
    {
        $nevers = ['consent.sign', 'finance.record', 'finance.confirm', 'capabilities.grant',
            'configuration.manage', 'operations.manage', 'audit.read', 'member_directory.view'];
        foreach ($nevers as $never) {
            try {
                $this->service()->grant($this->platform, $this->schoolA->id, $never);
            } catch (InvalidArgumentException) {
                // expected — the A-1 catalogue refuses it before any write
            }
        }
        $this->assertSame(0, DB::table('school_authority_grants')->count(), 'a never-capability was persisted');
    }

    public function test_edge_operator_cannot_insert_directly_rls_denies(): void
    {
        $admin = $this->schoolAdmin($this->schoolA->id);
        app(ScopeContext::class)->set($admin); // a school_admin request context — NOT system

        // Savepoint isolates the RLS-violation abort from the outer RefreshDatabase transaction.
        $threw = false;
        DB::beginTransaction();
        try {
            DB::table('school_authority_grants')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $this->schoolA->id, 'capability' => 'teams.approve',
                'granted_by' => $admin->id, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $threw = true;
            $this->assertStringContainsString('row-level security', strtolower($e->getMessage()));
        }
        DB::rollBack();
        app(ScopeContext::class)->setSystem();

        $this->assertTrue($threw, 'RLS did not deny the edge-operator direct insert (insert must be system-only)');
        $this->assertSame(0, DB::table('school_authority_grants')->count());
    }

    /** RIDER-1 — a school reads its OWN grants but never another school's, on the new tables. */
    public function test_a_school_reads_its_own_grants_but_not_anothers(): void
    {
        $this->service()->grant($this->platform, $this->schoolA->id, 'teams.approve');
        $this->service()->grant($this->platform, $this->schoolB->id, 'enrolment.create');

        app(ScopeContext::class)->set($this->schoolAdmin($this->schoolA->id));
        $seenA = DB::table('school_authority_grants')->pluck('school_id')->all();
        app(ScopeContext::class)->setSystem();
        $this->assertContains($this->schoolA->id, $seenA);
        $this->assertNotContains($this->schoolB->id, $seenA, 'school A read school B grants — cross-school leak');

        app(ScopeContext::class)->set($this->schoolAdmin($this->schoolB->id));
        $seenB = DB::table('school_authority_grants')->pluck('school_id')->all();
        app(ScopeContext::class)->setSystem();
        $this->assertContains($this->schoolB->id, $seenB);
        $this->assertNotContains($this->schoolA->id, $seenB, 'school B read school A grants — cross-school leak');
    }

    public function test_revoke_deactivates_and_allows_regrant(): void
    {
        $this->service()->grant($this->platform, $this->schoolA->id, 'teams.approve');
        $this->service()->revoke($this->platform, $this->schoolA->id, 'teams.approve');

        $this->assertSame(0, DB::table('school_authority_grants')
            ->where('school_id', $this->schoolA->id)->whereNull('revoked_at')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'authority_grant.revoked', 'entity_id' => (string) $this->schoolA->id]);

        // the revoked row (revoked_at NOT NULL) is outside the partial unique — a fresh active grant is allowed
        $this->service()->grant($this->platform, $this->schoolA->id, 'teams.approve');
        $this->assertSame(1, DB::table('school_authority_grants')
            ->where('school_id', $this->schoolA->id)->whereNull('revoked_at')->count());
    }

    public function test_set_override_upserts_current_state(): void
    {
        $programme = $this->makeProgramme();

        $this->service()->setOverride($this->platform, $programme, $this->schoolA->id, 'teams.approve', 'grant');
        $this->assertDatabaseHas('programme_authority_overrides', [
            'programme_id' => $programme, 'school_id' => $this->schoolA->id, 'capability' => 'teams.approve', 'mode' => 'grant',
        ]);

        // flip to withhold — an UPSERT, still exactly one row for the target
        $this->service()->setOverride($this->platform, $programme, $this->schoolA->id, 'teams.approve', 'withhold');
        $this->assertSame(1, DB::table('programme_authority_overrides')
            ->where('programme_id', $programme)->where('capability', 'teams.approve')->count());
        $this->assertDatabaseHas('programme_authority_overrides', [
            'programme_id' => $programme, 'capability' => 'teams.approve', 'mode' => 'withhold',
        ]);
    }

    public function test_override_rejects_a_never_capability(): void
    {
        $programme = $this->makeProgramme();
        $this->expectException(InvalidArgumentException::class);
        $this->service()->setOverride($this->platform, $programme, null, 'audit.read', 'grant');
    }
}
