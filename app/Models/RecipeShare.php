<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Udostępnienie jednego przepisu wskazanej osobie — sam odczyt (#2650).
 *
 * `$fillable` jest PUSTE i to celowo: obie kolumny rozstrzygają o dostępie
 * do prywatnej treści, więc ustawia je wyłącznie nazwana akcja
 * `App\Domain\Recipes\Udostepnienia\UdostepnijPrzepis`. Autor przepisu nie
 * ma tu kolumny — jest nim `recipes.author_id`.
 *
 * Reguła dostępu NIE stoi w tym modelu: `RecipePolicy::readShared()`.
 *
 * @property string $id
 * @property string $recipe_id
 * @property string $recipient_id
 */
class RecipeShare extends Model
{
    use HasUuids;

    protected $fillable = [];

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
