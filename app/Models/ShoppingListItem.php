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
 * `scaled_servings` (#2489): NULL = dosłowna linia autora albo ręczny wpis;
 * liczba = ilość w `text` policzył Kuking z linii autora na tyle porcji.
 * Poza `$fillable`, ustawia wyłącznie `ListaZakupow`. Kolumnę dodaje
 * migracja surowym SQL-em, którego Larastan nie odczyta — stąd deklaracja.
 *
 * `list_id` (#2528): nazwana lista pozycji; `NULL` = lista domyślna. Kolumna
 * dochodzi surowym DDL-em (AGENTS.md §6), więc Larastan nie wyczyta jej z migracji.
 *
 * @property float|null $scaled_servings
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
            'scaled_servings' => 'float',
            'edited_at' => 'datetime',
        ];
    }

    /** Tekst poprawiony ręcznie przez właściciela listy (#2443) — nie jest już dosłowną linią. */
    public function jestPoprawiona(): bool
    {
        return $this->edited_at !== null;
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
