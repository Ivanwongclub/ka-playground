<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi(); // 2.13: API default 60/min/user (limiter in AppServiceProvider)
        // FR006: RLS session context — set from the authenticated user, reset in
        // terminate. Structural: applies to every api route, no opt-out.
        $middleware->api(append: [\App\Http\Middleware\SetScopeContext::class]);
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
