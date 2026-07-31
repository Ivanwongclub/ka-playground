<?php

namespace App\Services\Enrolment;

use RuntimeException;

/**
 * A whole-file CSV defect (wrong columns, bad encoding, empty, over cap). It
 * rejects the ENTIRE file — no row is ever partial-parsed into the batch — and
 * fails the batch with this message as the reason.
 */
class StructuralParseException extends RuntimeException {}
