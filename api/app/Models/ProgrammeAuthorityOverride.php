<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A-2 — a per-programme grant/withhold of a delegable capability (school_id NULL = all schools on the
 * programme). Current-state (one row per target); written ONLY by AuthorityGrantService (system-context);
 * the capability is always ∈ the A-1 delegable catalogue.
 */
class ProgrammeAuthorityOverride extends Model
{
    protected $table = 'programme_authority_overrides';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'programme_id', 'school_id', 'capability', 'mode', 'set_by', 'set_at',
    ];

    protected $casts = [
        'set_at' => 'datetime',
    ];
}
