<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A team's project budget (S07, record-only). State machine (§P1):
 * draft → submitted → under_review → approved → active → closed
 *                                  \→ changes_requested → draft
 */
class TeamBudget extends Model
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const APPROVED = 'approved';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    /** Lines are editable only in these states (BI-5 freeze from submitted onward). */
    public const EDITABLE = [self::DRAFT, self::CHANGES_REQUESTED];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['activated_at' => 'datetime'];

    public function lines()
    {
        return $this->hasMany(BudgetLine::class, 'budget_id');
    }
}
