<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Identity\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** The auth manager caches the resolved user between in-test requests — flush it. */
    private function refreshAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function verifiedUser(string $email = 'user@example.test'): User
    {
        return User::factory()->create(['email' => $email, 'role' => 'guardian']);
    }

    public function test_unverified_account_cannot_complete_first_login(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'new@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'new@example.test', 'password' => 'password'])
            ->assertStatus(403);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'failed_login',
            'entity_id' => (string) $user->id,
            'reason' => 'email not verified — first login refused (2.11)',
        ]);
    }

    public function test_verified_login_returns_token_and_audits(): void
    {
        $user = $this->verifiedUser();

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'login', 'actor_id' => $user->id, 'actor_role' => 'guardian',
        ]);
    }

    public function test_fifth_failure_locks_and_sixth_attempt_is_refused(): void
    {
        $user = $this->verifiedUser('locked@example.test');

        for ($i = 1; $i <= AuthService::MAX_FAILURES; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->assertDatabaseHas('audit_events', [
            'action' => 'lockout', 'entity_id' => (string) $user->id,
        ]);
        $this->assertNotNull($user->fresh()->locked_until);

        // 6th attempt — even the CORRECT password is refused while locked
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(423);
    }

    public function test_admin_unlock_clears_the_lock_early_and_audits(): void
    {
        $user = $this->verifiedUser('locked2@example.test');
        $user->forceFill(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(10)])->save();

        $admin = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(), 'user_id' => $admin->id,
            'capability' => 'operations', 'granted_by' => $admin->id, 'granted_at' => now(),
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$user->id}/unlock")->assertOk();

        $this->assertNull($user->fresh()->locked_until);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'lockout_cleared', 'actor_id' => $admin->id, 'entity_id' => (string) $user->id,
        ]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_session_token_idle_expires_after_12h_but_remember_survives(): void
    {
        $user = $this->verifiedUser('idle@example.test');
        $session = $user->createToken('session')->plainTextToken;
        $remember = $user->createToken('remember')->plainTextToken;

        // Age both tokens 13 hours
        DB::table('personal_access_tokens')->update([
            'created_at' => now()->subHours(13), 'last_used_at' => now()->subHours(13),
        ]);

        $this->refreshAuth();
        $this->getJson('/api/enrolments', ['Authorization' => "Bearer {$session}"])->assertStatus(401);
        $this->refreshAuth();
        $this->getJson('/api/enrolments', ['Authorization' => "Bearer {$remember}"])->assertStatus(501);

        // Age the remember token past 30 days — it dies too
        DB::table('personal_access_tokens')->update([
            'created_at' => now()->subDays(31), 'last_used_at' => now()->subDays(31),
        ]);
        $this->refreshAuth();
        $this->getJson('/api/enrolments', ['Authorization' => "Bearer {$remember}"])->assertStatus(401);
    }

    public function test_password_reset_is_single_use_and_invalidates_sessions(): void
    {
        Notification::fake();
        $user = $this->verifiedUser('reset@example.test');
        $oldToken = $user->createToken('session')->plainTextToken;

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'reset_requested', 'entity_id' => (string) $user->id]);

        $resetToken = Password::createToken($user);
        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email, 'token' => $resetToken, 'password' => 'brand-new-password-1',
        ])->assertOk();

        $this->assertDatabaseHas('audit_events', ['action' => 'reset_completed', 'entity_id' => (string) $user->id]);
        // All sessions invalidated
        $this->refreshAuth();
        $this->getJson('/api/enrolments', ['Authorization' => "Bearer {$oldToken}"])->assertStatus(401);
        // Single use: same token again fails
        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email, 'token' => $resetToken, 'password' => 'brand-new-password-2',
        ])->assertStatus(422);
        // New password works
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'brand-new-password-1'])->assertOk();
    }

    public function test_reset_link_expiry_is_one_hour(): void
    {
        $this->assertSame(60, (int) config('auth.passwords.users.expire'));
    }

    public function test_logout_revokes_token_and_audits(): void
    {
        $user = $this->verifiedUser('logout@example.test');
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('token');

        $this->postJson('/api/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'logout', 'actor_id' => $user->id]);
        $this->refreshAuth();
        $this->getJson('/api/enrolments', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }
}
