<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgrammeConfigTest extends TestCase
{
    use RefreshDatabase;

    private Programme $programme;

    private School $schoolA;

    private School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->programme = Programme::query()->create([
            'code' => 'CFG-2026', 'name_en' => 'Config Prog', 'name_tc' => '設定', 'name_sc' => '设置',
            'jurisdiction' => 'HK',
        ]);
        $this->schoolA = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲', 'name_sc' => '甲']);
        $this->schoolB = School::query()->create(['name_en' => 'School B', 'name_tc' => '乙', 'name_sc' => '乙']);
    }

    private function capabilityAdmin(string $capability): User
    {
        $admin = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $admin->id,
            'capability' => $capability, 'granted_by' => $admin->id, 'granted_at' => now(),
        ]);

        return $admin;
    }

    private function seedCategories(): void
    {
        foreach ([
            ['name' => 'St. Paul Lobby', 'school' => $this->schoolA->id, 'default' => true],
            ['name' => 'School B Lobby', 'school' => $this->schoolB->id, 'default' => false],
            ['name' => 'Open Lobby', 'school' => null, 'default' => false],
        ] as $c) {
            DB::table('team_categories')->insert([
                'id' => (string) Str::uuid7(), 'programme_id' => $this->programme->id,
                'name_en' => $c['name'], 'name_tc' => $c['name'], 'name_sc' => $c['name'],
                'school_id' => $c['school'], 'assignment_rule' => 'open',
                'is_default' => $c['default'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    // ── OD-13b: one default lobby per programme, at the database ──

    public function test_second_default_lobby_loses_with_a_database_error(): void
    {
        Sanctum::actingAs($this->capabilityAdmin('configuration'));
        $this->postJson("/api/admin/programmes/{$this->programme->id}/team-categories", [
            'name_en' => 'A', 'name_tc' => '甲', 'name_sc' => '甲',
            'assignment_rule' => 'open', 'is_default' => true,
        ])->assertStatus(201);

        $this->postJson("/api/admin/programmes/{$this->programme->id}/team-categories", [
            'name_en' => 'B', 'name_tc' => '乙', 'name_sc' => '乙',
            'assignment_rule' => 'open', 'is_default' => true,
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('team_categories')->where('is_default', true)->count());
    }

    // ── team_categories: the five read branches (Leo item 1) ──

    public function test_academy_staff_sees_all_lobbies(): void
    {
        $this->seedCategories();
        Sanctum::actingAs($this->capabilityAdmin('audit_read'));
        $names = collect($this->getJson("/api/programmes/{$this->programme->id}/team-categories")->json('data'))->pluck('name_en');
        $this->assertCount(3, $names);
    }

    public function test_school_linked_teacher_sees_own_binding_plus_unbound(): void
    {
        $this->seedCategories();
        $teacher = User::factory()->create(['role' => 'teacher']);
        DB::table('teacher_links')->insert([
            'id' => (string) Str::uuid7(), 'teacher_id' => $teacher->id,
            'school_id' => $this->schoolA->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($teacher);
        $names = collect($this->getJson("/api/programmes/{$this->programme->id}/team-categories")->json('data'))->pluck('name_en');
        $this->assertEqualsCanonicalizing(['St. Paul Lobby', 'Open Lobby'], $names->all());
    }

    public function test_guardian_sees_via_their_students_school(): void
    {
        $this->seedCategories();
        $student = User::factory()->create(['role' => 'student']);
        DB::table('school_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $student->id,
            'school_id' => $this->schoolB->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $guardian = User::factory()->create(['role' => 'guardian']);
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $student->id,
            'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($guardian);
        $names = collect($this->getJson("/api/programmes/{$this->programme->id}/team-categories")->json('data'))->pluck('name_en');
        $this->assertEqualsCanonicalizing(['School B Lobby', 'Open Lobby'], $names->all());
    }

    public function test_unlinked_student_sees_only_unbound_lobbies(): void
    {
        $this->seedCategories();
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $names = collect($this->getJson("/api/programmes/{$this->programme->id}/team-categories")->json('data'))->pluck('name_en');
        $this->assertSame(['Open Lobby'], $names->all());
    }

    public function test_member_sees_zero_lobbies(): void
    {
        $this->seedCategories();
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->getJson("/api/programmes/{$this->programme->id}/team-categories")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // ── fee_items: OD-18 schema + isolation ──

    public function test_fee_amounts_are_integer_minor_units_with_iso_currency(): void
    {
        Sanctum::actingAs($this->capabilityAdmin('configuration'));
        $this->postJson("/api/admin/programmes/{$this->programme->id}/fee-items", [
            'name_en' => 'Tuition', 'name_tc' => '學費', 'name_sc' => '学费',
            'amount_minor' => 1280000, // HK$12,800.00
        ])->assertStatus(201);

        // Float-shaped input is rejected by validation
        $this->postJson("/api/admin/programmes/{$this->programme->id}/fee-items", [
            'name_en' => 'Kit', 'name_tc' => '套件', 'name_sc' => '套件',
            'amount_minor' => 128.50,
        ])->assertStatus(422);

        // Non-HKD rejected at the DB in Phase 1 (widening is a migration)
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('fee_items')->insert([
            'id' => (string) Str::uuid7(), 'programme_id' => $this->programme->id,
            'name_en' => 'x', 'name_tc' => 'x', 'name_sc' => 'x',
            'amount_minor' => 100, 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_fee_items_isolation_finance_sees_school_admin_and_guardian_do_not(): void
    {
        Sanctum::actingAs($this->capabilityAdmin('configuration'));
        $this->postJson("/api/admin/programmes/{$this->programme->id}/fee-items", [
            'name_en' => 'Tuition', 'name_tc' => '學費', 'name_sc' => '学费', 'amount_minor' => 1280000,
        ])->assertStatus(201);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->capabilityAdmin('finance'));
        $this->getJson("/api/admin/programmes/{$this->programme->id}/fee-items")
            ->assertOk()->assertJsonCount(1, 'data');

        // school_admin HOLDS finance.view (their own invoices) — the route
        // passes, and RLS answers with ZERO commercial rows: capability
        // present, terms absent. The sharper isolation proof.
        $this->app['auth']->forgetGuards();
        $schoolAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert([
            'id' => (string) Str::uuid7(), 'school_admin_id' => $schoolAdmin->id,
            'school_id' => $this->schoolA->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($schoolAdmin);
        $this->getJson("/api/admin/programmes/{$this->programme->id}/fee-items")
            ->assertOk()->assertJsonCount(0, 'data');

        // …and even at the RLS layer their session reads ZERO fee rows
        app(\App\Services\Authz\ScopeContext::class)->set($schoolAdmin->fresh());
        $this->assertSame(0, DB::table('fee_items')->count());
        app(\App\Services\Authz\ScopeContext::class)->setSystem();
    }

    // ── OD-12: thresholds editable after creation ──

    public function test_learn_thresholds_editable_after_creation_and_audited(): void
    {
        Sanctum::actingAs($this->capabilityAdmin('configuration'));
        $this->putJson("/api/admin/programmes/{$this->programme->id}/certification-rules", [
            'attendance_threshold_pct' => 70, 'team_gate_pass_pct' => 60,
        ])->assertOk();

        $this->putJson("/api/admin/programmes/{$this->programme->id}/certification-rules", [
            'attendance_threshold_pct' => 80, 'team_gate_pass_pct' => 75,
        ])->assertOk();

        $this->assertDatabaseHas('certification_rules', [
            'programme_id' => $this->programme->id,
            'attendance_threshold_pct' => 80, 'team_gate_pass_pct' => 75,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'certification_rules.updated']);
    }

    public function test_certification_rules_table_has_no_cobranding_columns(): void
    {
        $columns = collect(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'certification_rules'"
        ))->pluck('column_name');

        foreach (['partner', 'cobrand', 'co_brand', 'signatory', 'logo'] as $forbidden) {
            $this->assertFalse(
                $columns->contains(fn ($c) => str_contains($c, $forbidden)),
                "certification_rules must carry no '{$forbidden}' column (OD-21)",
            );
        }
    }
}
