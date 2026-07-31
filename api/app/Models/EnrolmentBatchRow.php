<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of a bulk-enrolment CSV, with its dry-run disposition. Every
 * non-validated row carries a reason (P4 — never silently dropped).
 */
class EnrolmentBatchRow extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    // STEP 2 commit outcomes (from a 'validated' row)
    public const STATUS_ENROLLED = 'enrolled';

    public const STATUS_NOT_ENROLLED = 'not_enrolled';

    public const DISPOSITION_EXISTING = 'match_existing';

    public const DISPOSITION_NEW = 'new';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function batch()
    {
        return $this->belongsTo(EnrolmentBatch::class, 'batch_id');
    }
}
