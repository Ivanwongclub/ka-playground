<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A team-project income/expense (S07, record-only). State machine (§P1):
 * draft → receipt_attached → submitted → under_review → approved → recorded → verified
 *                                                     \→ rejected
 */
class TeamTransaction extends Model
{
    public const DRAFT = 'draft';

    public const RECEIPT_ATTACHED = 'receipt_attached';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const RECORDED = 'recorded';

    public const VERIFIED = 'verified';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'occurred_on' => 'date',
        'over_budget_acknowledged' => 'boolean',
        'recorded_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}
