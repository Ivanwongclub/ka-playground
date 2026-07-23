<?php

namespace App\Services\Identity;

use App\Models\GuardianLink;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Guardian-led student creation (L4: the guardian is the anchor). The student's
 * credentials are created by the guardian (B10 rule 2 — students do not arrive
 * via Google or invitations); the account is treated as verified by that
 * guardian-led act. The guardian↔student link goes straight to active with
 * origin=onboarding — the guardian created the account, there is nothing to
 * confirm (B4's confirmation protects against mistyped pairing codes, step 5).
 */
class GuardianStudentService
{
    public function __construct(private readonly AuditService $audit) {}

    public function createStudent(User $guardian, string $name, string $email, string $password): User
    {
        $student = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'student',
            'email_verified_at' => now(), // guardian-led creation vouches the account
        ]);

        $link = GuardianLink::query()->create([
            'id' => (string) Str::uuid7(),
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'status' => 'active',
            'verified_at' => now(),
            'origin' => 'onboarding',
        ]);

        $this->audit->record(
            entityType: 'guardian_link',
            entityId: $link->id,
            action: 'guardian_link.created',
            toState: 'active',
            payloadAfter: ['student_id' => $student->id, 'guardian_id' => $guardian->id, 'origin' => 'onboarding'],
            actor: $guardian,
        );

        return $student;
    }
}
