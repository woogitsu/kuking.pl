<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property CarbonImmutable|null $expires_on
 * @property string|null $expiry_kind
 * @property string|null $quantity_note
 * @property bool $frozen
 */
class PantryItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * `expires_on`, `expiry_kind` i `frozen` NIE są w `$fillable`: ustawia je
     * wyłącznie nazwana akcja `ZmienTerminProduktu` po walidacji (#1903).
     * Ilość jest wolnym tekstem do 40 znaków (CHECK w bazie).
     */
    protected $fillable = [
        'name',
        'quantity_note',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'expires_on' => 'immutable_date',
            'frozen' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Drugie opakowanie tego samego produktu (#2568) — najwyżej jedno.
     * Pierwsze opakowanie to kolumny tego modelu.
     *
     * @return HasOne<PantrySecondPackage, $this>
     */
    public function secondPackage(): HasOne
    {
        return $this->hasOne(PantrySecondPackage::class, 'pantry_item_id');
    }
}
