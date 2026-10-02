<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Drugie opakowanie produktu z „Co mam w domu” (#2568, V2): własny termin,
 * rodzaj terminu, ilość (wolny tekst) i „mrożone”. Najwyżej jedno na produkt
 * (`UNIQUE (pantry_item_id)`); pierwsze opakowanie to kolumny `pantry_items`.
 *
 * Wiersz powstaje i zmienia się wyłącznie przez akcję domenową
 * `DrugieOpakowanieProduktu`, więc w `$fillable` nie ma żadnej kolumny:
 * ani `pantry_item_id` (właściciel pochodzi z produktu), ani terminu,
 * rodzaju i `frozen` (pola sterujące listy, jak w `PantryItem`).
 *
 * @property string $id
 * @property string $pantry_item_id
 * @property CarbonImmutable|null $expires_on
 * @property string|null $expiry_kind
 * @property string|null $quantity_note
 * @property bool $frozen
 */
class PantrySecondPackage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'expires_on' => 'immutable_date',
            'frozen' => 'boolean',
        ];
    }

    /** @return BelongsTo<PantryItem, $this> */
    public function produkt(): BelongsTo
    {
        return $this->belongsTo(PantryItem::class, 'pantry_item_id');
    }
}
