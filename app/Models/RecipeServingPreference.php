<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jawnie zapamiętana liczba porcji jednej osoby dla jednego przepisu (#2602).
 *
 * `$fillable` PUSTE: osobę i liczbę ustawia wyłącznie
 * `App\Domain\Recipes\Porcje\ZapamietanePorcje`, po sprawdzeniu Policy
 * i `WyborPorcji`; właściciel pochodzi z sesji, nigdy z żądania.
 *
 * @property string $id
 * @property string $user_id
 * @property string $recipe_id
 * @property float|string $servings
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class RecipeServingPreference extends Model
{
    use HasUuids;

    protected $table = 'recipe_serving_preferences';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'servings' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
