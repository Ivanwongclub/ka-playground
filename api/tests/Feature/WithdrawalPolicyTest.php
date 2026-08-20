<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Programmes\WithdrawalPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WithdrawalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Programme $programme;

    private User $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->programme = Programme::query()->create([
            'code' => 'WD-2026', 'name_en' => 'WD', 'name_tc' => 'WD', 'name_sc' => 'WD',
            'jurisdiction' => 'HK', 'starts_at' => '2026-09-01T00:00:00+08:00',
        ]);
        $this->config = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $this->config->id,
            'capability' => 'configuration', 'granted_by' => $this->config->id, 'granted_at' => now(),
        ]);
    }

    private function putPolicy(array $payload): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->config);

        return $this->putJson("/api/admin/programmes/{$this->programme->id}/withdrawal-policy", array_merge([
            'full_refund_before' => '2026-08-01', 'no_refund_after' => '2026-09-01',
            'requires_approval' => true,
        ], $payload));
    }

    // ── The schema is the control (Leo item 2) ──

    public function test_overlapping_bands_equal_dates_rejected(): void
    {
        $this->putPolicy(['bands' => [
            ['until_date' => '2026-08-10', 'refund_pct' => 75],
            ['until_date' => '2026-08-10', 'refund_pct' => 50],
        ]])->assertStatus(422)->assertJsonPath('errors.bands.0', fn ($m) => str_contains($m, 'strictly increasing'));
    }

    public function test_unordered_bands_rejected(): void
    {
        $this->putPolicy(['bands' => [
            ['until_date' => '2026-08-20', 'refund_pct' => 75],
            ['until_date' => '2026-08-10', 'refund_pct' => 50],
        ]])->assertStatus(422)->assertJsonPath('errors.bands.0', fn ($m) => str_contains($m, 'unordered or overlapping'));
    }

    public function test_band_inside_full_refund_window_rejected(): void
    {
        $this->putPolicy(['bands' => [['until_date' => '2026-07-15', 'refund_pct' => 75]]])
            ->assertStatus(422)->assertJsonPath('errors.bands.0', fn ($m) => str_contains($m, 'full-refund window'));
    }

    public function test_band_beyond_no_refund_after_rejected(): void
    {
        $this->putPolicy(['bands' => [['until_date' => '2026-09-15', 'refund_pct' => 25]]])
            ->assertStatus(422)->assertJsonPath('errors.bands.0', fn ($m) => str_contains($m, 'beyond no_refund_after'));
    }

    public function test_refund_pct_out_of_bounds_rejected_at_api_and_db(): void
    {
        $this->putPolicy(['bands' => [['until_date' => '2026-08-10', 'refund_pct' => 101]]])->assertStatus(422);
        $this->putPolicy(['bands' => [['until_date' => '2026-08-10', 'refund_pct' => -5]]])->assertStatus(422);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('withdrawal_bands')->insert([
            'id' => (string) Str::uuid7(), 'programme_id' => $this->programme->id,
            'position' => 0, 'until_date' => now(), 'refund_pct' => 150,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_inverted_window_rejected(): void
    {
        $this->putPolicy(['full_refund_before' => '2026-09-01', 'no_refund_after' => '2026-08-01'])
            ->assertStatus(422);
    }

    public function test_valid_bands_compute_against_fixtures(): void
    {
        $this->putPolicy(['bands' => [
            ['until_date' => '2026-08-10', 'refund_pct' => 75],
            ['until_date' => '2026-08-20', 'refund_pct' => 50],
            ['until_date' => '2026-09-01', 'refund_pct' => 25],
        ]])->assertOk();

        $svc = app(WithdrawalPolicyService::class);
        $cases = [
            ['2026-07-20', 100], // before full_refund_before
            ['2026-08-05', 75],
            ['2026-08-15', 50],
            ['2026-08-25', 25],
            ['2026-09-10', 0],   // past no_refund_after
        ];
        foreach ($cases as [$date, $expected]) {
            $this->assertSame($expected, $svc->refundPctAt($this->programme->id, Carbon::parse($date)), "at {$date}");
        }
    }

    /**
     * FIX-REFUND-SEED — complete every required section. $startsOn null omits the start date entirely,
     * which also drops the OD-33 timeline to none-set (silent, not partial) — the exact shape that used to
     * publish happily and seed a NULL-window policy.
     */
    private function completeSections(?string $startsOn = '2027-02-01'): void
    {
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => 'placeholder-s03'],
                'basics' => $startsOn === null ? ['description' => 'x'] : ['enrolment_closes_on' => '2027-01-10', 'starts_on' => $startsOn],
                'team_rules' => $startsOn === null ? ['x' => 1] : ['formation_deadline_on' => '2027-01-20'],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
    }

    /** Publish seeds a REAL window from basics.starts_on — the assertion whose absence hid the defect. */
    public function test_publish_seeds_od2_provisional_policy_with_real_windows(): void
    {
        Sanctum::actingAs($this->config);
        $this->completeSections('2027-02-01');
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();

        $this->assertDatabaseHas('withdrawal_policies', [
            'programme_id' => $this->programme->id, 'requires_approval' => true, 'seeded_provisional' => true,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal_policy.seeded']);

        // the window is REAL, and it is the HKT midnight of the wizard's date — never NULL, never naive UTC
        $expected = Carbon::parse('2027-02-01', 'Asia/Hong_Kong')->startOfDay();
        $policy = DB::table('withdrawal_policies')->where('programme_id', $this->programme->id)->first();
        $this->assertNotNull($policy->full_refund_before, 'a provisional policy must never carry a NULL window');
        $this->assertNotNull($policy->no_refund_after);
        $this->assertTrue($expected->eq(Carbon::parse($policy->full_refund_before)), "full_refund_before {$policy->full_refund_before} != HKT midnight of 2027-02-01");
        $this->assertTrue($expected->eq(Carbon::parse($policy->no_refund_after)));

        // the column that had no writer now carries the wizard's date (AUDIT-2 A-1)
        $this->assertTrue($expected->eq(Carbon::parse($this->programme->fresh()->starts_at)));

        // and the consequence that matters: a withdrawal BEFORE the start refunds in full, not 0%
        $this->assertSame(100, app(WithdrawalPolicyService::class)->refundPctAt($this->programme->id, Carbon::parse('2027-01-15')));
    }

    /** No start date ⇒ publish REFUSES, atomically. A loud failure beats a silent 0%-forever policy. */
    public function test_publish_without_a_start_date_is_refused_and_seeds_nothing(): void
    {
        Sanctum::actingAs($this->config);
        $this->completeSections(null);

        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertStatus(422);

        // the whole transaction rolled back: still draft, no policy, no version snapshot, no seed audit
        $this->assertSame('draft', $this->programme->fresh()->status);
        $this->assertDatabaseMissing('withdrawal_policies', ['programme_id' => $this->programme->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'withdrawal_policy.seeded']);
        $this->assertSame(0, DB::table('programme_versions')->where('programme_id', $this->programme->id)->count());
        $this->assertNull($this->programme->fresh()->starts_at);
    }

    /** Pre-flight NAMES the missing date, so the block reads as a finding rather than an exception. */
    public function test_pre_flight_errors_on_a_missing_start_date(): void
    {
        Sanctum::actingAs($this->config);
        $this->completeSections(null);

        $result = $this->postJson("/api/admin/programmes/{$this->programme->id}/pre-flight")->assertOk()->json();
        $this->assertFalse($result['publishable']);
        $finding = collect($result['findings'])->firstWhere('code', 'basics.starts_on_missing');
        $this->assertNotNull($finding, 'the missing start date must be a NAMED pre-flight finding');
        $this->assertSame('error', $finding['severity']);

        // and with the date present it is gone
        $this->completeSections('2027-02-01');
        $after = $this->postJson("/api/admin/programmes/{$this->programme->id}/pre-flight")->assertOk()->json();
        $this->assertTrue($after['publishable']);
        $this->assertNull(collect($after['findings'])->firstWhere('code', 'basics.starts_on_missing'));
    }

    // ── Read set: the bound parties (stated pre-build) ──

    public function test_guardian_reads_published_terms_but_not_draft(): void
    {
        $this->putPolicy(['bands' => [['until_date' => '2026-08-10', 'refund_pct' => 75]]])->assertOk();
        $guardian = User::factory()->create(['role' => 'guardian']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        // Draft: invisible to the guardian
        $this->getJson("/api/programmes/{$this->programme->id}/withdrawal-policy")
            ->assertOk()->assertJsonPath('policy', null);

        $this->programme->update(['status' => 'published']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $response = $this->getJson("/api/programmes/{$this->programme->id}/withdrawal-policy")->assertOk();
        $this->assertNotNull($response->json('policy'), 'a guardian must be able to read the published terms they will be bound by');
        $this->assertCount(1, $response->json('bands'));
    }

    public function test_member_reads_nothing_even_when_published(): void
    {
        $this->putPolicy([])->assertOk();
        $this->programme->update(['status' => 'published']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->getJson("/api/programmes/{$this->programme->id}/withdrawal-policy")
            ->assertOk()->assertJsonPath('policy', null);
    }
}
