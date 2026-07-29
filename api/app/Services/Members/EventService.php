<?php

namespace App\Services\Members;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** S06-5 (OD-22) — network events, academy-managed. Members read published ones (network-wide). */
class EventService
{
    public const TRANSITIONS = ['draft' => ['published', 'cancelled'], 'published' => ['cancelled'], 'cancelled' => []];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    public function create(array $attrs, User $actor): string
    {
        $this->assertOrganiser($actor);

        return $this->scope->asSystem(
            'Event create (S06-5, OD-22): the academy creates a Draft network event. events is a system-only write; the academy-operator authority was established before the elevation.',
            function () use ($attrs, $actor): string {
                $id = (string) Str::uuid7();
                DB::table('events')->insert([
                    'id' => $id, 'title_en' => $attrs['title_en'], 'title_tc' => $attrs['title_tc'], 'title_sc' => $attrs['title_sc'],
                    'starts_at' => $attrs['starts_at'], 'location' => $attrs['location'] ?? null, 'status' => 'draft',
                    'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record('event', $id, 'event.created', toState: 'draft', payloadAfter: ['title_en' => $attrs['title_en']], actor: $actor);

                return $id;
            },
        );
    }

    public function transition(string $eventId, string $to, User $actor): void
    {
        $this->assertOrganiser($actor);
        $this->scope->asSystem(
            'Event lifecycle transition (S06-5, OD-22): the academy publishes or cancels a network event. events.status is a system-only write; authority established before the elevation.',
            function () use ($eventId, $to, $actor): void {
                $event = DB::table('events')->where('id', $eventId)->first() ?? abort(404);
                if (! in_array($to, self::TRANSITIONS[$event->status] ?? [], true)) {
                    abort(409, "Illegal event transition {$event->status} → {$to}");
                }
                DB::table('events')->where('id', $eventId)->update(['status' => $to, 'updated_at' => now()]);
                $this->audit->record('event', $eventId, "event.{$to}", fromState: $event->status, toState: $to, actor: $actor);
            },
        );
    }

    private function assertOrganiser(User $actor): void
    {
        if ($actor->role !== 'academy_admin') {
            abort(403, 'Event management is an academy action');
        }
        $caps = DB::table('admin_capabilities')->where('user_id', $actor->id)->pluck('capability');
        if (! $caps->contains('operations') && ! $caps->contains('super_admin')) {
            abort(403, 'Event management requires the operations capability');
        }
    }
}
