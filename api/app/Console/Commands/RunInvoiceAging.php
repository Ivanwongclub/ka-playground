<?php

namespace App\Console\Commands;

use App\Services\Money\InvoiceAgingService;
use Illuminate\Console\Command;

/**
 * S04F STEP 3 (OD-55): age school-settled invoices past due_at + grace to
 * `overdue`. Touches ONLY consolidated_invoices — never a child's enrolment.
 * Scheduled daily alongside the other collections sweeps.
 */
class RunInvoiceAging extends Command
{
    protected $signature = 'invoices:age-school-settled';

    protected $description = 'Age unpaid school-settled invoices past their terms+grace to overdue (OD-55) — students untouched';

    public function handle(InvoiceAgingService $service): int
    {
        $aged = $service->run();
        $this->info("Invoice aging: {$aged} school-settled invoice(s) aged to overdue.");

        return self::SUCCESS;
    }
}
