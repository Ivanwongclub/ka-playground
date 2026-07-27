<?php

namespace App\Services\Money;

use Illuminate\Support\Str;

/** Drives the full Phase-1 flow (OD-46). Outcome switchable for tests. */
class MockProvider implements PaymentProvider
{
    public function __construct(private readonly bool $succeeds = true) {}

    public function createSession(string $orderId, int $amountMinor, string $currency): string
    {
        return 'mock_'.Str::random(24);
    }

    public function confirm(string $providerRef): bool
    {
        return $this->succeeds;
    }
}
