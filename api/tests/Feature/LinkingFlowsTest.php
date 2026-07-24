<?php

namespace Tests\Feature;

use App\Models\GuardianLink;
use App\Models\PairingCode;
use App\Models\User;
use App\Services\Identity\EnrolmentStatusPort;
use App\Services\Identity\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlwaysEnrolled implements EnrolmentStatusPort
{
    public function hasNonTerminalEnrolments(int $studentId): bool
    {
        return true; // 2.2 fixture — real adapter arrives in S04A
    }
}

class LinkingFlowsTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    private function guardian(): User
    {
        return User::factory()->create(['role' => 'guardian']);
    }

    // ── Pairing codes (B4 / 2.13) ──

    public function test_student_generates_at_most_five_active_codes(): void
    {
        $student = $this->student();
        Sanctum::actingAs($student);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/my/pairing-codes')->assertStatus(201);
        }
        $this->postJson('/api/my/pairing-codes')->assertStatus(422);
    }

    public function test_redeem_then_student_confirms_to_active(): void
    {
        $student = $this->student();
        $guardian = $this->guardian();
        Sanctum::actingAs($student);
        $code = $this->postJson('/api/my/pairing-codes')->json('code');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $linkId = $this->postJson('/api/pairing-codes/redeem', ['code' => $code])
            ->assertStatus(201)->assertJsonPath('status', 'pending_confirmation')->json('link_id');

        // Code is consumed on first successful use (B4)
        $this->postJson('/api/pairing-codes/redeem', ['code' => $code])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($student);
        $this->postJson("/api/my/guardian-requests/{$linkId}/confirm", ['accept' => true])
            ->assertOk()->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('audit_events', ['action' => 'guardian_link.confirmed', 'entity_id' => $linkId]);
    }

    public function test_code_is_case_sensitive(): void
    {
        $student = $this->student();
        Sanctum::actingAs($student);
        $code = $this->postJson('/api/my/pairing-codes')->json('code');
        $flipped = strtoupper($code) === $code ? strtolower($code) : strtoupper($code);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian());
        if ($flipped !== $code) {
            $this->postJson('/api/pairing-codes/redeem', ['code' => $flipped])->assertStatus(422);
        }
        $this->postJson('/api/pairing-codes/redeem', ['code' => $code])->assertStatus(201);
    }

    public function test_eleventh_global_failed_attempt_finds_the_code_hard_invalidated(): void
    {
        $student = $this->student();
        Sanctum::actingAs($student);
        $code = $this->postJson('/api/my/pairing-codes')->json('code');
        $codeRow = PairingCode::query()->where('code', $code)->firstOrFail();

        // GLOBAL means across accounts (2.13): ten DIFFERENT already-linked
        // guardians each fail once against this code string
        for ($i = 1; $i <= 10; $i++) {
            $linked = $this->guardian();
            GuardianLink::query()->create([
                'id' => (string) Str::uuid7(), 'student_id' => $student->id,
                'guardian_id' => $linked->id, 'status' => 'active', 'origin' => 'onboarding',
            ]);
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($linked);
            $this->postJson('/api/pairing-codes/redeem', ['code' => $code])->assertStatus(422);
        }

        $this->assertNotNull($codeRow->fresh()->invalidated_at, 'code must be hard-invalidated after 10 global fails');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'pairing_code.invalidated', 'entity_id' => $codeRow->id,
        ]);

        // 11th attempt — by a legitimate NEW guardian — finds the code dead
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian());
        $this->postJson('/api/pairing-codes/redeem', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'This code has been invalidated');
    }

    public function test_pairing_redemption_throttled_at_5_per_hour_per_account(): void
    {
        Sanctum::actingAs($this->guardian());
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/pairing-codes/redeem', ['code' => 'zzzzz'.$i % 10])->assertStatus(422);
        }
        $this->postJson('/api/pairing-codes/redeem', ['code' => 'zzzzz9'])->assertStatus(429);
    }

    public function test_unauthenticated_attacker_cannot_touch_the_failure_counter(): void
    {
        // D1a (Leo): targeted invalidation DoS requires reaching recordFailure().
        // Unauthenticated requests die at 401 — the counter is untouched.
        $student = $this->student();
        Sanctum::actingAs($student);
        $code = $this->postJson('/api/my/pairing-codes')->json('code');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/pairing-codes/redeem', ['code' => $code])->assertStatus(401);
        $this->postJson('/api/pairing-codes/redeem', ['code' => 'AAAAAA'])->assertStatus(401);

        $this->assertSame(0, DB::table('pairing_code_failures')->count());
        $this->assertNull(PairingCode::query()->where('code', $code)->firstOrFail()->invalidated_at);
    }

    public function test_recovery_after_invalidation_regenerate_works_immediately(): void
    {
        // D1b (Leo): an invalidated code is one dead code, not a locked-out family.
        // Invalidated codes do not count toward the max-5, so regeneration works.
        $student = $this->student();
        Sanctum::actingAs($student);
        $code = $this->postJson('/api/my/pairing-codes')->json('code');
        $row = PairingCode::query()->where('code', $code)->firstOrFail();
        $row->forceFill(['invalidated_at' => now()])->save();
        DB::table('pairing_code_failures')->insert(['code' => $code, 'attempts' => 10, 'last_attempt_at' => now()]);

        $fresh = $this->postJson('/api/my/pairing-codes')->assertStatus(201)->json('code');
        $this->assertNotSame($code, $fresh);

        // And the parent-initiated flow remains an independent alternate route
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->guardian());
        $this->postJson('/api/my/link-requests', ['student_email' => $student->email])->assertStatus(202);
    }

    // ── Parent-initiated + school-mediated (B4) ──

    public function test_parent_initiated_request_pends_and_never_leaks_existence(): void
    {
        $student = $this->student();
        Sanctum::actingAs($this->guardian());

        $this->postJson('/api/my/link-requests', ['student_email' => $student->email])->assertStatus(202);
        $this->postJson('/api/my/link-requests', ['student_email' => 'ghost@example.test'])->assertStatus(202);

        $this->assertDatabaseHas('guardian_links', [
            'student_id' => $student->id, 'status' => 'pending_confirmation', 'origin' => 'parent_initiated',
        ]);
    }

    public function test_school_vouched_link_auto_activates_for_own_school_student(): void
    {
        $school = \App\Models\School::query()->create(['name_en' => 'A', 'name_tc' => '甲', 'name_sc' => '甲']);
        $student = $this->student();
        $guardian = $this->guardian();
        $admin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $admin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/school/guardian-links', [
            'student_id' => $student->id, 'guardian_email' => $guardian->email,
        ])->assertStatus(201);

        $this->assertDatabaseHas('guardian_links', [
            'student_id' => $student->id, 'guardian_id' => $guardian->id,
            'status' => 'active', 'origin' => 'school_mediated',
        ]);
    }

    public function test_school_vouch_for_another_schools_student_is_denied_and_audited(): void
    {
        $schoolA = \App\Models\School::query()->create(['name_en' => 'A', 'name_tc' => '甲', 'name_sc' => '甲']);
        $schoolB = \App\Models\School::query()->create(['name_en' => 'B', 'name_tc' => '乙', 'name_sc' => '乙']);
        $student = $this->student(); // enrolled at school B
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $schoolB->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $adminA = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $adminA->id, 'school_id' => $schoolA->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        Sanctum::actingAs($adminA);
        $this->postJson('/api/school/guardian-links', [
            'student_id' => $student->id, 'guardian_email' => $this->guardian()->email,
        ])->assertStatus(404); // does not leak existence

        $this->assertDatabaseHas('audit_events', [
            'action' => 'scope.denied', 'actor_id' => $adminA->id,
        ]);
        $this->assertDatabaseMissing('guardian_links', ['student_id' => $student->id, 'origin' => 'school_mediated']);
    }

    // ── Continuity rule (2.2) ──

    private function activeLink(User $student, User $guardian): GuardianLink
    {
        return GuardianLink::query()->create([
            'id' => (string) Str::uuid7(), 'student_id' => $student->id,
            'guardian_id' => $guardian->id, 'status' => 'active',
            'verified_at' => now(), 'origin' => 'onboarding',
        ]);
    }

    public function test_sole_link_with_enrolment_cannot_be_revoked_by_guardian_and_refusal_is_audited(): void
    {
        $this->app->bind(EnrolmentStatusPort::class, AlwaysEnrolled::class);
        $guardian = $this->guardian();
        $link = $this->activeLink($this->student(), $guardian);

        Sanctum::actingAs($guardian);
        $this->postJson("/api/guardian-links/{$link->id}/revoke")->assertStatus(403);

        $this->assertSame('active', $link->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'guardian_link.revocation_refused', 'entity_id' => $link->id,
        ]);
    }

    public function test_admin_revocation_of_sole_link_needs_reason_and_opens_14_day_exception(): void
    {
        $this->app->bind(EnrolmentStatusPort::class, AlwaysEnrolled::class);
        $student = $this->student();
        $link = $this->activeLink($student, $this->guardian());

        $admin = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $admin->id,
            'capability' => 'operations', 'granted_by' => $admin->id, 'granted_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        // Without a reason: refused (2.2 requires a recorded reason)
        $this->postJson("/api/guardian-links/{$link->id}/revoke")->assertStatus(422);

        $this->postJson("/api/guardian-links/{$link->id}/revoke", [
            'reason' => 'Guardian deceased — family requests replacement',
        ])->assertOk();

        $this->assertSame('revoked', $link->fresh()->status);
        $exception = DB::table('guardian_replacement_exceptions')->where('student_id', $student->id)->first();
        $this->assertNotNull($exception, 'replacement exception must open');
        $this->assertSame('open', $exception->status);
        $this->assertEqualsWithDelta(
            now()->addDays(14)->timestamp,
            \Illuminate\Support\Carbon::parse($exception->deadline)->timestamp,
            5,
        );
        $this->assertDatabaseHas('audit_events', ['action' => 'guardian_replacement.opened']);
    }

    public function test_non_sole_link_revokes_freely_and_no_exception_opens(): void
    {
        $this->app->bind(EnrolmentStatusPort::class, AlwaysEnrolled::class);
        $student = $this->student();
        $guardian1 = $this->guardian();
        $link1 = $this->activeLink($student, $guardian1);
        $this->activeLink($student, $this->guardian());

        Sanctum::actingAs($guardian1);
        $this->postJson("/api/guardian-links/{$link1->id}/revoke")->assertOk();

        $this->assertSame('revoked', $link1->fresh()->status);
        $this->assertSame(0, DB::table('guardian_replacement_exceptions')->count());
    }

    public function test_without_enrolments_sole_link_revokes_freely_vacuous_until_s04a(): void
    {
        // Default NoEnrolmentsYet binding: the guard is vacuously permissive
        $guardian = $this->guardian();
        $link = $this->activeLink($this->student(), $guardian);

        Sanctum::actingAs($guardian);
        $this->postJson("/api/guardian-links/{$link->id}/revoke")->assertOk();
        $this->assertSame(0, DB::table('guardian_replacement_exceptions')->count());
    }

    public function test_pairing_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(PairingService::class, app(PairingService::class));
    }
}
