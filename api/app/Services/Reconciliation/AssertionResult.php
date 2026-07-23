<?php

namespace App\Services\Reconciliation;

final readonly class AssertionResult
{
    private function __construct(
        public bool $passed,
        public string $details,
    ) {}

    public static function pass(string $details = ''): self
    {
        return new self(true, $details);
    }

    public static function fail(string $details): self
    {
        return new self(false, $details);
    }
}
