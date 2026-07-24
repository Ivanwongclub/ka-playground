<?php

namespace App\Http\Middleware;

use App\Services\Authz\ScopeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP leg of the structural lifecycle: context from the AUTHENTICATED USER
 * only ($request->user() via Sanctum) — request input has no path into it.
 * Reset in terminate() so the connection never leaves a request carrying it.
 */
class SetScopeContext
{
    public function __construct(private readonly ScopeContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->reset();
        $user = $request->user(); // lazy Sanctum resolution; null stays fail-closed
        if ($user !== null) {
            $this->context->set($user);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->reset();
    }
}
