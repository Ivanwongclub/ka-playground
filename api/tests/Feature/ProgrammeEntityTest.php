<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgrammeEntityTest extends TestCase
{
    use RefreshDatabase;

    private function configAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $admin->id,
            'capability' => 'configuration', 'granted_by' => $admin->id, 'granted_at' => now(),
        ]);

        return $admin;
    }

    private function programmePayload(): array
    {
        return [
            'code' => 'STEM-CAR-2026',
            'name_en' => 'STEM on Car 2026',
            'name_tc' => '車上STEM 2026',
            'name_sc' => '车上STEM 2026',
            'jurisdiction' => 'HK',
            'hold_window_days' => 7,
            'payer_party' => 'parent',
        ];
    }

    public function test_configuration_capability_required(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'academy_admin'])); // no capability
        $this->postJson('/api/admin/programmes', $this->programmePayload())->assertStatus(403);
        $this->assertDatabaseHas('audit_events', ['action' => 'permission.denied']);
    }

    public function test_programme_created_with_jurisdiction_and_audited(): void
    {
        Sanctum::actingAs($this->configAdmin());
        $id = $this->postJson('/api/admin/programmes', $this->programmePayload())
            ->assertStatus(201)->json('id');

        $this->assertDatabaseHas('programmes', [
            'id' => $id, 'jurisdiction' => 'HK', 'status' => 'draft', 'hold_window_days' => 7,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'programme.created', 'entity_id' => (string) $id]);
    }

    public function test_trilingual_names_are_all_required(): void
    {
        Sanctum::actingAs($this->configAdmin());
        $payload = $this->programmePayload();
        unset($payload['name_sc']);
        $this->postJson('/api/admin/programmes', $payload)->assertStatus(422);
    }

    public function test_jurisdiction_constrained_at_api_and_database(): void
    {
        Sanctum::actingAs($this->configAdmin());
        $payload = $this->programmePayload();
        $payload['jurisdiction'] = 'UK';
        $this->postJson('/api/admin/programmes', $payload)->assertStatus(422);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('programmes')->insert(array_merge($this->programmePayload(), [
            'jurisdiction' => 'UK', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_version_snapshots_number_sequentially_and_audit(): void
    {
        Sanctum::actingAs($this->configAdmin());
        $id = $this->postJson('/api/admin/programmes', $this->programmePayload())->json('id');

        $this->postJson("/api/admin/programmes/{$id}/versions")->assertStatus(201)->assertJsonPath('version', 1);
        $this->postJson("/api/admin/programmes/{$id}/versions")->assertStatus(201)->assertJsonPath('version', 2);

        $this->assertDatabaseHas('audit_events', ['action' => 'programme.version_snapshotted', 'to_state' => 'v2']);
        $config = DB::table('programme_versions')->where('programme_id', $id)->where('version', 1)->value('config');
        $this->assertSame('STEM-CAR-2026', json_decode($config, true)['code']);
    }

    public function test_version_snapshots_are_immutable_at_the_database(): void
    {
        Sanctum::actingAs($this->configAdmin());
        $id = $this->postJson('/api/admin/programmes', $this->programmePayload())->json('id');
        $this->postJson("/api/admin/programmes/{$id}/versions")->assertStatus(201);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('INSERT-only');
        DB::table('programme_versions')->where('programme_id', $id)->update(['version' => 99]);
    }

    public function test_schools_crud_trilingual_and_audited(): void
    {
        Sanctum::actingAs($this->configAdmin());
        $id = $this->postJson('/api/admin/schools', [
            'name_en' => 'Bright Future Academy', 'name_tc' => '明日學院', 'name_sc' => '明日学院',
        ])->assertStatus(201)->json('id');

        $this->postJson('/api/admin/schools', ['name_en' => 'Only English'])->assertStatus(422);

        $this->putJson("/api/admin/schools/{$id}", [
            'name_en' => 'Bright Future Academy', 'name_tc' => '明日書院', 'name_sc' => '明日书院',
        ])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'school.updated', 'entity_id' => (string) $id]);
        $this->assertSame(1, Programme::query()->count() + 0 + \App\Models\School::query()->where('id', $id)->count());
    }
}
