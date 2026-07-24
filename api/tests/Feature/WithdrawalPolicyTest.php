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

    public function test_publish_seeds_od2_provisional_policy(): void
    {
        Sanctum::actingAs($this->config);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => 'placeholder-s03'],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$key}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();

        $this->assertDatabaseHas('withdrawal_policies', [
            'programme_id' => $this->programme->id, 'requires_approval' => true, 'seeded_provisional' => true,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal_policy.seeded']);
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
