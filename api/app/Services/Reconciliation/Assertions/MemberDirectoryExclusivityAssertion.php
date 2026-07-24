<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * member_directory.view is held by exactly {member, academy_admin} among roles
 * and exactly {super_admin} among capability groups (Leo review, 24 Jul). The
 * member directory lists first-generation adults (FR058/OD-1) — it must never
 * quietly widen into something students appear in or more roles can browse
 * (FR056). This assertion turns accidental widening by a later sprint into a
 * nightly alarm.
 */
class MemberDirectoryExclusivityAssertion implements Assertion
{
    private const ALLOWED_ROLES = ['academy_admin', 'member'];

    private const ALLOWED_CAPABILITIES = ['super_admin'];

    public function key(): string
    {
        return 'authz.member_directory_exclusive';
    }

    public function proves(): string
    {
        return 'member_directory.view is held only by member + academy_admin roles and the super_admin capability';
    }

    public function cites(): string
    {
        return 'FR056 · FR058 · OD-1';
    }

    public function tags(): array
    {
        return ['S01'];
    }

    public function check(): AssertionResult
    {
        $roles = DB::table('role_permissions')
            ->where('permission_key', 'member_directory.view')
            ->orderBy('role_key')->pluck('role_key')->all();
        if ($roles !== self::ALLOWED_ROLES) {
            return AssertionResult::fail(
                'role holders are ['.implode(', ', $roles).'] — expected exactly [academy_admin, member]'
            );
        }

        $capabilities = DB::table('capability_permissions')
            ->where('permission_key', 'member_directory.view')
            ->orderBy('capability')->pluck('capability')->all();
        if ($capabilities !== self::ALLOWED_CAPABILITIES) {
            return AssertionResult::fail(
                'capability holders are ['.implode(', ', $capabilities).'] — expected exactly [super_admin]'
            );
        }

        return AssertionResult::pass('holder set is exactly {member, academy_admin} + {super_admin}');
    }
}
