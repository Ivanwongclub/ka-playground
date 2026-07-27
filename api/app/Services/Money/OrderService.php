<?php

namespace App\Services\Money;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orders (S04B step 1): FULL programme fee, snapshotted at issuance — the SAME
 * for every student (OD-67); integer minor units + ISO currency (OD-18);
 * payer_party says who PAYS, the academy always receives (OD-25); lines are
 * INSERT-only (BI-5). Issuance is system-context only (the step-2 outbox
 * consumer is the caller; nothing user-reachable issues an order).
 */
class OrderService
{
    public function __construct(private readonly AuditService $audit) {}

    public function issueForEnrolment(string $enrolmentId, string $payerParty, ?int $payerSchoolId, ?User $actor, ?int $deadlineDays = null): object
    {
        $enrolment = DB::table('enrolments')->where('id', $enrolmentId)->first()
            ?? throw new RuntimeException("Enrolment {$enrolmentId} not found");
        if (! in_array($enrolment->status, ['confirmed', 'active'], true)) {
            throw new RuntimeException("Orders are issued for confirmed enrolments only — {$enrolmentId} is {$enrolment->status} (OD-43)");
        }
        $existing = DB::table('orders')->where('enrolment_id', $enrolmentId)->where('status', '<>', 'cancelled')->first();
        if ($existing !== null) {
            return $existing; // idempotent — the outbox consumer may re-scan
        }

        $fees = DB::table('fee_items')->where('programme_id', $enrolment->programme_id)
            ->orderBy('created_at')->get();
        if ($fees->isEmpty()) {
            throw new RuntimeException("Programme {$enrolment->programme_id} has no fee items — cannot issue an order");
        }
        $total = (int) $fees->sum('amount_minor');

        $id = (string) Str::uuid7();
        try {
            DB::transaction(function () use ($id, $enrolment, $payerParty, $payerSchoolId, $fees, $total, $deadlineDays): void {
                DB::table('orders')->insert([
                    'id' => $id, 'enrolment_id' => $enrolment->id,
                    'programme_id' => $enrolment->programme_id, 'student_id' => $enrolment->student_id,
                    'payer_party' => $payerParty, 'payer_school_id' => $payerSchoolId,
                    'status' => 'issued', 'total_amount_minor' => $total, 'currency' => 'HKD',
                    'payment_due_at' => $payerParty === 'school' ? null : now()->addDays($deadlineDays ?? 7), // OD-43
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($fees as $fee) {
                    DB::table('order_lines')->insert([
                        'id' => (string) Str::uuid7(), 'order_id' => $id,
                        'name_en' => $fee->name_en, 'name_tc' => $fee->name_tc, 'name_sc' => $fee->name_sc,
                        'amount_minor' => (int) $fee->amount_minor, 'currency' => $fee->currency,
                        'created_at' => now(),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return DB::table('orders')->where('enrolment_id', $enrolmentId)->where('status', '<>', 'cancelled')->first();
        }
        $this->audit->record('order', $id, 'order.issued',
            toState: 'issued', programmeId: (int) $enrolment->programme_id,
            payloadAfter: ['enrolment_id' => $enrolment->id, 'payer_party' => $payerParty,
                'total_amount_minor' => $total, 'currency' => 'HKD'],
            actor: $actor);

        return DB::table('orders')->where('id', $id)->first();
    }
}
