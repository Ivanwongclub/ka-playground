<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A file taken in through the shared upload service (2.12). Invisible until the
 * ClamAV scan passes (BI-10): nothing may serve or reference a file whose
 * status is not 'clean'.
 */
class Upload extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLEAN = 'clean';

    public const STATUS_QUARANTINED = 'quarantined';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'immutable_datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function isVisible(): bool
    {
        return $this->status === self::STATUS_CLEAN;
    }
}
