<?php

namespace App\Services\Money;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Uploads\UploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manual recording (S04B step 4): school-settled/offline money, recorded only
 * when received IN FULL (OD-5/48 — the amount must equal the order total;
 * under/over is refused, not recorded). BI-9 recorder ≠ confirmer is enforced
 * server-side here AND at the database (the UPDATE policy's WITH CHECK).
 * Evidence: 1..n images/PDFs through the EXISTING BI-10 pipeline — the
 * 'evidence' context that has existed since S00; no new intake surface.
 * Provider payments never enter this flow (OD-47: they self-confirm).
 */
class ManualPaymentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly UploadService $uploads,
    ) {}

    /** @param UploadedFile[] $evidenceFiles */
    public function record(string $orderId, int $amountMinor, string $currency, array $evidenceFiles, ?string $note, User $recorder): object
    {
        $order = DB::table('orders')->where('id', $orderId)->first() ?? abort(404);
        if ($order->status !== 'issued') {
            // 2.19 semantics arrive with the exception queue; never silent
            $this->audit->record('order', $orderId, 'payment.late_refused',
                reason: "manual recording against a {$order->status} order refused (2.19)",
                programmeId: (int) $order->programme_id, actor: $recorder);
            abort(409, "Order is {$order->status} — recording refused and audited (2.19)");
        }
        if ($amountMinor !== (int) $order->total_amount_minor || $currency !== $order->currency) {
            throw ValidationException::withMessages(['amount' => [
                'OD-5/OD-48: payments are recorded only when received IN FULL — the amount must equal the order total exactly. An underpayment is not recorded; an overpayment is not recordable here.',
            ]]);
        }
        if ($evidenceFiles === []) {
            throw ValidationException::withMessages(['evidence' => ['1..n evidence images are required (OD-5)']]);
        }

        $paymentId = (string) Str::uuid7();
        DB::table('payments')->insert([
            'id' => $paymentId, 'order_id' => $order->id, 'origin' => 'manual',
            'amount_minor' => $amountMinor, 'currency' => $currency, 'via_link' => false,
            'status' => 'pending_confirmation', 'recorded_by' => $recorder->id,
            'note' => $note, 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($evidenceFiles as $file) {
            $upload = $this->uploads->intake($file, 'evidence', $recorder); // the EXISTING pipeline
            DB::table('payment_evidence')->insert([
                'id' => (string) Str::uuid7(), 'payment_id' => $paymentId,
                'upload_id' => $upload->id, 'created_at' => now(),
            ]);
        }
        $this->audit->record('payment', $paymentId, 'payment.recorded',
            toState: 'pending_confirmation', programmeId: (int) $order->programme_id,
            payloadAfter: ['order_id' => $order->id, 'amount_minor' => $amountMinor,
                'evidence_count' => count($evidenceFiles), 'recorded_by' => $recorder->id],
            actor: $recorder);

        return DB::table('payments')->where('id', $paymentId)->first();
    }

    public function confirm(string $paymentId, User $confirmer): void
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first() ?? abort(404);
        if ($payment->origin !== 'manual' || $payment->status !== 'pending_confirmation') {
            abort(409, 'Only a pending manual payment can be confirmed');
        }
        if ((int) $payment->recorded_by === (int) $confirmer->id) {
            // BI-9, refused server-side and AUDITED — the DB policy backstops this
            $this->audit->record('payment', $paymentId, 'payment.bi9_refused',
                reason: 'recorder attempted to confirm their own payment (BI-9: recorder ≠ confirmer)',
                actor: $confirmer);
            abort(403, 'BI-9: the recorder cannot confirm their own payment — a second finance account must confirm');
        }
        // BI-10: confirmation waits until every evidence file has passed the
        // scan. Scan status is a SYSTEM-INTEGRITY fact, not a scoped read — the
        // confirmer's authority was already set by finance.confirm + BI-9, but
        // uploads RLS does not admit them, so a scoped count would read 0 and
        // silently defeat the gate. Read the true state under a narrow elevation.
        $notClean = app(\App\Services\Authz\ScopeContext::class)->asSystem(
            'Manual payment BI-10 gate (S04B): confirmation must wait until every evidence upload is scan-clean. Scan status is a system-integrity fact; the confirmer\'s authorisation is already established by finance.confirm + BI-9. Reads only uploads.status for this payment\'s evidence; no upload content or other row leaves the elevation.',
            fn () => DB::table('payment_evidence as pe')
                ->join('uploads as u', 'u.id', '=', 'pe.upload_id')
                ->where('pe.payment_id', $paymentId)->where('u.status', '<>', 'clean')->count(),
        );
        if ($notClean > 0) {
            abort(409, "{$notClean} evidence file(s) not yet clean — confirmation waits for the scan (BI-10)");
        }

        DB::table('payments')->where('id', $paymentId)->update([
            'status' => 'confirmed', 'confirmed_by' => $confirmer->id,
            'confirmed_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('payment', $paymentId, 'payment.confirmed',
            fromState: 'pending_confirmation', toState: 'confirmed',
            payloadAfter: ['recorded_by' => $payment->recorded_by, 'confirmed_by' => $confirmer->id],
            actor: $confirmer);
        \App\Jobs\FinalizeManualPayment::dispatch($paymentId)->afterCommit();
    }

    public function reject(string $paymentId, string $reason, User $decider): void
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first() ?? abort(404);
        if ($payment->origin !== 'manual' || $payment->status !== 'pending_confirmation') {
            abort(409, 'Only a pending manual payment can be rejected');
        }
        if ((int) $payment->recorded_by === (int) $decider->id) {
            $this->audit->record('payment', $paymentId, 'payment.bi9_refused',
                reason: 'recorder attempted to decide their own payment (BI-9 applies to rejection too)',
                actor: $decider);
            abort(403, 'BI-9: the recorder cannot decide their own payment');
        }
        DB::table('payments')->where('id', $paymentId)->update([
            'status' => 'rejected', 'confirmed_by' => $decider->id, 'updated_at' => now(),
        ]);
        $this->audit->record('payment', $paymentId, 'payment.rejected',
            fromState: 'pending_confirmation', toState: 'rejected', reason: $reason, actor: $decider);
    }
}
