<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S04C gate — five-branch RLS isolation on the new scoped tables. These carry
 * pre-account personal data (registration_requests) and the most misleadable row
 * in the system (held_links), so the read set is proven at the policy layer:
 * routed-school admin sees its own, another school sees zero, academy ops sees
 * all (incl. direct), families/Members see zero, and anonymous sees zero.
 */
class RegistrationRlsTest extends TestCase
{
    use RefreshDatabase;

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->reset();
        }
    }

    /** Count rows a given actor can SELECT (RLS applies to the count). */
    private function countAs(User $u, string $table): int
    {
        $s = app(ScopeContext::class);
        $s->set($u);
        try {
            return (int) DB::table($table)->count();
        } finally {
            $s->reset();
        }
    }

    private function countAnon(string $table): array
    {
        $s = app(ScopeContext::class);
        $s->setPublic();
        $pub = (int) DB::table($table)->count();
        $s->reset();
        $empty = (int) DB::table($table)->count(); // no context at all
        $s->reset();

        return ['public' => $pub, 'empty' => $empty];
    }

    private function schoolAdmin(int $schoolId): User
    {
        $u = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $u->id, 'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        return $u;
    }

    private function ops(): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        $this->sys(fn () => DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => 'operations', 'granted_by' => $u->id, 'granted_at' => now()]));

        return $u;
    }

    public function test_five_branch_isolation_on_registration_requests(): void
    {
        $a = School::query()->create(['name_en' => 'A', 'name_tc' => '甲', 'name_sc' => '甲']);
        $b = School::query()->create(['name_en' => 'B', 'name_tc' => '乙', 'name_sc' => '乙']);
        $adminA = $this->schoolAdmin($a->id);
        $adminB = $this->schoolAdmin($b->id);
        $ops = $this->ops();

        $this->sys(fn () => DB::table('registration_requests')->insert([
            ['id' => (string) Str::uuid7(), 'kind' => 'student', 'applicant_name' => 'S', 'applicant_email' => 's@ex.com', 'preferred_language' => 'en', 'routing' => 'school', 'school_id' => $a->id, 'status' => 'submitted', 'reference' => Str::random(10), 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid7(), 'kind' => 'guardian', 'applicant_name' => 'D', 'applicant_email' => 'd@ex.com', 'preferred_language' => 'en', 'routing' => 'academy', 'school_id' => null, 'status' => 'submitted', 'reference' => Str::random(10), 'created_at' => now(), 'updated_at' => now()],
        ]));

        $this->assertSame(1, $this->countAs($adminA, 'registration_requests'), '[1] routed-school admin sees its own school-routed request');
        $this->assertSame(0, $this->countAs($adminB, 'registration_requests'), '[2] another school sees zero');
        $this->assertSame(2, $this->countAs($ops, 'registration_requests'), '[3] academy ops sees all (incl. the direct one)');
        $this->assertSame(0, $this->countAs(User::factory()->create(['role' => 'guardian']), 'registration_requests'), '[4a] guardian zero');
        $this->assertSame(0, $this->countAs(User::factory()->create(['role' => 'student']), 'registration_requests'), '[4b] student zero');
        $this->assertSame(0, $this->countAs(User::factory()->create(['role' => 'member']), 'registration_requests'), '[4c] Member zero');
        $this->assertSame(['public' => 0, 'empty' => 0], $this->countAnon('registration_requests'), '[5] anonymous zero (public + empty context)');
    }

    public function test_five_branch_isolation_on_held_links(): void
    {
        $a = School::query()->create(['name_en' => 'A', 'name_tc' => '甲', 'name_sc' => '甲']);
        $b = School::query()->create(['name_en' => 'B', 'name_tc' => '乙', 'name_sc' => '乙']);
        $adminA = $this->schoolAdmin($a->id);
        $adminB = $this->schoolAdmin($b->id);
        $ops = $this->ops();
        $claimant = $this->sys(fn () => User::factory()->create(['role' => 'guardian']));

        $this->sys(fn () => DB::table('held_links')->insert([
            'id' => (string) Str::uuid7(), 'claimant_id' => $claimant->id, 'claimant_role' => 'guardian',
            'counterpart_email' => 'x@ex.com', 'school_id' => $a->id, 'status' => 'held', 'origin' => 'form_claimed',
            'expires_at' => now()->addDays(90), 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->assertSame(1, $this->countAs($adminA, 'held_links'), '[1] routed-school admin sees its own');
        $this->assertSame(0, $this->countAs($adminB, 'held_links'), '[2] another school zero');
        $this->assertSame(1, $this->countAs($ops, 'held_links'), '[3] academy ops sees it');
        $this->assertSame(0, $this->countAs($claimant, 'held_links'), '[4a] the claimant themselves cannot read the held claim (reviewer set only)');
        $this->assertSame(0, $this->countAs(User::factory()->create(['role' => 'member']), 'held_links'), '[4b] Member zero');
        $this->assertSame(['public' => 0, 'empty' => 0], $this->countAnon('held_links'), '[5] anonymous zero');
    }

    public function test_five_branch_isolation_on_school_links_affiliation(): void
    {
        // the D-i affiliation row: the student reads their own (→ Active), the
        // routed school sees it, another school + Member see zero, anon zero.
        $a = School::query()->create(['name_en' => 'A', 'name_tc' => '甲', 'name_sc' => '甲']);
        $b = School::query()->create(['name_en' => 'B', 'name_tc' => '乙', 'name_sc' => '乙']);
        $adminA = $this->schoolAdmin($a->id);
        $adminB = $this->schoolAdmin($b->id);
        $ops = $this->ops();
        $student = $this->sys(fn () => User::factory()->create(['role' => 'student']));
        $this->sys(fn () => DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $a->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        $this->assertSame(1, $this->countAs($student, 'school_links'), '[1] the student reads their own affiliation (the active link → Active)');
        $this->assertSame(1, $this->countAs($adminA, 'school_links'), '[2] the routed school sees it');
        $this->assertSame(0, $this->countAs($adminB, 'school_links'), '[3] another school zero');
        $this->assertSame(1, $this->countAs($ops, 'school_links'), '[4] academy ops sees it');
        $this->assertSame(0, $this->countAs(User::factory()->create(['role' => 'member']), 'school_links'), '[5a] Member zero');
        $this->assertSame(['public' => 0, 'empty' => 0], $this->countAnon('school_links'), '[5b] anonymous zero');
    }
}
