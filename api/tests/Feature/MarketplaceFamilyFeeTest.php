<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-READ-3 items 2+3 — the storefront's published price and enrolment-open date.
 *
 * The price is served to AUTHENTICATED FAMILY callers only (ruling F-3): the anonymous payload carries no
 * money field, which is what keeps `payment_links.single_reader` true in BOTH senses — its mechanical checks
 * pass AND the sentence they assert ("the token-resolution path is the ONLY unauthenticated reader of any
 * payment data") remains factually correct. That is the point of these tests, not a side effect.
 *
 * F-4 is proved here too, and it is a real constraint rather than a nicety: asSystem writes one INSERT-only
 * audit_events row per CALL, so the elevation must be ONE query for all listable programmes — never one per
 * programme — and must not fire at all for anonymous traffic.
 */
class MarketplaceFamilyFeeTest extends TestCase
{
    use RefreshDatabase;

    private const MK_COMPLETE = [
        'tagline' => ['en' => 'Build robots', 'tc' => '製造機械人', 'sc' => '制造机械人'],
        'category' => ['en' => 'STEM', 'tc' => '理工', 'sc' => '理工'],
        'age_range' => ['en' => '8–12', 'tc' => '8至12歲', 'sc' => '8至12岁'],
        'duration' => ['en' => '10 weeks', 'tc' => '10週', 'sc' => '10周'],
        'brand_color' => '#7A3B57',
    ];

    private int $paid;   // two fee items → 2500_00 + 300_00

    private int $free;   // published, marketing-complete, ZERO fee items

    protected function setUp(): void
    {
        parent::setUp();
        $this->paid = $this->mkProgramme('2026-05-01 09:00:00+00');
        $this->free = $this->mkProgramme(null);
        $this->feeItem($this->paid, 'Programme fee', 250000);
        $this->feeItem($this->paid, 'Materials', 30000);
    }

    private function mkProgramme(?string $opensAt): int
    {
        $pid = DB::table('programmes')->insertGetId([
            'code' => 'MP'.Str::upper(Str::random(6)), 'name_en' => 'Prog EN', 'name_tc' => '課程', 'name_sc' => '课程',
            'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'is_template' => false,
            'enrolment_opens_at' => $opensAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['basics', ['starts_on' => '2099-01-01', 'enrolment_closes_on' => '2098-12-01']], ['marketing', self::MK_COMPLETE]] as [$key, $data]) {
            DB::table('wizard_sections')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $pid,
                'section_key' => $key, 'status' => 'complete', 'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $pid;
    }

    private function feeItem(int $programmeId, string $name, int $minor): void
    {
        DB::table('fee_items')->insert([
            'id' => (string) Str::uuid7(), 'programme_id' => $programmeId, 'name_en' => $name,
            'name_tc' => $name, 'name_sc' => $name, 'amount_minor' => $minor, 'currency' => 'HKD',
            'sort' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return collect($this->getJson('/api/programmes')->assertOk()->json('data'))->firstWhere('id', $id);
    }

    private function elevationCount(): int
    {
        return DB::table('audit_events')->where('action', 'scope.elevated')
            ->where('entity_id', 'App\Http\Controllers\MarketplaceController::withFeeTotals')->count();
    }

    // ── item 2: who sees a price ─────────────────────────────────────────────────────────────────────

    public function test_anonymous_payload_carries_no_money_field_at_all(): void
    {
        $body = $this->getJson('/api/programmes')->assertOk()->getContent();
        $this->assertArrayNotHasKey('fee_total_minor', $this->row($this->paid));
        $this->assertArrayNotHasKey('currency', $this->row($this->paid));
        $this->assertStringNotContainsString('fee_total_minor', $body);
        $this->assertStringNotContainsString('250000', $body);
    }

    public function test_anonymous_read_elevates_nothing(): void
    {
        // F-4: no elevation ⇒ no audit row. Anonymous storefront traffic must not write to audit_events.
        $this->getJson('/api/programmes')->assertOk();
        $this->getJson("/api/programmes/{$this->paid}")->assertOk();
        $this->assertSame(0, $this->elevationCount());
    }

    public function test_guardian_receives_the_summed_fee(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $row = $this->row($this->paid);
        // The SUM of both fee items — exactly what OrderService charges as orders.total_amount_minor.
        $this->assertSame(280000, $row['fee_total_minor']);
        $this->assertSame('HKD', $row['currency']);
    }

    public function test_student_receives_it_too_because_a_list_price_is_not_an_order_amount(): void
    {
        // FAMILY = student + guardian, per the ruling. NOT the P-3/B-18 case: that rule keeps an enrolment's
        // ORDER AMOUNT — one family's obligation on a shared column — off a read a student receives. A
        // published catalogue LIST PRICE is marketing, identical for every viewer, and the prototype puts it
        // on the student's own explore card. FLAGGED for confirmation; narrowing it is a one-line change.
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->assertSame(280000, $this->row($this->paid)['fee_total_minor']);
    }

    public function test_non_family_authenticated_roles_get_no_fee(): void
    {
        foreach (['teacher', 'school_admin', 'member', 'academy_admin'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->assertArrayNotHasKey('fee_total_minor', $this->row($this->paid), "{$role} received a fee");
        }
        $this->assertSame(0, $this->elevationCount()); // and none of them elevated
    }

    public function test_a_programme_with_no_fee_items_carries_no_field_never_zero(): void
    {
        // Publish only requires the wizard's has_fee_items boolean, so this state is reachable — and a
        // fabricated HK$0.00 would be a false price on a public storefront.
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $row = $this->row($this->free);
        $this->assertArrayNotHasKey('fee_total_minor', $row);
        $this->assertArrayNotHasKey('currency', $row);
    }

    public function test_one_elevation_per_request_not_one_per_programme(): void
    {
        // F-4, the whole reason for the shape: TWO listable programmes, ONE audit row.
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->getJson('/api/programmes')->assertOk();
        $this->assertSame(1, $this->elevationCount());
        $this->getJson('/api/programmes')->assertOk();
        $this->assertSame(2, $this->elevationCount()); // one per request, and only one
    }

    public function test_the_detail_read_is_bounded_the_same_way(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $r = $this->getJson("/api/programmes/{$this->paid}")->assertOk();
        $this->assertSame(280000, $r->json('fee_total_minor'));
        $this->assertSame(1, $this->elevationCount());
    }

    // ── item 3: enrolment_opens_at ───────────────────────────────────────────────────────────────────

    public function test_enrolment_opens_at_is_served_from_the_column_for_everyone(): void
    {
        $row = $this->row($this->paid);
        $this->assertArrayHasKey('enrolment_opens_at', $row);
        $this->assertStringStartsWith('2026-05-01', $row['enrolment_opens_at']);
        // Same source as the derived chip, so the two can never contradict each other.
        $this->assertSame('open', $row['status']);
        $this->assertNull($this->row($this->free)['enrolment_opens_at']); // no window set ⇒ null, not invented
    }

    public function test_a_future_window_reads_as_closed_and_carries_its_open_date(): void
    {
        // This is the pair the "Coming soon" filter needs: status closed + a real date to show.
        $soon = $this->mkProgramme('2099-06-01 09:00:00+00');
        $row = $this->row($soon);
        $this->assertSame('closed', $row['status']);
        $this->assertStringStartsWith('2099-06-01', $row['enrolment_opens_at']);
    }
}
