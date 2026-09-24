<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecipeIngredientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedna linia listy składników.
 *
 * `ingredient_text` to dokładnie to, co wpisał człowiek — i to jest wersja
 * pokazywana użytkownikowi. `ingredient_id`, `quantity`, `unit_id` to wynik
 * normalizacji: dziś przydają się do wyszukiwania, a są też przygotowane pod
 * skalowanie porcji (plan V2, dziś niewdrożone). Nigdy nie nadpisują tekstu autora.
 */
class RecipeIngredient extends Model
{
    /** @use HasFactory<RecipeIngredientFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'recipe_id',
        // Śródtytuł części przepisu — „Ciasto", „Farsz", „Do podania" (D-033).
        // `null` znaczy „ten składnik nie należy do żadnej części" i jest
        // stanem NORMALNYM: większość przepisów nie ma grup. Kolejność grup
        // wynika z `position` składników, nie z osobnej kolumny; układem do
        // wyświetlenia zajmuje się `App\Domain\Recipes\GrupySkladnikow`.
        'group_name',
        'ingredient_id',
        'ingredient_text',
        'quantity',
        'unit_id',
        'note',
        'position',
        // „Ten składnik nie ma wymiernej ilości" — sól do smaku, mleko ile
        // weźmie (issue #44). Gdy powstanie skalowanie porcji (plan V2, dziś
        // niewdrożone), takiego składnika NIE WOLNO mnożyć: trzy szczypty soli są śmieszne, a trzy razy
        // „ile weźmie" nie znaczy nic.
        'no_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'position' => 'integer',
            'no_amount' => 'boolean',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
