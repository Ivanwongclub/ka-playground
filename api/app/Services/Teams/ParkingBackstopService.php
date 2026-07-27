<?php

namespace App\Services\Teams;

use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S05 STEP 3 — the 90-day parking backstop (OD-35, the loop-breaker). A scheduled
 * SYSTEM job: any parked_rollforward exception past its backstop_at is force-
 * resolved so a rolled-forward student can never sit forever. If the enrolment
 * carries a PAID order (only possible once STEP 4 dissolution re-pools paid
 * members), the system issues a FULL auto-refund (OD-48) — origin='backstop_auto',
 * OUT of BI-9 per OD-47 (non-manual/self-confirm money is outside BI-9; Leo ruling
 * 2026-07-27) — then releases; if unpaid, it simply releases. Every step is
 * attributed to the SYSTEM actor (OD-64).
 */
class ParkingBackstopService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly EnrolmentService $enrolments,
        private readonly TeamExceptionService $exceptions,
    ) {}

    /** Fire the backstop for every parked exception past its window as of $asOf (default now). */
    public function run(?\DateTimeInterface $asOf = null): array
    {
        $now = $asOf ?? now();

        return $this->scope->asSystem(
            'Parking backstop (S05-3, OD-35): the SYSTEM force-resolves parked roll-forwards past their 90-day window — full auto-refund (origin=backstop_auto, out of BI-9 per OD-47) of any paid order, then enrolment in_pool → released, then the exception auto_released. Scheduled actor; touches only expired parked rows and their own orders/enrolments.',
            function () use ($now): array {
                $refunded = 0;
                $released = 0;
                $due = DB::table('team_exceptions')
                    ->where('type', 'parked_rollforward')->where('status', 'open')
                    ->whereNotNull('backstop_at')->where('backstop_at', '<', $now)
                    ->orderBy('id')->get();

                foreach ($due as $ex) {
                    $enrolment = DB::table('enrolments')->where('id', $ex->enrolment_id)->first();
                    if ($enrolment === null) {
                        continue;
                    }
                    $paidOrder = DB::table('orders')->where('enrolment_id', $enrolment->id)->where('status', 'paid')->first();
                    $didRefund = false;
                    if ($paidOrder !== null) {
                        $this->autoRefund($paidOrder);
                        $refunded++;
                        $didRefund = true;
                    }
                    if ($enrolment->status === 'in_pool') {
                        $this->enrolments->transition($enrolment->id, 'released', null, 'parking backstop expired (OD-35, SYSTEM)');
                        $released++;
                    }
                    $this->exceptions->resolveOpenFor((int) $enrolment->programme_id, 'enrolment', $enrolment->id,
                        $didRefund ? 'auto_refund_release' : 'auto_release', status: 'auto_released');
                }

                return ['refunded' => $refunded, 'released' => $released];
            },
        );
    }

    /** Full SYSTEM auto-refund of a paid order (OD-48 full-only), out of BI-9 (OD-47). */
    private function autoRefund(object $order): void
    {
        $destParty = $order->payer_party; // guardian | student | school
        $destSchoolId = $order->payer_party === 'school' ? $order->payer_school_id : null;
        $id = (string) Str::uuid7();
        DB::table('refunds')->insert([
            'id' => $id, 'order_id' => $order->id, 'withdrawal_request_id' => null, 'origin' => 'backstop_auto',
            'amount_minor' => (int) $order->total_amount_minor, 'currency' => $order->currency,
            'destination_party' => $destParty, 'destination_school_id' => $destSchoolId,
            'status' => 'confirmed', // system self-confirmed; no human recorder/confirmer (OD-47, out of BI-9)
            'requested_by' => null, 'approved_by' => null, 'confirmed_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'refunded', 'updated_at' => now()]);
        $this->audit->record('refund', $id, 'refund.auto_confirmed',
            toState: 'confirmed', reason: 'parking backstop full auto-refund (OD-35/48, out of BI-9 per OD-47)',
            programmeId: (int) $order->programme_id,
            payloadAfter: ['order_id' => $order->id, 'origin' => 'backstop_auto', 'amount_minor' => (int) $order->total_amount_minor]);
        $this->audit->record('order', $order->id, 'order.refunded',
            fromState: 'paid', toState: 'refunded', programmeId: (int) $order->programme_id,
            payloadAfter: ['refund_id' => $id]);
    }
}
