<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExport extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'status',
        'disk',
        'object_key',
        'bytes',
        'completed_at',
        'expires_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'bytes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_READY
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
