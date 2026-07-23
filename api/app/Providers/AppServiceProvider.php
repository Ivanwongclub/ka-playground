<?php

namespace App\Providers;

use App\Services\Uploads\ClamAvScanner;
use App\Services\Uploads\VirusScanner;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
