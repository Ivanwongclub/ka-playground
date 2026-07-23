<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThrottlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('auth');
    }

    public function test_auth_endpoint_allows_5_per_minute_per_ip_then_429(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => "probe{$i}@example.test", 'password' => 'x'])
                ->assertStatus(422); // unknown email — but the attempt counts
        }

        $this->postJson('/api/auth/login', ['email' => 'probe6@example.test', 'password' => 'x'])
            ->assertStatus(429);
    }

    public function test_api_default_allows_60_per_minute_per_user_then_429(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));

        for ($i = 1; $i <= 60; $i++) {
            $this->getJson('/api/enrolments')->assertStatus(501);
        }

        $this->getJson('/api/enrolments')->assertStatus(429);
    }

    public function test_pairing_limiter_is_registered_at_5_per_hour(): void
    {
        // Consumed by the step-5 redemption endpoint; registration is testable now
        $limiter = RateLimiter::limiter('pairing');
        $this->assertNotNull($limiter, 'pairing limiter must be registered (2.13)');

        $request = \Illuminate\Http\Request::create('/probe');
        $limit = $limiter($request);
        $this->assertSame(5, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds / 60); // per hour
    }
}
