<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditEndpointSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_session_gets_401(): void
    {
        $this->getJson('/api/audit-events')->assertStatus(401);
    }

    public function test_session_without_audit_read_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'academy_admin']));
        $this->getJson('/api/audit-events')->assertStatus(403);
    }

    public function test_audit_read_capability_gets_200(): void
    {
        $admin = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $admin->id,
            'capability' => 'audit_read', 'granted_by' => $admin->id, 'granted_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/audit-events')->assertOk()->assertJsonStructure(['data', 'total']);
    }

    public function test_member_gets_403_not_the_data(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->getJson('/api/audit-events')->assertStatus(403);
    }
}
