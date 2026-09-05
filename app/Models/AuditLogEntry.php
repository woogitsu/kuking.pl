<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * Wpis w dzienniku audytu.
 *
 * Zasady: logujemy FAKT i AKTORA, nigdy treści ani tokenów. IP wyłącznie jako
 * hash — do wykrywania nadużyć wystarcza, a nie tworzy zbędnego zbioru danych
 * osobowych (docs/SECURITY_PRIVACY_LEGAL.md).
 */
class AuditLogEntry extends Model
{
    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'ip_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Zapisz zdarzenie w dzienniku.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $metadata = [],
        ?string $ip = null,
    ): self {
        return self::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'ip_hash' => $ip === null ? null : hash('sha256', $ip.config('app.key')),
            'metadata' => $metadata,
        ]);
    }
}
