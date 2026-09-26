<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Jedno zdjęcie wskazane ręcznie do kolażu w hero strony powitalnej.
 *
 * Wiersz tej tabeli NIE JEST zgodą na pokazanie zdjęcia — jest wskazaniem.
 * O tym, czy zdjęcie w ogóle wolno pokazać nieznajomemu, rozstrzyga
 * `App\Domain\Feed\HeroKolaz` przy każdym wyświetleniu, na podstawie
 * bieżącego stanu wpisu i konta autora.
 *
 * Kolumny `hero_picks` wypisane jawnie: migracja podaje nazwę tabeli stałą
 * klasy, a takiej migracji Larastan nie odczyta (#1731).
 *
 * @property string $media_id
 * @property string $post_id
 * @property int $position
 * @property string|null $curator_id
 * @property Carbon|null $created_at
 */
class HeroPick extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'media_id',
        'post_id',
        'position',
        'curator_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'curator_id');
    }
}
