<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedna pozycja prywatnej listy zakupów (#27, etap 2, D-333).
 *
 * Pozycja to TEKST: wpisany ręcznie albo skopiowana dosłownie linia składnika
 * przepisu. W `$fillable` stoi wyłącznie `text` — pochodzenie (`source`),
 * przepis (`recipe_id`), właściciel (`user_id`), kolejność i stan odhaczenia
 * ustawia akcja domenowa (`ListaZakupow`) jawnym przypisaniem, nigdy żądanie
 * (AGENTS.md §7: pola sterujące i klucze właściciela poza `$fillable`).
 *
 * `list_id` (#2528): nazwana lista pozycji; `NULL` = lista domyślna. Kolumna
 * dochodzi surowym DDL-em (AGENTS.md §6), więc Larastan nie wyczyta jej z migracji.
 *
 * @property string|null $list_id
 */
class ShoppingListItem extends Model
{
    use HasUuids;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_RECIPE = 'recipe';

    protected $fillable = [
        'text',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function jestOdhaczona(): bool
    {
        return $this->checked_at !== null;
    }

    /**
     * Nazwana lista pozycji; `list_id = NULL` to lista domyślna („Na co dzień”).
     *
     * @return BelongsTo<ShoppingList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'list_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
