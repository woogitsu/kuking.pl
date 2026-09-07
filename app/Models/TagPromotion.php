<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Promocja tagu — lista gospodarza (D-021, „tag promowany — lista
 * gospodarza"). Uzasadnienie kształtu tabeli jest w migracji
 * `2026_09_07_100100_create_tag_promotions_table`.
 *
 * Klucz główny to `tag_id` — jeden tag ma najwyżej jedną promocję, więc
 * osobne `id` byłoby surogatem bez zastosowania (ten sam wybór co
 * `Profile::user_id`).
 */
class TagPromotion extends Model
{
    protected $primaryKey = 'tag_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tag_id',
        'position',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
