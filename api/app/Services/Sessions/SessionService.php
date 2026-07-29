<?php

namespace App\Services\Sessions;

use App\Events\SessionRescheduled;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * S06-2 (2.3) — the session lifecycle. Draft → Published → Full → In Progress →
 * Completed / Cancelled, plus the RESCHEDULE action (keeps bookings, writes an
 * immutable session_version, re-opens booking if capacity grew, fires
 * `session.rescheduled`) and the reschedule CLASH check (2.24). Organiser =
 * academy operations/super. All state writes run under a system elevation.
 */
class SessionService
{
    /** Lifecycle transitions (2.3); reschedule is a separate action, not a target here. */
    public const TRANSITIONS = [
        'draft' => ['published', 'cancelled'],
        'published' => ['full', 'in_progress', 'cancelled'],
        'full' => ['published', 'in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'rescheduled' => ['published'],
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    /** Create a Draft session bound to a programme (and optionally a team, D4). */
    public function create(int $programmeId, array $attrs, User $actor): string
    {
        $this->assertOrganiser($actor);
        if (($attrs['ends_at'] ?? '') <= ($attrs['starts_at'] ?? '')) {
            throw ValidationException::withMessages(['ends_at' => ['Session must end after it starts']]);
        }

        return $this->scope->asSystem(
            'Session create (S06-2): the organiser creates a Draft session bound to a programme (optionally a team, D4). programme_sessions is a system-only write; the academy-operator authority was established before the elevation.',
            function () use ($programmeId, $attrs, $actor): string {
                $id = (string) Str::uuid7();
                DB::table('programme_sessions')->insert([
                    'id' => $id, 'programme_id' => $programmeId, 'team_id' => $attrs['team_id'] ?? null,
                    'title' => $attrs['title'], 'starts_at' => $attrs['starts_at'], 'ends_at' => $attrs['ends_at'],
                    'capacity' => (int) $attrs['capacity'], 'mentor_id' => $attrs['mentor_id'] ?? null,
                    'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record('session', $id, 'session.created', toState: 'draft', programmeId: $programmeId,
                    payloadAfter: ['title' => $attrs['title'], 'starts_at' => $attrs['starts_at']], actor: $actor);

                return $id;
            },
        );
    }

    /** Lifecycle transition (publish / start / complete / cancel / mark full). */
    public function transition(string $sessionId, string $to, User $actor): void
    {
        $this->assertOrganiser($actor);
        $this->scope->asSystem(
            'Session lifecycle transition (S06-2, 2.3): the organiser advances a session through its state machine (draft→published→full→in_progress→completed/cancelled). programme_sessions.status is a system-only write; authority established before the elevation.',
            function () use ($sessionId, $to, $actor): void {
                $session = DB::table('programme_sessions')->where('id', $sessionId)->first() ?? abort(404);
                if (! in_array($to, self::TRANSITIONS[$session->status] ?? [], true)) {
                    abort(409, "Illegal session transition {$session->status} → {$to}");
                }
                DB::table('programme_sessions')->where('id', $sessionId)->update(['status' => $to, 'updated_at' => now()]);
                $this->audit->record('session', $sessionId, "session.{$to}", fromState: $session->status, toState: $to,
                    programmeId: (int) $session->programme_id, actor: $actor);
            },
        );
    }

    /**
     * Reschedule (2.3 + 2.24). Keeps bookings; writes a session_version snapshot;
     * re-opens booking if capacity grew; returns clashing students and fires
     * `session.rescheduled`. Clash = a booked student who has another live booking
     * overlapping the NEW time (organiser sees the count pre-confirm via clashPreview).
     */
    public function reschedule(string $sessionId, string $newStart, string $newEnd, ?int $newCapacity, User $actor): array
    {
        $this->assertOrganiser($actor);
        if ($newEnd <= $newStart) {
            throw ValidationException::withMessages(['ends_at' => ['Session must end after it starts']]);
        }

        return $this->scope->asSystem(
            'Session reschedule (S06-2, 2.3/2.24): the organiser moves a session; the pre-change snapshot is written to session_versions, bookings are retained, booking re-opens if capacity grew, and clashing students are computed for re-notification. programme_sessions/session_versions are system-only writes; authority established before the elevation.',
            function () use ($sessionId, $newStart, $newEnd, $newCapacity, $actor): array {
                return DB::transaction(function () use ($sessionId, $newStart, $newEnd, $newCapacity, $actor): array {
                    $session = DB::table('programme_sessions')->where('id', $sessionId)->first() ?? abort(404);
                    if (! in_array($session->status, ['published', 'full'], true)) {
                        abort(409, "Only a published or full session can be rescheduled (is {$session->status})");
                    }
                    $capacity = $newCapacity ?? (int) $session->capacity;
                    $clashes = $this->clashingStudents($session, $newStart, $newEnd);

                    // immutable snapshot BEFORE the change
                    $nextVersion = (int) DB::table('session_versions')->where('session_id', $sessionId)->max('version') + 1;
                    DB::table('session_versions')->insert([
                        'id' => (string) Str::uuid7(), 'session_id' => $sessionId, 'version' => $nextVersion,
                        'payload' => json_encode(['starts_at' => $session->starts_at, 'ends_at' => $session->ends_at,
                            'capacity' => (int) $session->capacity, 'status' => $session->status]),
                        'reason' => 'rescheduled', 'created_by' => $actor->id, 'created_at' => now(),
                    ]);

                    // re-open booking if capacity grew and it was full
                    $newStatus = ($session->status === 'full' && $capacity > (int) $session->capacity) ? 'published' : $session->status;
                    DB::table('programme_sessions')->where('id', $sessionId)->update([
                        'starts_at' => $newStart, 'ends_at' => $newEnd, 'capacity' => $capacity,
                        'status' => $newStatus, 'updated_at' => now(),
                    ]);
                    $this->audit->record('session', $sessionId, 'session.rescheduled',
                        fromState: $session->status, toState: $newStatus, programmeId: (int) $session->programme_id,
                        payloadAfter: ['from' => $session->starts_at, 'to' => $newStart, 'clash_count' => count($clashes),
                            'reopened' => $newStatus !== $session->status], actor: $actor);

                    SessionRescheduled::dispatch($sessionId, (string) $session->starts_at, $newStart, $clashes);

                    return ['session_id' => $sessionId, 'version' => $nextVersion, 'clashing_students' => $clashes,
                        'clash_count' => count($clashes), 'reopened' => $newStatus !== $session->status];
                });
            },
        );
    }

    /** Pre-confirm: how many booked students would clash at the proposed new time (2.24). */
    public function clashPreview(string $sessionId, string $newStart, string $newEnd, User $actor): array
    {
        $this->assertOrganiser($actor);

        return $this->scope->asSystem(
            'Session clash preview (S06-2, 2.24): read-only count of booked students who would clash at a proposed new time — spans bookings across sessions the organiser cannot otherwise see. No write.',
            function () use ($sessionId, $newStart, $newEnd): array {
                $session = DB::table('programme_sessions')->where('id', $sessionId)->first() ?? abort(404);
                $clashes = $this->clashingStudents($session, $newStart, $newEnd);

                return ['clash_count' => count($clashes), 'clashing_students' => $clashes];
            },
        );
    }

    /** @return array<int, int> student ids booked on $session who have another LIVE booking overlapping [newStart,newEnd). */
    private function clashingStudents(object $session, string $newStart, string $newEnd): array
    {
        return DB::table('session_bookings as b')
            ->join('session_bookings as other', function ($j): void {
                $j->on('other.student_id', '=', 'b.student_id')->whereColumn('other.session_id', '<>', 'b.session_id');
            })
            ->join('programme_sessions as os', 'os.id', '=', 'other.session_id')
            ->where('b.session_id', $session->id)->whereIn('b.status', ['booked', 'waitlisted'])
            ->whereIn('other.status', ['booked', 'waitlisted'])
            ->whereNotIn('os.status', ['cancelled', 'completed'])
            ->where('os.starts_at', '<', $newEnd)->where('os.ends_at', '>', $newStart)
            ->distinct()->pluck('b.student_id')->map(fn ($x) => (int) $x)->all();
    }

    private function assertOrganiser(User $actor): void
    {
        if ($actor->role !== 'academy_admin') {
            abort(403, 'Session management is an academy action');
        }
        $caps = DB::table('admin_capabilities')->where('user_id', $actor->id)->pluck('capability');
        if (! $caps->contains('operations') && ! $caps->contains('super_admin')) {
            abort(403, 'Session management requires the operations capability');
        }
    }
}
