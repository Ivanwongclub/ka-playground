<?php

namespace App\Services\Authz;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Effective permissions per Spec B7: role defaults, plus capability-group
 * permissions for Academy Administrators (OD-17). Per-link overrides (layer 2)
 * are applied by the link-scoped queries that arrive with the link entities.
 */
class PermissionResolver
{
    /** @return list<string> */
    public function effectivePermissions(User $user): array
    {
        $rolePermissions = DB::table('role_permissions')
            ->where('role_key', $user->role)
            ->pluck('permission_key');

        $capabilityPermissions = collect();
        if ($user->role === 'academy_admin') {
            $active = DB::table('admin_capabilities')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->pluck('capability');
            if ($active->isNotEmpty()) {
                $capabilityPermissions = DB::table('capability_permissions')
                    ->whereIn('capability', $active)
                    ->pluck('permission_key');
            }
        }

        return $rolePermissions->merge($capabilityPermissions)->unique()->sort()->values()->all();
    }

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->effectivePermissions($user), true);
    }
}
