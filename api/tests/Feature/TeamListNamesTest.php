<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-UX3-3a STEP 2 — Backend delta B1: additive names + member count on GET /teams (S-UX2b).
 * Proves (T1) the names ride ADDITIVELY — every prior key intact, plus programme/category/created_by
 * names and member_count; (T2) the LEFT JOINs are COUNT-PRESERVING under a hiding users_read — a team
 * whose creator the caller may not see keeps its row with created_by_name = NULL (never the raw id).
 */
class TeamListNamesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $caps): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        foreach ($caps as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => $c, 'granted_by' => $u->id, 'granted_at' => now()]);
        }

        return $u;
    }

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->setSystem();
        }
    }

    private function act(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    public function test_team_list_carries_additive_names_and_member_count(): void
    {
        $ops = $this->admin(['operations']);
        $creator = User::factory()->create(['role' => 'student', 'name' => 'Captain Chan']);

        [$programmeId, $lobby] = $this->sys(function () {
            $programmeId = DB::table('programmes')->insertGetId(['code' => 'T'.Str::random(4), 'name_en' => 'Robotics EN', 'name_tc' => '機械人', 'name_sc' => '机械人', 'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
            $lobby = (string) Str::uuid7();
            DB::table('team_categories')->insert(['id' => $lobby, 'programme_id' => $programmeId, 'name_en' => 'Open Lobby', 'name_tc' => '開放大堂', 'name_sc' => '开放大堂', 'assignment_rule' => 'open', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

            return [$programmeId, $lobby];
        });

        $teamId = (string) Str::uuid7();
        $this->sys(function () use ($teamId, $programmeId, $lobby, $creator) {
            DB::table('teams')->insert(['id' => $teamId, 'programme_id' => $programmeId, 'category_id' => $lobby, 'name' => 'Alpha', 'status' => 'submitted', 'created_by' => $creator->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['student', 'student'] as $i => $_) {
                $s = User::factory()->create(['role' => 'student']);
                $g = User::factory()->create(['role' => 'guardian']);
                $e = (string) Str::uuid7();
                DB::table('enrolments')->insert(['id' => $e, 'programme_id' => $programmeId, 'student_id' => $s->id, 'acting_guardian_id' => $g->id, 'status' => 'teamed', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'team_id' => $teamId, 'programme_id' => $programmeId, 'enrolment_id' => $e, 'category_id' => $lobby, 'student_id' => $s->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->act($ops);
        $row = collect($this->getJson('/api/teams')->assertOk()->json('data'))->firstWhere('id', $teamId);

        // (T2) all prior keys intact
        foreach (['id', 'programme_id', 'category_id', 'name', 'status', 'created_by'] as $k) {
            $this->assertArrayHasKey($k, $row);
        }
        // additive names
        $this->assertSame('Robotics EN', $row['programme_name_en']);
        $this->assertSame('機械人', $row['programme_name_tc']);
        $this->assertSame('机械人', $row['programme_name_sc']);
        $this->assertSame('Open Lobby', $row['category_name_en']);
        $this->assertSame('開放大堂', $row['category_name_tc']);
        $this->assertSame('Captain Chan', $row['created_by_name']);
        // additive member count
        $this->assertSame(2, $row['member_count']);
    }

    public function test_left_joins_are_count_preserving_when_users_read_hides_the_creator(): void
    {
        // A lobby school-admin sees a team in their lobby, created by a student of a DIFFERENT school
        // (users_read admits only their own school's students). The team row must survive with a NULL
        // created_by_name — the LEFT join never drops it, and the raw id is never surfaced as a name.
        $schoolA = School::query()->create(['name_en' => 'A', 'name_tc' => 'A', 'name_sc' => 'A']);
        $lobbyAdmin = User::factory()->create(['role' => 'school_admin']);
        $crossSchoolCreator = User::factory()->create(['role' => 'student', 'name' => 'Hidden Creator']);

        $teamId = (string) Str::uuid7();
        $this->sys(function () use ($schoolA, $lobbyAdmin, $crossSchoolCreator, $teamId) {
            DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $lobbyAdmin->id, 'school_id' => $schoolA->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $programmeId = DB::table('programmes')->insertGetId(['code' => 'T'.Str::random(4), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
            $lobby = (string) Str::uuid7();
            // the team's lobby is bound to schoolA → the school-admin sees the team via teams_read
            DB::table('team_categories')->insert(['id' => $lobby, 'programme_id' => $programmeId, 'name_en' => 'Lobby', 'name_tc' => 'Lobby', 'name_sc' => 'Lobby', 'assignment_rule' => 'auto_by_school', 'school_id' => $schoolA->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('teams')->insert(['id' => $teamId, 'programme_id' => $programmeId, 'category_id' => $lobby, 'name' => 'Beta', 'status' => 'submitted', 'created_by' => $crossSchoolCreator->id, 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->act($lobbyAdmin);
        $row = collect($this->getJson('/api/teams')->assertOk()->json('data'))->firstWhere('id', $teamId);

        $this->assertNotNull($row, 'the LEFT join must not drop the team even when the creator is hidden');
        $this->assertNull($row['created_by_name'], 'a hidden creator resolves to NULL, never the raw id');
        // the raw id must never masquerade as the name
        $this->assertNotSame((string) $crossSchoolCreator->id, (string) $row['created_by_name']);
    }
}
