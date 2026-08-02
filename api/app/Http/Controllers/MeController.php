<?php

namespace App\Http\Controllers;

use App\Services\Authz\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S-UX1: canonical current-identity endpoint. The SPA persists only the bearer
 * token, so on load/refresh it has no idea who is signed in — this returns the
 * caller's identity plus their EFFECTIVE PERMISSION SET (role defaults + capability
 * permissions, B7/OD-17). The client drives role-aware nav and the user menu from
 * this one source. Read-only, own identity only, not audited. Nav-hiding built on
 * these permissions is UX only; every endpoint keeps its own server-side gate.
 */
class MeController extends Controller
{
    public function show(Request $request, PermissionResolver $resolver): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'permissions' => $resolver->effectivePermissions($user),
        ]);
    }
}
