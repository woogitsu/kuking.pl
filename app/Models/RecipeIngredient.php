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
 * normalizacji: przydają się do wyszukiwania i przyszłego skalowania porcji,
 * ale nigdy nie nadpisują tekstu autora.
 */
class RecipeIngredient extends Model
{
    /** @use HasFactory<RecipeIngredientFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'recipe_id',
        'group_name',
        'ingredient_id',
        'ingredient_text',
        'quantity',
        'unit_id',
        'note',
        'position',
        // „Ten składnik nie ma wymiernej ilości" — sól do smaku, mleko ile
        // weźmie (issue #44). Przy skalowaniu porcji (V2) takiego składnika
        // się NIE mnoży: trzy szczypty soli są śmieszne, a trzy razy
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
