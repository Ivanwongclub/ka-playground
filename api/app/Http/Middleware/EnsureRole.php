<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route guard on the single unstacked role (Spec B1): `role:guardian`. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        if ($user->role !== $role) {
            abort(403, "Requires role: {$role}");
        }

        return $next($request);
    }
}
