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
        // Queue workers (incl. Horizon): every job RUNS as system; the boundary
        // restores the surrounding context (empty in a worker → stays scrubbed;
        // the requester's context when the sync driver runs a job inline —
        // S03-3 repair: a blind reset was wiping the rest of the request).
        Queue::before(fn () => $this->app->make(ScopeContext::class)->beginJob());
        Queue::after(fn () => $this->app->make(ScopeContext::class)->endJob());
        Queue::failing(fn () => $this->app->make(ScopeContext::class)->endJob());

        // Console + scheduler (incl. reconcile:run): system context.
        Event::listen(CommandStarting::class, function (): void {
            $ctx = $this->app->make(ScopeContext::class);
            $ctx->reset();
            $ctx->setSystem();
        });
    }
}
