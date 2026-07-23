<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuthEventType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditSpineTest extends TestCase
{
    use RefreshDatabase;

    private function recordSampleEvent(): AuditEvent
    {
        return app(AuditService::class)->record(
            entityType: 'enrolment',
            entityId: '42',
            action: 'status.changed',
            fromState: 'Intent',
            toState: 'ConsentPending',
        );
    }

    public function test_update_on_audit_events_fails_at_the_database(): void
    {
        $this->recordSampleEvent();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('INSERT-only');

        DB::table('audit_events')->update(['action' => 'tampered']);
    }

    public function test_delete_on_audit_events_fails_at_the_database(): void
    {
        $this->recordSampleEvent();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('INSERT-only');

        DB::table('audit_events')->delete();
    }

    public function test_model_layer_also_refuses_updates(): void
    {
        $this->recordSampleEvent();

        $this->expectException(LogicException::class);

        AuditEvent::query()->firstOrFail()->update(['action' => 'tampered']);
    }

    public function test_service_writes_event_carrying_actor_identity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $event = $this->recordSampleEvent();

        $this->assertDatabaseHas('audit_events', [
            'event_id' => $event->event_id,
            'actor_id' => $user->id,
            'entity_type' => 'enrolment',
            'entity_id' => '42',
            'action' => 'status.changed',
            'from_state' => 'Intent',
            'to_state' => 'ConsentPending',
        ]);
    }

    public function test_auth_event_types_are_reserved_per_2_11(): void
    {
        $this->assertSame(
            ['login', 'logout', 'failed_login', 'lockout', 'reset_requested', 'reset_completed', 'invitation_accepted', 'email_verified'],
            array_column(AuthEventType::cases(), 'value'),
        );
    }
}
