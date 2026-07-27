<?php

namespace App\Services\Reconciliation\Assertions;

use App\Models\Programme;
use App\Services\Programmes\WizardService;
use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;

/** OD-33 as a standing invariant, not just a publish/edit gate. */
class DeadlineOrderingAssertion implements Assertion
{
    public function key(): string
    {
        return 'deadline.ordering';
    }

    public function proves(): string
    {
        return 'no published programme violates enrolment close < formation deadline < programme start';
    }

    public function cites(): string
    {
        return 'OD-33 · A12';
    }

    public function tags(): array
    {
        return ['S04A'];
    }

    public function check(): AssertionResult
    {
        $wizard = app(WizardService::class);
        $failures = [];
        $programmes = Programme::query()->where('status', 'published')->get();
        foreach ($programmes as $programme) {
            $violation = $wizard->deadlineOrderingViolation($programme);
            if ($violation !== null) {
                $failures[] = "{$programme->code}: {$violation}";
            }
        }

        return $failures !== []
            ? AssertionResult::fail(implode(' · ', $failures))
            : AssertionResult::pass($programmes->count().' published programme(s) checked'.($programmes->isEmpty() ? ' (vacuous)' : ', timelines ordered or unset'));
    }
}
