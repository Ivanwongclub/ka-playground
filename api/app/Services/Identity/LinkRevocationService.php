<?php

namespace App\Services\Identity;

use App\Models\GuardianLink;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\PermissionResolver;
use App\Services\Authz\ScopeContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guardian-link revocation with the 2.2 continuity rule: the LAST active link
 * of a student holding a non-terminal enrolment cannot be revoked by guardian
 * or student alone — Academy Admin action with a recorded reason, which opens
 * a 14-day replacement exception. Signed consents stay valid regardless (they
 * bind to their signed version — S03's problem, recorded here as doctrine).
 */
class LinkRevocationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly EnrolmentStatusPort $enrolments,
        private readonly PermissionResolver $resolver,
        private readonly ScopeContext $scope,
    ) {}

    public function revoke(User $actor, GuardianLink $link, ?string $reason): GuardianLink
    {
        if ($link->status !== 'active') {
            throw ValidationException::withMessages(['link' => ['Only an active link can be revoked']]);
        }

        // Platform integrity check: sole-ness must see ALL links, not the
        // actor's scoped view (RLS hides co-guardians from each other)
        $isSoleLink = $this->scope->asSystem(
            'Sole-guardian integrity check (2.2): sole-ness must count ALL active links, while RLS correctly hides co-guardians from each other. Read-only count; result never exposes the hidden rows.',
            fn (): bool => GuardianLink::query()
            ->where('student_id', $link->student_id)
            ->where('status', 'active')
            ->where('id', '!=', $link->id)
            ->doesntExist());

        $guarded = $isSoleLink && $this->enrolments->hasNonTerminalEnrolments($link->student_id);

        if ($guarded) {
            $isOpsAdmin = $actor->role === 'academy_admin'
                && $this->resolver->allows($actor, 'operations.manage');
            if (! $isOpsAdmin) {
                $this->audit->record(
                    'guardian_link', $link->id, 'guardian_link.revocation_refused',
                    reason: 'sole active guardian link with a non-terminal enrolment — Academy Admin action required (2.2)',
                    actor: $actor,
                );
                throw new AuthorizationException(
                    'This is the student\'s last active guardian link — an Academy Administrator must action it (2.2)'
                );
            }
            if ($reason === null || trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => ['A recorded reason is required (2.2)']]);
            }
        }

        $link->forceFill(['status' => 'revoked'])->save();
        $this->audit->record(
            'guardian_link', $link->id, 'guardian_link.revoked',
            fromState: 'active', toState: 'revoked',
            reason: $reason, actor: $actor,
        );

        if ($guarded) {
            $exceptionId = (string) Str::uuid7();
            \Illuminate\Support\Facades\DB::table('guardian_replacement_exceptions')->insert([
                'id' => $exceptionId,
                'student_id' => $link->student_id,
                'revoked_link_id' => $link->id,
                'reason' => $reason,
                'deadline' => now()->addDays(14),
                'status' => 'open',
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record(
                'guardian_replacement_exception', $exceptionId, 'guardian_replacement.opened',
                toState: 'open',
                reason: 'sole guardian link revoked — replacement required within 14 days (2.2)',
                payloadAfter: ['student_id' => $link->student_id, 'deadline' => now()->addDays(14)->toIso8601String()],
                actor: $actor,
            );
            // Admin alert: exception queue + critical log now; K-engine channel in S09
            Log::critical('Guardian replacement exception opened (2.2)', [
                'exception_id' => $exceptionId, 'student_id' => $link->student_id,
            ]);
        }

        return $link;
    }
}
