<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Metadane zdjęcia. Sam plik żyje w object storage pod `object_key`.
 *
 * Widoki NIGDY nie pokazują zdjęcia, które nie jest `ready` — dzięki temu
 * niezweryfikowany plik (albo taki z jeszcze nieusuniętym GPS-em z EXIF)
 * nie wycieka na stronę.
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'media';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'owner_id',
        'disk',
        'object_key',
        'mime_type',
        'bytes',
        'width',
        'height',
        'status',
        'alt_text',
        'checksum_sha256',
        'perceptual_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Publiczny URL wariantu zdjęcia.
     *
     * `$variant` to jeden z kluczy config('kuking.media.variants').
     * Jeśli wariant nie został jeszcze wygenerowany, wracamy do oryginału —
     * lepiej pokazać większe zdjęcie niż pustą ramkę.
     */
    public function url(string $variant = 'feed'): string
    {
        $key = $this->metadata['variants'][$variant]['key'] ?? $this->object_key;

        return Storage::disk($this->disk)->url($key);
    }

    public function width(string $variant = 'feed'): ?int
    {
        return $this->metadata['variants'][$variant]['width'] ?? $this->width;
    }

    public function height(string $variant = 'feed'): ?int
    {
        return $this->metadata['variants'][$variant]['height'] ?? $this->height;
    }
}
