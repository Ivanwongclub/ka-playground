<?php

namespace App\Providers;

use App\Services\Authz\ScopeContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * The structural context lifecycle (Leo, S02A pre-step-3): registered ONCE
 * here — no per-job, per-command or per-callsite discipline anywhere.
 * A long-lived Horizon worker connection is scrubbed around EVERY job, so
 * stale context from a previous user or job is impossible.
 */
class ScopeContextServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScopeContext::class);
    }

    public function boot(): void
    {
        // Queue workers (incl. Horizon): reset → system before every job;
        // reset after and on failure. Jobs run as platform, never as a user.
        Queue::before(function (): void {
            $ctx = $this->app->make(ScopeContext::class);
            $ctx->reset();
            $ctx->setSystem();
        });
        Queue::after(fn () => $this->app->make(ScopeContext::class)->reset());
        Queue::failing(fn () => $this->app->make(ScopeContext::class)->reset());

        // Console + scheduler (incl. reconcile:run): system context.
        Event::listen(CommandStarting::class, function (): void {
            $ctx = $this->app->make(ScopeContext::class);
            $ctx->reset();
            $ctx->setSystem();
        });
    }
}
