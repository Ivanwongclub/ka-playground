<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A planned amount in a budget, per category (OD-18 minor units). Immutable once
 * the budget is active (BI-5, DB-enforced) — corrections are a new revision.
 */
class BudgetLine extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function budget()
    {
        return $this->belongsTo(TeamBudget::class, 'budget_id');
    }
}
