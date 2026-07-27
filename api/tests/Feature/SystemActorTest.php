<?php

namespace Tests\Feature;

use App\Events\EnrolmentTransitioned;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SystemActorTest extends TestCase
{
    use RefreshDatabase;

    public function test_actorless_audit_in_system_context_is_attributed_to_system_never_null(): void
    {
        app(ScopeContext::class)->setSystem();
        $event = app(AuditService::class)->record('enrolment', 'od64-probe', 'enrolment.completed',
            toState: 'completed', reason: 'job-driven transition, no human actor');

        $row = DB::table('audit_events')->where('event_id', $event->event_id)->first();
        $this->assertNull($row->actor_id);
        $this->assertSame('system', $row->actor_role, 'OD-64: SYSTEM actor, never a null attribution');
    }

    public function test_human_actor_attribution_is_untouched_by_the_fallback(): void
    {
        $guardian = User::factory()->create(['role' => 'guardian']);
        $event = app(AuditService::class)->record('enrolment', 'od64-probe-2', 'enrolment.submitted',
            toState: 'submitted', actor: $guardian);
        $row = DB::table('audit_events')->where('event_id', $event->event_id)->first();
        $this->assertSame($guardian->id, (int) $row->actor_id);
        $this->assertSame('guardian', $row->actor_role);
    }

    public function test_every_enrolment_transition_raises_its_od66_event(): void
    {
        Event::fake([EnrolmentTransitioned::class]);
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(), 'student_id' => $student->id,
            'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $programme = \App\Models\Programme::query()->create([
            'code' => 'OD66-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(4)),
            'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK',
        ]);
        DB::table('enrolments')->insert([
            'id' => $id = (string) \Illuminate\Support\Str::uuid7(), 'programme_id' => $programme->id,
            'student_id' => $student->id, 'acting_guardian_id' => $guardian->id,
            'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(\App\Services\Enrolments\EnrolmentService::class)->transition($id, 'pending_consent', $guardian);

        Event::assertDispatched(EnrolmentTransitioned::class,
            fn ($e) => $e->enrolmentId === $id && $e->from === 'submitted' && $e->to === 'pending_consent');
    }
}
