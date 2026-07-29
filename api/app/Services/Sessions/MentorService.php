<?php

namespace App\Services\Sessions;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S06-2 (2.6) — the mentor lifecycle. Active → Inactive (no new bookings) →
 * Departed. Departing is BLOCKED while the mentor still has future, non-terminal
 * sessions — each must be reassigned or rescheduled first, so a departing mentor
 * never strands a session. Academy operations/super manage the lifecycle.
 */
class MentorService
{
    public const TRANSITIONS = [
        'active' => ['inactive', 'departed'],
        'inactive' => ['active', 'departed'],
        'departed' => [],
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    public function setStatus(int $userId, string $to, User $actor): void
    {
        $this->assertOrganiser($actor);
        $user = DB::table('users')->where('id', $userId)->first();
        if ($user === null || $user->role !== 'teacher') {
            abort(422, 'A mentor must be a teacher (2.6)');
        }

        $this->scope->asSystem(
            'Mentor lifecycle (S06-2, 2.6): the academy moves a mentor active/inactive/departed; departing is refused while future non-terminal sessions remain (reassign or reschedule first). mentors is a system-only write; authority established before the elevation.',
            function () use ($userId, $to, $actor): void {
                $mentor = DB::table('mentors')->where('user_id', $userId)->first();
                $from = $mentor->status ?? 'active';
                if ($mentor !== null && ! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                    abort(409, "Illegal mentor transition {$from} → {$to}");
                }
                if ($to === 'departed') {
                    $future = DB::table('programme_sessions')->where('mentor_id', $userId)
                        ->where('starts_at', '>', now())->whereNotIn('status', ['cancelled', 'completed'])->count();
                    if ($future > 0) {
                        abort(409, "Mentor has {$future} future session(s) — reassign or reschedule each before departing (2.6)");
                    }
                }
                DB::table('mentors')->updateOrInsert(
                    ['user_id' => $userId],
                    ['status' => $to, 'updated_at' => now(), 'created_at' => $mentor->created_at ?? now(), 'id' => $mentor->id ?? (string) Str::uuid7()],
                );
                $this->audit->record('mentor', (string) $userId, "mentor.{$to}", fromState: $from, toState: $to,
                    payloadAfter: ['user_id' => $userId], actor: $actor);
            },
        );
    }

    private function assertOrganiser(User $actor): void
    {
        if ($actor->role !== 'academy_admin') {
            abort(403, 'Mentor management is an academy action');
        }
        $caps = DB::table('admin_capabilities')->where('user_id', $actor->id)->pluck('capability');
        if (! $caps->contains('operations') && ! $caps->contains('super_admin')) {
            abort(403, 'Mentor management requires the operations capability');
        }
    }
}
