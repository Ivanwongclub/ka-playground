<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => 'operations', 'granted_by' => $this->ops->id, 'granted_at' => now()]);
    }

    private function member(): User
    {
        return User::factory()->create(['role' => 'member']);
    }

    private function publishedEvent(): string
    {
        Sanctum::actingAs($this->ops);
        $id = $this->postJson('/api/admin/events', ['title_en' => 'Gala', 'title_tc' => '晚宴', 'title_sc' => '晚宴', 'starts_at' => '2026-12-01 18:00:00'])->assertStatus(201)->json('id');
        $this->postJson("/api/admin/events/{$id}/transition", ['to' => 'published'])->assertOk();
        $this->app['auth']->forgetGuards();

        return $id;
    }

    private function setProfile(User $member, string $name): void
    {
        Sanctum::actingAs($member);
        $this->putJson('/api/my/profile', ['display_name' => $name, 'visible' => true])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    public function test_member_sees_published_events_but_a_student_does_not(): void
    {
        $eventId = $this->publishedEvent();
        $member = $this->member();

        Sanctum::actingAs($member);
        $events = $this->getJson('/api/events')->assertOk()->json('events');
        $this->app['auth']->forgetGuards();
        $this->assertSame([$eventId], array_column($events, 'id')); // network-wide: the Member sees the published event

        // a student is NOT in the network — sees no events
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);
        $this->assertSame([], $this->getJson('/api/events')->assertOk()->json('events'));
        $this->app['auth']->forgetGuards();
    }

    public function test_rsvp_is_per_member_not_network_wide(): void
    {
        $eventId = $this->publishedEvent();
        $m1 = $this->member();
        $m2 = $this->member();

        Sanctum::actingAs($m1);
        $this->postJson("/api/events/{$eventId}/rsvp", ['status' => 'going'])->assertOk();
        $mine = $this->getJson('/api/my/rsvps')->assertOk()->json('rsvps');
        $this->app['auth']->forgetGuards();
        $this->assertCount(1, $mine);
        $this->assertSame($m1->id, (int) $mine[0]['member_id']);

        // R-Member watch: a DIFFERENT member does NOT see m1's rsvp (rsvp is per-member, not events-broad)
        Sanctum::actingAs($m2);
        $this->assertSame([], $this->getJson('/api/my/rsvps')->assertOk()->json('rsvps'));
        $this->app['auth']->forgetGuards();
    }

    public function test_directory_visible_to_members_and_hidden_from_others(): void
    {
        $m1 = $this->member();
        $m2 = $this->member();
        $this->setProfile($m1, 'Alice');
        $this->setProfile($m2, 'Bob');

        // a Member sees the members directory
        Sanctum::actingAs($m1);
        $names = array_column($this->getJson('/api/directory')->assertOk()->json('directory'), 'display_name');
        $this->app['auth']->forgetGuards();
        sort($names);
        $this->assertSame(['Alice', 'Bob'], $names);

        // a student / guardian / teacher sees NOTHING (member_directory is Member+academy only)
        foreach (['student', 'guardian', 'teacher'] as $role) {
            $u = User::factory()->create(['role' => $role]);
            Sanctum::actingAs($u);
            $this->assertSame([], $this->getJson('/api/directory')->assertOk()->json('directory'), "{$role} must not see the members directory");
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_member_has_no_enrolment_team_or_scoped_data_and_cannot_manage_events(): void
    {
        $this->publishedEvent();
        $member = $this->member();

        Sanctum::actingAs($member);
        // a Member matches no team/enrolment row (network-scoped, not link-scoped)
        $this->assertSame([], $this->getJson('/api/teams')->assertOk()->json('data'));
        // a Member cannot create events (not academy)
        $this->postJson('/api/admin/events', ['title_en' => 'X', 'title_tc' => 'X', 'title_sc' => 'X', 'starts_at' => '2026-12-01 10:00:00'])->assertStatus(403);
        $this->app['auth']->forgetGuards();
    }

    public function test_non_member_cannot_rsvp_or_set_a_profile(): void
    {
        $eventId = $this->publishedEvent();
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);
        $this->postJson("/api/events/{$eventId}/rsvp", ['status' => 'going'])->assertStatus(403); // role:member
        $this->putJson('/api/my/profile', ['display_name' => 'X'])->assertStatus(403);
        $this->app['auth']->forgetGuards();
    }
}
