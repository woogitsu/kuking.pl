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
 * @property float|null $scaled_servings
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
        ];
    }

    public function jestOdhaczona(): bool
    {
        return $this->checked_at !== null;
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
