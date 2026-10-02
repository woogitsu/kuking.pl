<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedna pozycja prywatnej listy ostatnio oglądanych przepisów (#2553):
 * „ta osoba ostatnio oglądała ten przepis o tej porze”. Nic ponad to —
 * tytuł, zdjęcie i autora zawsze pobiera się na żywo z przepisu, który osoba
 * dziś widzi. `$fillable` jest pusty: wiersz zmienia wyłącznie
 * `App\Domain\Recipes\OstatnioOgladane`, a właściciel pochodzi z sesji.
 *
 * @property string $id
 * @property string $user_id
 * @property string $recipe_id
 * @property CarbonImmutable $viewed_at
 */
class RecentRecipeView extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'recent_recipe_views';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'immutable_datetime',
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
