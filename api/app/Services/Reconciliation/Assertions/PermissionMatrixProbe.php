<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * The database matrix must equal config/permission-matrix.php exactly — roles
 * AND capability groups (OD-17). Catches post-seed drift (a manually edited
 * row grants or removes power invisibly). Deliberately circular against the
 * seed: the Member NEGATIVE tests in the S01 suite are the non-circular control.
 */
class PermissionMatrixProbe implements Assertion
{
    public function key(): string
    {
        return 'authz.permission_matrix';
    }

    public function proves(): string
    {
        return 'role and capability permission grids in the database match config/permission-matrix.php';
    }

    public function cites(): string
    {
        return 'OD-1 · OD-17 · Spec B7';
    }

    public function tags(): array
    {
        return ['S01'];
    }

    public function check(): AssertionResult
    {
        $matrix = config('permission-matrix');
        $diffs = [];

        $expectedRoles = collect($matrix['roles'])
            ->flatMap(fn (array $perms, string $role) => array_map(fn ($p) => "{$role}:{$p}", $perms))
            ->sort()->values();
        $actualRoles = DB::table('role_permissions')
            ->get()->map(fn ($r) => "{$r->role_key}:{$r->permission_key}")->sort()->values();
        foreach ($expectedRoles->diff($actualRoles) as $missing) {
            $diffs[] = "role grid MISSING {$missing}";
        }
        foreach ($actualRoles->diff($expectedRoles) as $extra) {
            $diffs[] = "role grid has EXTRA {$extra}";
        }

        $expectedCaps = collect($matrix['capabilities'])
            ->flatMap(fn ($perms, string $cap) => array_map(
                fn ($p) => "{$cap}:{$p}",
                $perms === '*' ? $matrix['permissions'] : $perms,
            ))
            ->sort()->values();
        $actualCaps = DB::table('capability_permissions')
            ->get()->map(fn ($r) => "{$r->capability}:{$r->permission_key}")->sort()->values();
        foreach ($expectedCaps->diff($actualCaps) as $missing) {
            $diffs[] = "capability grid MISSING {$missing}";
        }
        foreach ($actualCaps->diff($expectedCaps) as $extra) {
            $diffs[] = "capability grid has EXTRA {$extra}";
        }

        if ($diffs !== []) {
            return AssertionResult::fail(implode('; ', array_slice($diffs, 0, 10)).(count($diffs) > 10 ? ' …' : ''));
        }

        return AssertionResult::pass(
            sprintf('%d role rows and %d capability rows match the source of truth', $actualRoles->count(), $actualCaps->count())
        );
    }
}
