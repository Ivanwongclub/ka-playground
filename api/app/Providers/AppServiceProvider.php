<?php

namespace App\Providers;

use App\Services\Identity\AuthService;
use App\Services\Uploads\ClamAvScanner;
use App\Services\Uploads\VirusScanner;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
