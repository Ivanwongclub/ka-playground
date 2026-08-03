<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-UX3-9 — guardian/teacher self-service. Proves the teacher roll boundary (own school only,
 * cross-school denial, tight allowlist, no elevation) and the guardian money boundary (own family
 * only, token_hash never, mint mints-not-pays).
 */
class SelfServiceUxTest extends TestCase
{
    use RefreshDatabase;

    private function sys(callable $fn): mixed
    {
        $scope = app(ScopeContext::class);
        $scope->setSystem();
        try {
            return $fn();
        } finally {
            $scope->reset();
        }
    }

    private function schoolStudent(string $name, int $schoolId): User
    {
        $u = User::factory()->create(['role' => 'student', 'name' => $name]);
        $this->sys(fn () => DB::table('school_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $u->id, 'school_id' => $schoolId,
            'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $u;
    }

    private function orderFor(User $student, string $status, string $payer = 'guardian', ?int $payerSchoolId = null): string
    {
        return $this->sys(function () use ($student, $status, $payer, $payerSchoolId): string {
            $programme = Programme::query()->create(['code' => 'SS-'.Str::upper(Str::random(6)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
            $enrolmentId = (string) Str::uuid7();
            DB::table('enrolments')->insert([
                'id' => $enrolmentId, 'programme_id' => $programme->id, 'student_id' => $student->id,
                'acting_guardian_id' => $student->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $orderId = (string) Str::uuid7();
            DB::table('orders')->insert([
                'id' => $orderId, 'enrolment_id' => $enrolmentId, 'programme_id' => $programme->id,
                'student_id' => $student->id, 'payer_party' => $payer,
                'payer_school_id' => $payer === 'school' ? $payerSchoolId : null, // ord_school_payer_check
                'total_amount_minor' => 250000,
                'currency' => 'HKD', 'status' => $status, 'payment_due_at' => now()->addDays(7),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $orderId;
        });
    }

    private function guardianOf(User $child): User
    {
        $g = User::factory()->create(['role' => 'guardian']);
        $this->sys(fn () => DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'guardian_id' => $g->id, 'student_id' => $child->id,
            'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $g;
    }

    // ── Test 1 — teacher My Students: own school roll only, tight allowlist, cross-school denial ─────────
    public function test_teacher_sees_own_school_roll_only_with_tight_allowlist(): void
    {
        $schoolA = School::query()->create(['name_en' => 'A', 'name_tc' => 'A', 'name_sc' => 'A']);
        $schoolB = School::query()->create(['name_en' => 'B', 'name_tc' => 'B', 'name_sc' => 'B']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->sys(fn () => DB::table('teacher_links')->insert([
            'id' => (string) Str::uuid7(), 'teacher_id' => $teacher->id, 'school_id' => $schoolA->id,
            'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $mine1 = $this->schoolStudent('Amy Ateam', $schoolA->id);
        $mine2 = $this->schoolStudent('Ben Ateam', $schoolA->id);
        $other = $this->schoolStudent('Zoe Bschool', $schoolB->id);

        Sanctum::actingAs($teacher);
        $body = $this->getJson('/api/teacher/students')->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $names = array_column($body['data'], 'student_name');
        $this->assertContains('Amy Ateam', $names);
        $this->assertContains('Ben Ateam', $names);
        $this->assertNotContains('Zoe Bschool', $names); // cross-school child-privacy: another school DENIED

        // exact key-allowlist per row
        $this->assertEqualsCanonicalizing(['student_id', 'student_name'], array_keys($body['data'][0]));
        // forbidden-field sweep — no guardian/consent/enrolment/money/email leak
        $blob = json_encode($body);
        $this->assertStringNotContainsStringIgnoringCase($mine1->email, $blob);
        foreach (['guardian', 'consent', 'enrolment', 'order', 'amount', 'school_id', 'email'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $blob, "leaked: {$forbidden}");
        }
        // student SB's id/name never present (belt-and-suspenders on the denial)
        $this->assertNotContains($other->id, array_column($body['data'], 'student_id'));
    }

    public function test_teacher_students_read_adds_no_elevation(): void
    {
        $allow = array_keys(config('scope-elevations'));
        $this->assertNotContains('App\Http\Controllers\TeacherStudentsController::index', $allow);
    }

    public function test_a_teacher_of_another_school_sees_a_disjoint_roll(): void
    {
        $schoolA = School::query()->create(['name_en' => 'A', 'name_tc' => 'A', 'name_sc' => 'A']);
        $schoolB = School::query()->create(['name_en' => 'B', 'name_tc' => 'B', 'name_sc' => 'B']);
        $teacherB = User::factory()->create(['role' => 'teacher']);
        $this->sys(fn () => DB::table('teacher_links')->insert([
            'id' => (string) Str::uuid7(), 'teacher_id' => $teacherB->id, 'school_id' => $schoolB->id,
            'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->schoolStudent('Amy Ateam', $schoolA->id);
        $bStudent = $this->schoolStudent('Zoe Bschool', $schoolB->id);

        Sanctum::actingAs($teacherB);
        $names = array_column($this->getJson('/api/teacher/students')->assertOk()->json('data'), 'student_name');
        $this->app['auth']->forgetGuards();

        $this->assertContains('Zoe Bschool', $names);
        $this->assertNotContains('Amy Ateam', $names); // school B's teacher never sees school A's roll
    }

    // ── Test 2 — guardian money reads: own family only, token_hash never, school-payer nuance ───────────
    public function test_guardian_sees_own_family_orders_only_and_never_a_token_hash(): void
    {
        $school = School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S']);
        $child = User::factory()->create(['role' => 'student', 'name' => 'My Kid']);
        $guardian = $this->guardianOf($child);
        $familyOrder = $this->orderFor($child, 'issued', 'guardian');
        $schoolOrder = $this->orderFor($child, 'issued', 'school', $school->id); // school-payer FOR my child

        $otherChild = User::factory()->create(['role' => 'student', 'name' => 'Other Kid']);
        $this->guardianOf($otherChild);
        $otherOrder = $this->orderFor($otherChild, 'issued', 'guardian'); // another family

        Sanctum::actingAs($guardian);
        $orders = $this->getJson('/api/orders')->assertOk()->json('data');
        $links = $this->getJson('/api/my/payment-links')->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $ids = array_column($orders, 'id');
        $this->assertContains($familyOrder, $ids);        // own child's family order — visible
        $this->assertContains($schoolOrder, $ids);        // HONEST: familyRead keys on student_id, so a
                                                          // school-payer order FOR my child IS RLS-visible.
                                                          // "school-payer excluded" is a My Payments DISPLAY
                                                          // filter (payer_party in guardian/student), NOT RLS.
        $this->assertNotContains($otherOrder, $ids);      // another family — absent (the real privacy line)

        // token_hash NEVER leaves — not in the orders read nor the links read
        $this->assertStringNotContainsStringIgnoringCase('token_hash', json_encode($orders));
        $this->assertStringNotContainsStringIgnoringCase('token', json_encode($links));
    }

    // ── Test 3 — mint mints a link, does not move money; refuses a non-issued order ─────────────────────
    public function test_mint_returns_a_link_and_does_not_move_money(): void
    {
        $child = User::factory()->create(['role' => 'student', 'name' => 'Payable Kid']);
        $guardian = $this->guardianOf($child);
        $order = $this->orderFor($child, 'issued', 'guardian');

        Sanctum::actingAs($guardian);
        $minted = $this->postJson("/api/my/orders/{$order}/payment-link")->assertStatus(201)->json();
        $this->app['auth']->forgetGuards();

        $this->assertArrayHasKey('url', $minted);              // a forwardable /pay link, NOT a payment
        $this->assertStringContainsString('/pay/', $minted['url']);
        $this->sys(function () use ($order): void {
            $this->assertSame('issued', DB::table('orders')->where('id', $order)->value('status')); // money NOT moved
            $this->assertSame(0, DB::table('receipts')->where('order_id', $order)->count());          // no receipt
        });
    }

    public function test_mint_refuses_a_non_issued_order(): void
    {
        $child = User::factory()->create(['role' => 'student', 'name' => 'Paid Kid']);
        $guardian = $this->guardianOf($child);
        $paid = $this->orderFor($child, 'paid', 'guardian');

        Sanctum::actingAs($guardian);
        // shown-not-hidden: the server refuses with a rendered message (422 validation)
        $this->postJson("/api/my/orders/{$paid}/payment-link")->assertStatus(422)
            ->assertJsonFragment(['order' => ['Order is paid — nothing to pay']]);
        $this->app['auth']->forgetGuards();
    }
}
