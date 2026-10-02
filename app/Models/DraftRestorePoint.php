<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punkt odzyskania tekstu prywatnego szkicu przepisu (#2512).
 *
 * To nie jest wersja przepisu: nie ma go w historii, w eksporcie przepisu, na
 * stronie publicznej ani w kanale. Widzi go wyłącznie autor szkicu. Wiersz żyje
 * najwyżej `kuking.przepisy.szkic_punkt_odzyskania_dni` dni. `$fillable` jest
 * pusty — punkt tworzy i podmienia wyłącznie `PunktOdzyskaniaSzkicu`.
 *
 * @property string $id
 * @property string $recipe_id
 * @property string $user_id
 * @property array<string, mixed> $snapshot
 * @property CarbonImmutable $taken_at
 */
class DraftRestorePoint extends Model
{
    use HasUuids;

    protected $table = 'draft_restore_points';

    protected $fillable = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'taken_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
