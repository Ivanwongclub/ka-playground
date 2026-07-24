<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditService;
use App\Services\Authz\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `permission:finance.view`. Server-side enforcement, never UI
 * hiding. 401 without an authenticated user; 403 when the effective permission
 * set (role + capabilities, B7/OD-17) does not carry the required key.
 */
class EnsurePermission
{
    public function __construct(
        private readonly PermissionResolver $resolver,
        private readonly AuditService $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        if (! $this->resolver->allows($user, $permission)) {
            // A denial is a security event — audited (Leo review, 24 Jul)
            $this->audit->record(
                'user', (string) $user->getAuthIdentifier(), 'permission.denied',
                reason: "missing permission: {$permission}",
                payloadAfter: ['permission' => $permission, 'path' => $request->path()],
                actor: $user,
            );
            abort(403, "Missing permission: {$permission}");
        }

        return $next($request);
    }
}
