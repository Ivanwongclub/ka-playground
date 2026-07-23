<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Read model for audit_events. INSERT-only (BI-1): the database trigger is the
 * load-bearing guard; the model-level exceptions just fail earlier and clearer.
 * All writes go through App\Services\Audit\AuditService — no other write path.
 */
class AuditEvent extends Model
{
    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'payload_before' => 'array',
            'payload_after' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('audit_events is INSERT-only (BI-1): UPDATE blocked');
        });
        static::deleting(function (): never {
            throw new LogicException('audit_events is INSERT-only (BI-1): DELETE blocked');
        });
    }
}
