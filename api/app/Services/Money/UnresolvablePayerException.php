<?php

namespace App\Services\Money;

use RuntimeException;

/**
 * S04F STEP 1 — raised when a programme's E6 payer cannot be resolved to a
 * concrete obligation payer. It is a LOUD failure by design (D-18): a
 * school-paid programme whose student has no single active school roll must
 * NEVER silently fall back to a guardian obligation (which would drop the order
 * from the invoice branch — the exact bug the wire fixes). The caller's
 * transaction aborts; a critical log is emitted before the throw.
 */
class UnresolvablePayerException extends RuntimeException {}
