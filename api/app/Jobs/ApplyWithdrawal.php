<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Enrolments\EnrolmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** Applies an APPROVED withdrawal: the only path to `Withdrawn` (BI-7). */
class ApplyWithdrawal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $requestId, public readonly int $deciderId) {}

    public function handle(EnrolmentService $enrolments): void
    {
        $request = DB::table('withdrawal_requests')->where('id', $this->requestId)->first();
        if ($request === null || $request->status !== 'approved') {
            return;
        }
        $enrolment = DB::table('enrolments')->where('id', $request->enrolment_id)->first();
        if ($enrolment === null || $enrolment->status === 'withdrawn') {
            return; // idempotent
        }
        $enrolments->transition($request->enrolment_id, 'withdrawn',
            User::find($this->deciderId), "withdrawal request {$this->requestId} approved");
    }
}
