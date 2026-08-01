<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Money\ConsolidatedInvoiceService;
use App\Services\Money\InvoiceAgingService;
use App\Services\Reconciliation\Assertions\NoSilentOverdueAssertion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S04F STEP 3 (OD-55) — invoice aging + the (school, programme) unique. The
 * receivable's clock is set once at issuance and only extendTerms moves it; the
 * sweep ages an unpaid invoice to overdue writing ONLY consolidated_invoices —
 * a child's enrolment is NEVER lapsed by a school's non-payment.
 */
class InvoiceAgingTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private School $school;

    private int $programmeId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        $this->school = $this->sys(fn () => School::create(['name_en' => 'Sch'.Str::random(3), 'name_tc' => '甲', 'name_sc' => '甲']));
        $this->programmeId = $this->sys(fn () => DB::table('programmes')->insertGetId(['code' => 'AG'.Str::random(4), 'name_en' => 'a', 'name_tc' => 'a', 'name_sc' => 'a', 'jurisdiction' => 'HK', 'payer_party' => 'school', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]));
    }

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->setSystem();
        }
    }

    /** A school-payer 'issued' order for this school/programme (enrolment + student). */
    private function schoolOrder(int $fee = 250000): object
    {
        return $this->sys(function () use ($fee) {
            $student = User::factory()->create(['role' => 'student']);
            $eid = (string) Str::uuid7();
            DB::table('enrolments')->insert(['id' => $eid, 'programme_id' => $this->programmeId, 'student_id' => $student->id, 'acting_guardian_id' => $student->id, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
            $oid = (string) Str::uuid7();
            DB::table('orders')->insert(['id' => $oid, 'enrolment_id' => $eid, 'programme_id' => $this->programmeId, 'student_id' => $student->id, 'payer_party' => 'school', 'payer_school_id' => $this->school->id, 'status' => 'issued', 'total_amount_minor' => $fee, 'currency' => 'HKD', 'payment_due_at' => null, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('orders')->where('id', $oid)->first();
        });
    }

    private function invoiceId(): ?string
    {
        return $this->sys(fn () => DB::table('consolidated_invoices')->where('programme_id', $this->programmeId)->value('id'));
    }

    // ── due_at set once at issuance; UNCHANGED by later covered orders ────────

    public function test_due_at_is_set_at_issuance_and_immutable_on_recompute(): void
    {
        $svc = app(ConsolidatedInvoiceService::class);
        $o1 = $this->schoolOrder();
        $this->sys(fn () => $svc->coverOrder($o1));
        $due1 = $this->sys(fn () => DB::table('consolidated_invoices')->where('programme_id', $this->programmeId)->value('due_at'));
        $this->assertNotNull($due1);
        // ~30 days out
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, strtotime($due1), 120);

        // a second covered order GROWS original but must NOT move due_at
        $o2 = $this->schoolOrder();
        $this->sys(fn () => $svc->coverOrder($o2));
        $inv = $this->sys(fn () => DB::table('consolidated_invoices')->where('programme_id', $this->programmeId)->first());
        $this->assertSame(500000, (int) $inv->original_amount_minor, 'original grew');
        $this->assertSame($due1, $inv->due_at, 'due_at did NOT move — the receivable clock is fixed at issuance');
    }

    // ── the UNIQUE (school, programme) makes a double invoice impossible ──────

    public function test_unique_blocks_a_second_invoice_for_the_pair(): void
    {
        $this->sys(fn () => app(ConsolidatedInvoiceService::class)->coverOrder($this->schoolOrder()));
        $this->assertSame(1, $this->sys(fn () => DB::table('consolidated_invoices')->where('programme_id', $this->programmeId)->count()));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->sys(fn () => DB::table('consolidated_invoices')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'programme_id' => $this->programmeId,
            'original_amount_minor' => 0, 'balance_minor' => 0, 'currency' => 'HKD', 'status' => 'issued',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    // ── aging: issued → overdue, and CHILDREN ENTIRELY UNTOUCHED ──────────────

    public function test_aging_marks_overdue_and_never_touches_the_enrolment(): void
    {
        $order = $this->schoolOrder();
        $this->sys(fn () => app(ConsolidatedInvoiceService::class)->coverOrder($order));
        $invoiceId = $this->invoiceId();
        // seed a live team membership for the same enrolment (the child is participating)
        $teamId = $this->sys(function () use ($order) {
            $lobby = (string) Str::uuid7();
            DB::table('team_categories')->insert(['id' => $lobby, 'programme_id' => $this->programmeId, 'name_en' => 'O', 'name_tc' => 'O', 'name_sc' => 'O', 'assignment_rule' => 'open', 'school_id' => null, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            $tid = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $tid, 'programme_id' => $this->programmeId, 'category_id' => $lobby, 'name' => 'T', 'status' => 'confirmed', 'created_by' => $this->ops->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'team_id' => $tid, 'enrolment_id' => $order->enrolment_id, 'category_id' => $lobby, 'student_id' => $order->student_id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

            return $tid;
        });
        // push the clock into the past (past grace)
        $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->update(['due_at' => now()->subDays(40)]));

        $aged = $this->sys(fn () => app(InvoiceAgingService::class)->run());
        $this->assertSame(1, $aged);
        $this->assertSame('overdue', $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->value('status')));

        // THE CHILD-WELFARE CHECK: nothing downstream moved
        $this->assertSame('confirmed', $this->sys(fn () => DB::table('enrolments')->where('id', $order->enrolment_id)->value('status')), 'enrolment NOT lapsed');
        $this->assertSame('covered_by_invoice', $this->sys(fn () => DB::table('orders')->where('id', $order->id)->value('status')), 'order NOT lapsed');
        $this->assertSame('active', $this->sys(fn () => DB::table('team_members')->where('enrolment_id', $order->enrolment_id)->value('status')), 'membership NOT suspended');
        $this->assertSame(0, $this->sys(fn () => DB::table('audit_events')->whereIn('entity_type', ['enrolment', 'order', 'team_member'])->whereIn('entity_id', [$order->enrolment_id, $order->id])->where('action', 'like', '%lapsed%')->count()), 'no lapse audit against a child');
    }

    // ── terminal fates: extendTerms (→issued, clock reset) and markPaid ───────

    public function test_extend_terms_returns_to_issued_and_resets_the_clock(): void
    {
        $order = $this->schoolOrder();
        $this->sys(fn () => app(ConsolidatedInvoiceService::class)->coverOrder($order));
        $invoiceId = $this->invoiceId();
        $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->update(['due_at' => now()->subDays(40)]));
        $this->sys(fn () => app(InvoiceAgingService::class)->run());

        $this->sys(fn () => app(InvoiceAgingService::class)->extendTerms($invoiceId, $this->ops));
        $inv = $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->first());
        $this->assertSame('issued', $inv->status);
        $this->assertGreaterThan(now()->addDays(20)->timestamp, strtotime($inv->due_at), 'clock reset forward');
    }

    public function test_mark_paid_is_the_resolved_on_pay_terminal_fate(): void
    {
        $order = $this->schoolOrder();
        $this->sys(fn () => app(ConsolidatedInvoiceService::class)->coverOrder($order));
        $invoiceId = $this->invoiceId();
        $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->update(['due_at' => now()->subDays(40)]));
        $this->sys(fn () => app(InvoiceAgingService::class)->run());

        $this->sys(fn () => app(InvoiceAgingService::class)->markPaid($invoiceId, $this->ops));
        $this->assertSame('paid', $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->value('status')));

        // paid is terminal — extendTerms (paid → issued) is an illegal transition
        $this->expectException(\RuntimeException::class);
        $this->sys(fn () => app(InvoiceAgingService::class)->extendTerms($invoiceId, $this->ops));
    }

    // ── no_silent_overdue teeth ───────────────────────────────────────────────

    public function test_no_silent_overdue_reds_on_an_unaged_invoice_then_greens(): void
    {
        $order = $this->schoolOrder();
        $this->sys(fn () => app(ConsolidatedInvoiceService::class)->coverOrder($order));
        $invoiceId = $this->invoiceId();
        $this->assertTrue($this->sys(fn () => (new NoSilentOverdueAssertion)->check()->passed), 'green while within terms');

        // clock into the past but NOT yet aged → red
        $this->sys(fn () => DB::table('consolidated_invoices')->where('id', $invoiceId)->update(['due_at' => now()->subDays(40)]));
        $this->assertFalse($this->sys(fn () => (new NoSilentOverdueAssertion)->check()->passed), 'reds: past due+grace still issued');

        // the sweep ages it → green
        $this->sys(fn () => app(InvoiceAgingService::class)->run());
        $this->assertTrue($this->sys(fn () => (new NoSilentOverdueAssertion)->check()->passed));
    }
}
