<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BootstrapSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_the_first_super_admin_with_audit_trail(): void
    {
        $this->artisan('bootstrap:super-admin', ['--email' => 'first-admin@synthetic.test'])
            ->expectsOutputToContain('Super admin created')
            ->expectsOutputToContain('STANDING GO-LIVE ITEM')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'first-admin@synthetic.test')->firstOrFail();
        $this->assertSame('academy_admin', $user->role);
        $this->assertDatabaseHas('admin_capabilities', [
            'user_id' => $user->id, 'capability' => 'super_admin', 'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'bootstrap.super_admin', 'entity_id' => (string) $user->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'capability.granted', 'entity_id' => (string) $user->id,
        ]);
    }

    public function test_refuses_when_any_active_super_admin_exists(): void
    {
        $this->artisan('bootstrap:super-admin', ['--email' => 'first@synthetic.test'])->assertExitCode(0);

        $this->artisan('bootstrap:super-admin', ['--email' => 'second@synthetic.test'])
            ->expectsOutputToContain('REFUSED')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'second@synthetic.test']);
    }

    public function test_refuses_even_when_super_admin_was_seeded_outside_the_command(): void
    {
        $admin = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $admin->id,
            'capability' => 'super_admin', 'granted_by' => $admin->id, 'granted_at' => now(),
        ]);

        $this->artisan('bootstrap:super-admin', ['--email' => 'late@synthetic.test'])
            ->expectsOutputToContain('REFUSED')
            ->assertExitCode(1);
    }

    public function test_runs_again_after_all_super_admins_are_revoked(): void
    {
        $this->artisan('bootstrap:super-admin', ['--email' => 'first@synthetic.test'])->assertExitCode(0);
        $firstId = User::query()->where('email', 'first@synthetic.test')->value('id');
        DB::table('admin_capabilities')->where('capability', 'super_admin')
            ->update(['revoked_at' => now(), 'revoked_by' => $firstId]);

        // No ACTIVE super_admin remains — bootstrap is legitimately available again
        $this->artisan('bootstrap:super-admin', ['--email' => 'replacement@synthetic.test'])->assertExitCode(0);
    }

    public function test_refuses_invalid_or_duplicate_email(): void
    {
        $this->artisan('bootstrap:super-admin', ['--email' => 'not-an-email'])
            ->expectsOutputToContain('REFUSED')->assertExitCode(1);

        User::factory()->create(['email' => 'taken@synthetic.test']);
        $this->artisan('bootstrap:super-admin', ['--email' => 'taken@synthetic.test'])
            ->expectsOutputToContain('REFUSED')->assertExitCode(1);
    }
}
