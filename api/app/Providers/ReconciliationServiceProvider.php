<?php

namespace App\Providers;

use App\Services\Reconciliation\Assertions\AuditImmutabilityProbe;
use App\Services\Reconciliation\Assertions\AuditTriggerEnabledProbe;
use App\Services\Reconciliation\Assertions\ConsentSignExclusivityAssertion;
use App\Services\Reconciliation\Assertions\GuardianLinkCoverageAssertion;
use App\Services\Reconciliation\Assertions\MemberDirectoryExclusivityAssertion;
use App\Services\Reconciliation\Assertions\PermissionMatrixProbe;
use App\Services\Reconciliation\Assertions\ScopeCoverageAssertion;
use App\Services\Reconciliation\Assertions\VersionSnapshotImmutabilityProbe;
use App\Services\Reconciliation\ReconciliationRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Every sprint registers its reconciliation assertions here — this list is the
 * platform's spine. Assertions are added, never removed or disabled.
 */
class ReconciliationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReconciliationRegistry::class, function (): ReconciliationRegistry {
            $registry = new ReconciliationRegistry;

            // S00
            $registry->register(new AuditImmutabilityProbe);
            $registry->register(new AuditTriggerEnabledProbe);

            // S01
            $registry->register(new PermissionMatrixProbe);
            $registry->register(new GuardianLinkCoverageAssertion);
            $registry->register(new ConsentSignExclusivityAssertion);
            $registry->register(new MemberDirectoryExclusivityAssertion);

            // S02A
            $registry->register(new ScopeCoverageAssertion);
            $registry->register(new VersionSnapshotImmutabilityProbe);

            return $registry;
        });
    }
}
