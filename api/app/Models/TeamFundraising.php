<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A team's project type + fundraising target (S07, Pitch stage). One per team. */
class TeamFundraising extends Model
{
    protected $table = 'team_fundraising';

    public const SPONSORSHIP = 'sponsorship';

    public const CHARITY = 'charity';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
