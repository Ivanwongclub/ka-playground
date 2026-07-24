<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * consent.sign belongs to the guardian role ALONE (Leo review, 24 Jul). A
 * consent signable by staff proves nothing: FR036 requires an authenticated
 * guardian session, ETO Cap. 553 rests on attribution, BI-6 hashes a document
 * whose purpose is proving that specific guardian agreed. This assertion makes
 * reintroduction via any capability group or role a nightly alarm.
 */
class ConsentSignExclusivityAssertion implements Assertion
{
    public function key(): string
    {
        return 'authz.consent_sign_exclusive';
    }

    public function proves(): string
    {
        return 'consent.sign is held by the guardian role only — no capability group, no other role';
    }

    public function cites(): string
    {
        return 'FR036 · BI-6 · ETO Cap. 553';
    }

    public function tags(): array
    {
        return ['S01'];
    }

    public function check(): AssertionResult
    {
        $capabilityHolders = DB::table('capability_permissions')
            ->where('permission_key', 'consent.sign')->pluck('capability');
        if ($capabilityHolders->isNotEmpty()) {
            return AssertionResult::fail(
                'capability group(s) hold consent.sign: '.$capabilityHolders->implode(', ')
            );
        }

        $roleHolders = DB::table('role_permissions')
            ->where('permission_key', 'consent.sign')->pluck('role_key');
        if ($roleHolders->count() !== 1 || $roleHolders->first() !== 'guardian') {
            return AssertionResult::fail(
                'consent.sign role holders are ['.$roleHolders->implode(', ')."] — expected exactly ['guardian']"
            );
        }

        return AssertionResult::pass('guardian is the sole holder of consent.sign');
    }
}
