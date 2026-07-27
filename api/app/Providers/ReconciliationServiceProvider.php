<?php

namespace App\Providers;

use App\Services\Reconciliation\Assertions\AuditImmutabilityProbe;
use App\Services\Reconciliation\Assertions\AuditTriggerEnabledProbe;
use App\Services\Reconciliation\Assertions\ConsentHashIntegrityAssertion;
use App\Services\Reconciliation\Assertions\ConsentIssuanceCompletenessAssertion;
use App\Services\Reconciliation\Assertions\DeadlineOrderingAssertion;
use App\Services\Reconciliation\Assertions\EnrolmentStatusBypassAssertion;
use App\Services\Reconciliation\Assertions\EnrolmentUniquenessProbe;
use App\Services\Reconciliation\Assertions\PoolIntegrityAssertion;
use App\Services\Reconciliation\Assertions\ConsentLanguageCompletenessAssertion;
use App\Services\Reconciliation\Assertions\ConsentSignExclusivityAssertion;
use App\Services\Reconciliation\Assertions\SupersededVersionReconsentAssertion;
use App\Services\Reconciliation\Assertions\DefaultLobbyAssertion;
use App\Services\Reconciliation\Assertions\PublishedProgrammeCompletenessAssertion;
use App\Services\Reconciliation\Assertions\GuardianLinkCoverageAssertion;
use App\Services\Reconciliation\Assertions\MemberDirectoryExclusivityAssertion;
use App\Services\Reconciliation\Assertions\PaymentObligationCompletenessAssertion;
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

            // S02B
            $registry->register(new PublishedProgrammeCompletenessAssertion);
            $registry->register(new DefaultLobbyAssertion);

            // S03
            $registry->register(new ConsentHashIntegrityAssertion);
            $registry->register(new ConsentLanguageCompletenessAssertion);
            $registry->register(new SupersededVersionReconsentAssertion);

            // S04A
            $registry->register(new EnrolmentUniquenessProbe);
            $registry->register(new PoolIntegrityAssertion);
            $registry->register(new EnrolmentStatusBypassAssertion);
            $registry->register(new ConsentIssuanceCompletenessAssertion);
            $registry->register(new DeadlineOrderingAssertion);

            // S04B
            $registry->register(new PaymentObligationCompletenessAssertion);

            return $registry;
        });
    }
}
