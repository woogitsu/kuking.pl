<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeden produkt z prywatnej listy „Co mam w domu” (V2, D-285).
 *
 * `rdzenie` i `klucz` to kolumny GENEROWANE w bazie z `name` przez
 * `public.kuking_rdzenie_skladnika()` — nie zapisuje się ich z PHP.
 * `user_id` nie jest w `$fillable`: wiersz powstaje wyłącznie przez
 * relację `$user->pantryItems()`, więc właściciel pochodzi z sesji,
 * nigdy z żądania.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $klucz
 */
class PantryItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'name',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
