<?php

namespace App\Services\Money;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Refund payout (2.17): requested → approved → confirmed | rejected. BI-9 in
 * depth — the approver and the confirmer are DISTINCT finance people, refused
 * both at the app (403 + audit) and at the DB (rf_update WITH CHECK). FULL
 * amount only (OD-48); destination = the original payer party (OD-25).
 * The 'requested' row is minted only by WithdrawalSettlementService (system).
 */
class RefundService
{
    public function __construct(private readonly AuditService $audit) {}

    public function approve(string $refundId, string $evidenceNote, User $approver): void
    {
        $refund = DB::table('refunds')->where('id', $refundId)->first() ?? abort(404);
        if ($refund->status !== 'requested') {
            abort(409, 'Only a requested refund can be approved');
        }
        if (trim($evidenceNote) === '') {
            abort(422, '2.17: payout approval requires evidence');
        }
        DB::table('refunds')->where('id', $refundId)->update([
            'status' => 'approved', 'approved_by' => $approver->id,
            'evidence_note' => $evidenceNote, 'updated_at' => now(),
        ]);
        $this->audit->record('refund', $refundId, 'refund.approved',
            fromState: 'requested', toState: 'approved',
            payloadAfter: ['approved_by' => $approver->id, 'amount_minor' => (int) $refund->amount_minor], actor: $approver);
    }

    public function confirm(string $refundId, User $confirmer): void
    {
        $refund = DB::table('refunds')->where('id', $refundId)->first() ?? abort(404);
        if ($refund->status !== 'approved') {
            abort(409, 'Only an approved refund can be confirmed');
        }
        if ((int) $refund->approved_by === (int) $confirmer->id) {
            // BI-9 — refused server-side and audited; the DB policy backstops it
            $this->audit->record('refund', $refundId, 'refund.bi9_refused',
                reason: 'the payout approver cannot also confirm it (BI-9: recorder ≠ confirmer, 2.17)', actor: $confirmer);
            abort(403, 'BI-9: the approver of a refund cannot confirm it — a second finance account must confirm');
        }
        DB::table('refunds')->where('id', $refundId)->update([
            'status' => 'confirmed', 'confirmed_by' => $confirmer->id, 'updated_at' => now(),
        ]);
        $this->audit->record('refund', $refundId, 'refund.confirmed',
            fromState: 'approved', toState: 'confirmed',
            payloadAfter: ['approved_by' => $refund->approved_by, 'confirmed_by' => $confirmer->id], actor: $confirmer);
    }

    public function reject(string $refundId, string $reason, User $decider): void
    {
        $refund = DB::table('refunds')->where('id', $refundId)->first() ?? abort(404);
        if (! in_array($refund->status, ['requested', 'approved'], true)) {
            abort(409, 'Only a requested or approved refund can be rejected');
        }
        DB::table('refunds')->where('id', $refundId)->update(['status' => 'rejected', 'updated_at' => now()]);
        $this->audit->record('refund', $refundId, 'refund.rejected',
            fromState: $refund->status, toState: 'rejected', reason: $reason, actor: $decider);
    }
}
