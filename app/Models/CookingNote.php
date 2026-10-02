<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prywatny roboczy dopisek osoby do jednego przepisu, zrobiony w trakcie
 * gotowania (#2587). Nie jest wykonaniem ani wpisem: nikt poza właścicielem go
 * nie widzi. `$fillable` jest pusty — właściciel pochodzi z sesji, a wiersz
 * zmienia wyłącznie `App\Domain\Recipes\Gotowanie\RoboczyDopisek`.
 *
 * @property string $id
 * @property string $user_id
 * @property string $recipe_id
 * @property string $body
 * @property int $revision
 * @property CarbonImmutable $expires_at
 */
class CookingNote extends Model
{
    use HasUuids;

    protected $table = 'cooking_notes';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
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
