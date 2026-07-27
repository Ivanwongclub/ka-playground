<?php

namespace App\Services\Money;

/** OD-46: the interface Phase 1 builds against; QFPay implements it in S-QFPAY. */
interface PaymentProvider
{
    /** @return string provider session/charge reference */
    public function createSession(string $orderId, int $amountMinor, string $currency): string;

    /** Confirms the charge. Provider payments self-confirm (OD-47 — out of BI-9). */
    public function confirm(string $providerRef): bool;
}
