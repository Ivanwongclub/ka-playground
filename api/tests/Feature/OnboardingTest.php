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

    public function test_member_invitations_are_refused_until_s06(): void
    {
        Sanctum::actingAs($this->opsAdmin());
        $this->postJson('/api/admin/invitations', ['email' => 'm@example.test', 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('errors.role.0', fn ($m) => str_contains($m, 'OD-22'));
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

    public function test_guardian_creates_student_with_active_link_and_audit(): void
    {
        $guardian = User::factory()->create(['role' => 'guardian']);
        Sanctum::actingAs($guardian);

        $response = $this->postJson('/api/my/students', [
            'name' => 'Chan Tai Man', 'email' => 'ctm@example.test', 'password' => 'student-pass-12345',
        ])->assertStatus(201);

        $studentId = $response->json('student_id');
        $this->assertDatabaseHas('users', ['id' => $studentId, 'role' => 'student']);
        $this->assertDatabaseHas('guardian_links', [
            'student_id' => $studentId, 'guardian_id' => $guardian->id,
            'status' => 'active', 'origin' => 'onboarding',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'guardian_link.created', 'actor_id' => $guardian->id, 'actor_role' => 'guardian',
        ]);
    }

    public function test_non_guardian_cannot_create_students(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->postJson('/api/my/students', [
            'name' => 'X', 'email' => 'x@example.test', 'password' => 'student-pass-12345',
        ])->assertStatus(403);
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
