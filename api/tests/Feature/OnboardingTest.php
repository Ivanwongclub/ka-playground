<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Identity\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function opsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $admin->id,
            'capability' => 'operations', 'granted_by' => $admin->id, 'granted_at' => now(),
        ]);

        return $admin;
    }

    private function issue(string $role = 'guardian', string $email = 'parent@example.test'): array
    {
        Sanctum::actingAs($this->opsAdmin());

        return $this->postJson('/api/admin/invitations', ['email' => $email, 'role' => $role])
            ->assertStatus(201)
            ->json();
    }

    public function test_invitation_issue_requires_operations_permission(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));
        $this->postJson('/api/admin/invitations', ['email' => 'x@example.test', 'role' => 'guardian'])
            ->assertStatus(403);
    }

    public function test_member_invitations_are_enabled_once_s06_surfaces_ship(): void
    {
        // S-UX3-8 (OD-22): the Member surfaces are now delivered, so the member-invitation deferral is
        // LIFTED — a member invitation is issued like any other invitable role. (Was: refused-until-s06.)
        Sanctum::actingAs($this->opsAdmin());
        $this->postJson('/api/admin/invitations', ['email' => 'm@example.test', 'role' => 'member'])
            ->assertStatus(201);
    }

    public function test_student_invitations_are_refused_guardian_led(): void
    {
        Sanctum::actingAs($this->opsAdmin());
        $this->postJson('/api/admin/invitations', ['email' => 's@example.test', 'role' => 'student'])
            ->assertStatus(422);
    }

    public function test_accept_creates_unverified_user_and_is_single_use(): void
    {
        Notification::fake();
        $issued = $this->issue();

        $this->postJson('/api/onboarding/accept', [
            'token' => $issued['token'], 'password' => 'correct-horse-battery',
        ])->assertStatus(201)->assertJsonPath('verification_required', true);

        $user = User::query()->where('email', 'parent@example.test')->firstOrFail();
        $this->assertSame('guardian', $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'invitation_accepted', 'entity_id' => $issued['invitation_id'],
        ]);

        // Single use: the same token is dead
        $this->postJson('/api/onboarding/accept', [
            'token' => $issued['token'], 'password' => 'another-password-123',
        ])->assertStatus(422);
    }

    public function test_expired_invitation_is_refused(): void
    {
        Notification::fake();
        $issued = $this->issue();
        DB::table('invitations')->where('id', $issued['invitation_id'])
            ->update(['expires_at' => now()->subDay()]);

        $this->postJson('/api/onboarding/accept', [
            'token' => $issued['token'], 'password' => 'correct-horse-battery',
        ])->assertStatus(422);
    }

    public function test_signed_verification_link_verifies_and_audits(): void
    {
        Notification::fake();
        $issued = $this->issue();
        $this->postJson('/api/onboarding/accept', [
            'token' => $issued['token'], 'password' => 'correct-horse-battery',
        ])->assertStatus(201);
        $user = User::query()->where('email', 'parent@example.test')->firstOrFail();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'email_verified', 'entity_id' => (string) $user->id,
        ]);

        // Tampered hash refused
        $other = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1('other@example.test'),
        ]);
        $this->getJson($other)->assertStatus(403);
    }

    public function test_guardian_creates_student_path_is_retired(): void
    {
        // OD-27 (S04C STEP 4): guardian-creates-student is retired — students are
        // created by self-registration + approval now. The endpoint no longer exists.
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->postJson('/api/my/students', [
            'name' => 'Chan Tai Man', 'email' => 'ctm@example.test', 'password' => 'student-pass-12345',
        ])->assertStatus(404);
    }

    public function test_invitation_stores_only_a_hash_of_the_token(): void
    {
        Notification::fake();
        $issued = $this->issue(email: 'hash@example.test');
        $this->assertDatabaseMissing('invitations', ['token_hash' => $issued['token']]);
        $this->assertDatabaseHas('invitations', ['token_hash' => hash('sha256', $issued['token'])]);
        $this->assertSame(1, \App\Models\Invitation::query()->where('email', 'hash@example.test')->count());
        app(InvitationService::class); // container resolution smoke
    }
}
