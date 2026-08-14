<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A-2 — a delegated capability held by a school (active = revoked_at IS NULL). Written ONLY by
 * AuthorityGrantService (system-context); the capability is always ∈ the A-1 delegable catalogue.
 */
class SchoolAuthorityGrant extends Model
{
    protected $table = 'school_authority_grants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'school_id', 'capability', 'granted_by', 'granted_at', 'revoked_by', 'revoked_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
