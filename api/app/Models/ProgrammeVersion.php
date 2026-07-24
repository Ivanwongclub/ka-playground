<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable D5 snapshot — the DB trigger is the guard; this fails earlier. */
class ProgrammeVersion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('programme_versions is INSERT-only (D5)');
        });
        static::deleting(function (): never {
            throw new LogicException('programme_versions is INSERT-only (D5)');
        });
    }
}
