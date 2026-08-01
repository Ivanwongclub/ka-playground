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
use App\Services\Reconciliation\Assertions\InvoiceBalanceAssertion;
use App\Services\Reconciliation\Assertions\ManualPaymentSodAssertion;
use App\Services\Reconciliation\Assertions\OrderLinesImmutabilityProbe;
use App\Services\Reconciliation\Assertions\ReceiptGaplessAssertion;
use App\Services\Reconciliation\Assertions\ActivationLivenessAssertion;
use App\Services\Reconciliation\Assertions\CapacityClaimsWholeAssertion;
use App\Services\Reconciliation\Assertions\CapacityConservationAssertion;
use App\Services\Reconciliation\Assertions\AttendanceIntegrityAssertion;
use App\Services\Reconciliation\Assertions\BookingCascadeLiveAssertion;
use App\Services\Reconciliation\Assertions\PublicContextConfinementAssertion;
use App\Services\Reconciliation\Assertions\LinkActivationAuditedAssertion;
use App\Services\Reconciliation\Assertions\NoUnverifiedMaterialisationAssertion;
use App\Services\Reconciliation\Assertions\QueueEscalationLivenessAssertion;
use App\Services\Reconciliation\Assertions\AccountProvenanceAssertion;
use App\Services\Reconciliation\Assertions\NoActiveWithoutApprovalAssertion;
use App\Services\Reconciliation\Assertions\GuardianAdditionVisibilityAssertion;
use App\Services\Reconciliation\Assertions\BatchNoStuckAssertion;
use App\Services\Reconciliation\Assertions\BatchRowConservationAssertion;
use App\Services\Reconciliation\Assertions\BatchScanGatedAssertion;
use App\Services\Reconciliation\Assertions\BudgetApprovedProvenanceAssertion;
use App\Services\Reconciliation\Assertions\InvoiceLineReconciliationAssertion;
use App\Services\Reconciliation\Assertions\NoSilentOverdueAssertion;
use App\Services\Reconciliation\Assertions\ObligationPayerMatchesProgrammeAssertion;
use App\Services\Reconciliation\Assertions\VouchScopeAssertion;
use App\Services\Reconciliation\Assertions\ConsentCompleteAtConfirmAssertion;
use App\Services\Reconciliation\Assertions\LadderLivenessAssertion;
use App\Services\Reconciliation\Assertions\LearnGateIntegrityAssertion;
use App\Services\Reconciliation\Assertions\NoStalePublishedSessionAssertion;
use App\Services\Reconciliation\Assertions\NoSilentLapseAssertion;
use App\Services\Reconciliation\Assertions\PoolNoExpiredParkingAssertion;
use App\Services\Reconciliation\Assertions\RefundBackstopProvenanceAssertion;
use App\Services\Reconciliation\Assertions\TeamSizeOrWaiverAssertion;
use App\Services\Reconciliation\Assertions\RefundFullOnlyAssertion;
use App\Services\Reconciliation\Assertions\PaymentObligationCompletenessAssertion;
use App\Services\Reconciliation\Assertions\PaymentLinkNoPiiAssertion;
use App\Services\Reconciliation\Assertions\PaymentLinkSingleReaderAssertion;
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
            $registry->register(new PaymentLinkNoPiiAssertion);
            $registry->register(new PaymentLinkSingleReaderAssertion);
            $registry->register(new InvoiceBalanceAssertion);
            $registry->register(new ReceiptGaplessAssertion);
            $registry->register(new OrderLinesImmutabilityProbe);
            $registry->register(new ManualPaymentSodAssertion);
            $registry->register(new RefundFullOnlyAssertion);

            // S05 — provenance + no_silent_lapse landed early (out-of-BI-9 backstop
            // control; resolution machinery at STEP 4). The rest of the battery lands
            // at the S05 gate (STEP 6).
            $registry->register(new RefundBackstopProvenanceAssertion);
            $registry->register(new NoSilentLapseAssertion);
            $registry->register(new CapacityConservationAssertion);
            $registry->register(new CapacityClaimsWholeAssertion);
            $registry->register(new ConsentCompleteAtConfirmAssertion);
            $registry->register(new TeamSizeOrWaiverAssertion);
            $registry->register(new PoolNoExpiredParkingAssertion);

            // S06
            $registry->register(new ActivationLivenessAssertion);
            $registry->register(new LadderLivenessAssertion);
            // S06-7 gate battery
            $registry->register(new LearnGateIntegrityAssertion);
            $registry->register(new NoStalePublishedSessionAssertion);
            $registry->register(new AttendanceIntegrityAssertion);
            $registry->register(new BookingCascadeLiveAssertion);

            // S04C — the anonymous-write confinement (STEP 1)
            $registry->register(new PublicContextConfinementAssertion);
            // S04C STEP 3 — FLAG #2 made structural + the held-link typo guard
            $registry->register(new LinkActivationAuditedAssertion);
            $registry->register(new NoUnverifiedMaterialisationAssertion);
            // S04C STEP 4 — the queue's escalation liveness + account provenance
            $registry->register(new QueueEscalationLivenessAssertion);
            $registry->register(new AccountProvenanceAssertion);
            // S04D STEP 1 — the all-three-tables activation provenance backstop
            $registry->register(new NoActiveWithoutApprovalAssertion);
            // S04D STEP 3 — OD-24 never-silent + OD-30 vouch scope
            $registry->register(new GuardianAdditionVisibilityAssertion);
            $registry->register(new VouchScopeAssertion);
            // S04E STEP 1 — the BI-10 scan gate, path-independent
            $registry->register(new BatchScanGatedAssertion);
            // S04E STEP 2 — every committed batch row conserved, reasoned, no waiting
            $registry->register(new BatchRowConservationAssertion);
            // S04E STEP 3 — no batch stuck in a transient state (liveness)
            $registry->register(new BatchNoStuckAssertion);
            // S04F STEP 1 — obligation/order payer matches its programme E6 (OD-25/D-18)
            $registry->register(new ObligationPayerMatchesProgrammeAssertion);
            // S04F STEP 2 — invoice original = Σ its covered orders (OD-25)
            $registry->register(new InvoiceLineReconciliationAssertion);
            // S04F STEP 3 — no school invoice past due+grace left un-aged (OD-55)
            $registry->register(new NoSilentOverdueAssertion);
            // S07 STEP 1 — every active team budget carries an approving audit (FR061)
            $registry->register(new BudgetApprovedProvenanceAssertion);

            return $registry;
        });
    }
}
