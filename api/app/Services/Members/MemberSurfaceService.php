<?php

namespace App\Services\Members;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** S06-5 (OD-22) — Member self-service: RSVP to published events, and the directory profile. */
class MemberSurfaceService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    public function rsvp(string $eventId, string $status, User $member): void
    {
        if (! in_array($status, ['going', 'not_going', 'maybe'], true)) {
            abort(422, 'RSVP status must be going, not_going or maybe');
        }

        $this->scope->asSystem(
            'Member RSVP (S06-5, OD-22): a Member records their own RSVP to a published event. event_rsvps is a system-only write; only the acting Member\'s own rsvp row is touched.',
            function () use ($eventId, $status, $member): void {
                $event = DB::table('events')->where('id', $eventId)->first() ?? abort(404);
                if ($event->status !== 'published') {
                    abort(409, 'You can only RSVP to a published event');
                }
                DB::table('event_rsvps')->updateOrInsert(
                    ['event_id' => $eventId, 'member_id' => $member->id],
                    ['id' => (string) Str::uuid7(), 'status' => $status, 'updated_at' => now(), 'created_at' => now()],
                );
                $this->audit->record('event_rsvp', "{$eventId}:{$member->id}", 'event_rsvp.set', toState: $status,
                    payloadAfter: ['event_id' => $eventId, 'member_id' => $member->id, 'status' => $status], actor: $member);
            },
        );
    }

    public function upsertProfile(array $attrs, User $member): void
    {
        $this->scope->asSystem(
            'Member profile upsert (S06-5, OD-22): a Member edits their own directory profile. member_profiles is a system-only write; only the acting Member\'s own profile is touched.',
            function () use ($attrs, $member): void {
                DB::table('member_profiles')->updateOrInsert(
                    ['user_id' => $member->id],
                    ['id' => (string) Str::uuid7(), 'display_name' => $attrs['display_name'],
                        'headline' => $attrs['headline'] ?? null, 'visible' => $attrs['visible'] ?? true,
                        'updated_at' => now(), 'created_at' => now()],
                );
                $this->audit->record('member_profile', (string) $member->id, 'member_profile.updated',
                    payloadAfter: ['user_id' => $member->id], actor: $member);
            },
        );
    }
}
