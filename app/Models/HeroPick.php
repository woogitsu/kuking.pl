<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedno zdjęcie wskazane ręcznie do kolażu w hero strony powitalnej.
 *
 * Wiersz tej tabeli NIE JEST zgodą na pokazanie zdjęcia — jest wskazaniem.
 * O tym, czy zdjęcie w ogóle wolno pokazać nieznajomemu, rozstrzyga
 * `App\Domain\Feed\HeroKolaz` przy każdym wyświetleniu, na podstawie
 * bieżącego stanu wpisu i konta autora.
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

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'curator_id');
    }
}
