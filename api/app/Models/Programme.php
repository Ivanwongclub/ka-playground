<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Programme extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enrolment_opens_at' => 'immutable_datetime',
            'enrolment_closes_at' => 'immutable_datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProgrammeVersion::class);
    }
}
