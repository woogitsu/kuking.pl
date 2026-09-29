<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zapamiętany na koncie postęp gotowania jednego przepisu (#2016).
 *
 * Wiersz istnieje tylko wtedy, gdy osoba świadomie włączyła synchronizację
 * dla tego przepisu. Wszystkie pola są STERUJĄCE albo należą do właściciela,
 * więc `$fillable` jest pusty: wiersz powstaje i zmienia się wyłącznie przez
 * `App\Domain\Recipes\Gotowanie\PostepGotowania`, a właściciel pochodzi
 * z sesji, nigdy z żądania.
 *
 * @property string $id
 * @property string $user_id
 * @property string $recipe_id
 * @property list<string> $done_step_ids
 * @property float|string|null $servings
 * @property list<string> $prepared_ingredient_ids
 * @property int $revision
 * @property CarbonImmutable $expires_at
 */
class CookingProgress extends Model
{
    use HasUuids;

    protected $table = 'cooking_progress';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'done_step_ids' => 'array',
            'prepared_ingredient_ids' => 'array',
            'revision' => 'integer',
            'expires_at' => 'immutable_datetime',
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
        return $this->belongsTo(Recipe::class)->withTrashed();
    }
}
