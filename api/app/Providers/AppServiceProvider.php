<?php

namespace App\Providers;

use App\Services\Identity\AuthService;
use App\Services\Identity\EnrolmentStatusPort;
use App\Services\Identity\NoEnrolmentsYet;
use App\Services\Uploads\ClamAvScanner;
use App\Services\Uploads\VirusScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\Money\PaymentProvider::class, fn () => new \App\Services\Money\MockProvider); // OD-46: QFPay adapter replaces this in S-QFPAY
        // 2.2 continuity condition: real adapter arrives with enrolments (S04A)
        $this->app->bind(EnrolmentStatusPort::class, NoEnrolmentsYet::class);
        $this->app->bind(VirusScanner::class, fn (): VirusScanner => new ClamAvScanner(
            host: config('uploads.clamav.host'),
            port: config('uploads.clamav.port'),
            timeoutSeconds: config('uploads.clamav.timeout_seconds'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Throttling (2.13): auth 5/min/IP · API default 60/min/user ·
        // pairing codes 5/hour/account (consumed by the step-5 redemption flow;
        // the 10-global-fails hard invalidation is data-level in that flow)
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?? $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('payment-link', fn (Request $r) => Limit::perMinute(10)->by('paylink:'.$r->ip()));
        // S04C: the anonymous registration surface (first anonymous write), per-IP.
        RateLimiter::for('registration', fn (Request $r) => Limit::perMinute(10)->by('register:'.$r->ip()));
        RateLimiter::for('pairing', fn (Request $r) => Limit::perHour(5)->by('pairing:'.($r->user()?->id ?? $r->ip())));

        // Session policy (2.11): 12h IDLE expiry (last_used_at), remember-me 30d.
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid): bool {
            if (! $isValid) {
                return false;
            }
            $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;
            $limit = $accessToken->name === 'remember'
                ? now()->subDays(AuthService::REMEMBER_DAYS)
                : now()->subHours(AuthService::IDLE_HOURS);

            return $lastActivity->greaterThan($limit);
        });
    }
}
