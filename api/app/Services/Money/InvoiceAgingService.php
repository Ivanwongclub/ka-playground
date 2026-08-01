<?php

namespace App\Services\Money;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;

/**
 * S04F STEP 3 (OD-55) — the school-settled receivable's aging lifecycle. A
 * school's non-payment ages the INVOICE (academy collections); it NEVER lapses a
 * child's enrolment. Every method here writes ONLY `consolidated_invoices`
 * (+ its audit) — no enrolment, order, team_member, or consent is ever touched.
 * That confinement IS the child-welfare protection: the children already
 * participated; the receivable is academy↔school.
 *
 * Status is the ledger (D-13): `overdue` is the trackable academy exception,
 * with terminal fates `paid` (resolved-on-pay) and `issued` (academy extends
 * terms). "Withdraw the cohort" (OD-55) is a manual academy op — never automatic.
 */
class InvoiceAgingService
{
    /** issued → overdue (sweep); overdue → {issued (extend), paid}; issued → paid. */
    private const TRANSITIONS = [
        'issued' => ['overdue', 'paid'],
        'overdue' => ['issued', 'paid'],
        'paid' => [],
    ];

    public function __construct(
        private readonly ScopeContext $scope,
        private readonly AuditService $audit,
    ) {}

    /**
     * The nightly sweep: every unpaid invoice past due_at + grace becomes
     * `overdue`, audited — so the academy exception is never silent.
     *
     * @return int invoices aged this run
     */
    public function run(?\DateTimeInterface $asOf = null): int
    {
        $now = $asOf ?? now();
        $grace = (int) config('finance.school_invoice_grace_days', 7);

        return $this->scope->asSystem(
            'S04F STEP 3 invoice aging (OD-55): the SYSTEM scans school-settled invoices past due_at + grace and ages each unpaid one to overdue, audited. It writes ONLY consolidated_invoices — never an enrolment/order/team_member/consent — so a school\'s non-payment can never lapse a child. consolidated_invoices is system-write by construction; this is the scheduled actor.',
            function () use ($now, $grace): int {
                $due = DB::table('consolidated_invoices')
                    ->where('status', 'issued')
                    ->whereNotNull('due_at')
                    ->whereRaw("due_at + (? || ' days')::interval < ?", [$grace, $now])
                    ->pluck('id');

                foreach ($due as $id) {
                    $this->transition($id, 'overdue', null, "aged past due_at + {$grace}d grace (OD-55)");
                }

                return $due->count();
            },
        );
    }

    /** Academy extends terms: overdue → issued, the clock reset forward. */
    public function extendTerms(string $invoiceId, User $admin): void
    {
        $this->scope->asSystem(
            'S04F STEP 3 extend terms (OD-55): an academy operator resets an overdue school invoice\'s due_at forward and returns it to issued — the "extend terms" resolution. Writes ONLY consolidated_invoices; the authority check precedes this elevation.',
            function () use ($invoiceId, $admin): void {
                $newDue = now()->addDays((int) config('finance.school_invoice_terms_days', 30));
                DB::table('consolidated_invoices')->where('id', $invoiceId)->update(['due_at' => $newDue]);
                $this->transition($invoiceId, 'issued', $admin, 'terms extended (OD-55)');
            },
        );
    }

    /** Resolved-on-pay: overdue|issued → paid (offline settlement recorded). */
    public function markPaid(string $invoiceId, User $admin): void
    {
        $this->scope->asSystem(
            'S04F STEP 3 mark paid (OD-55): records that a school settled its consolidated invoice (offline, record-only), moving it to paid — the resolved-on-pay terminal fate. Writes ONLY consolidated_invoices; the authority check precedes this elevation.',
            fn () => $this->transition($invoiceId, 'paid', $admin, 'school settled the invoice (offline)'),
        );
    }

    private function transition(string $invoiceId, string $to, ?User $actor, string $reason): void
    {
        $invoice = DB::table('consolidated_invoices')->where('id', $invoiceId)->first()
            ?? throw new \RuntimeException("Consolidated invoice {$invoiceId} not found");
        if ($invoice->status === $to) {
            return;
        }
        if (! in_array($to, self::TRANSITIONS[$invoice->status] ?? [], true)) {
            throw new \RuntimeException("Illegal invoice transition {$invoice->status} → {$to}");
        }
        DB::table('consolidated_invoices')->where('id', $invoiceId)->update(['status' => $to, 'updated_at' => now()]);
        $this->audit->record('consolidated_invoice', $invoiceId, "consolidated_invoice.{$to}",
            fromState: $invoice->status, toState: $to, reason: $reason,
            programmeId: (int) $invoice->programme_id, actor: $actor);
    }
}
