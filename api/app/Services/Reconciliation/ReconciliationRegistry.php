<?php

namespace App\Services\Reconciliation;

use RuntimeException;

/**
 * Registry every sprint adds its assertions to (via ReconciliationServiceProvider).
 * There is no unregister, skip or disable — deliberately (CLAUDE.md §2.7).
 */
class ReconciliationRegistry
{
    /** @var array<string, Assertion> */
    private array $assertions = [];

    public function register(Assertion $assertion): void
    {
        $key = $assertion->key();
        if (isset($this->assertions[$key])) {
            throw new RuntimeException("Reconciliation assertion key collision: '{$key}'");
        }
        $this->assertions[$key] = $assertion;
    }

    /** @return array<string, Assertion> keyed by assertion key; optionally tag-filtered */
    public function matching(?string $tag = null): array
    {
        if ($tag === null) {
            return $this->assertions;
        }

        return array_filter(
            $this->assertions,
            fn (Assertion $a): bool => in_array($tag, $a->tags(), true),
        );
    }
}
