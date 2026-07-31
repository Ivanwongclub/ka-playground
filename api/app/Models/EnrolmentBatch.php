<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A school's bulk-enrolment operation (S04E, Spec Part H). STEP 1 takes it as
 * far as READY (a dry-run preview) or FAILED; STEP 2 commits it.
 */
class EnrolmentBatch extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_READY = 'ready';

    public const STATUS_COMMITTING = 'committing';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIALLY_COMPLETE = 'partially_complete';

    public const STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    public function rows()
    {
        return $this->hasMany(EnrolmentBatchRow::class, 'batch_id');
    }
}
