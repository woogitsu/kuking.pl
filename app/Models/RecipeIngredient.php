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
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'position' => 'integer',
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
