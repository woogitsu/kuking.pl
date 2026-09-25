<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Kolekcja = zeszyt z przepisami. Domyślnie prywatna.
 */
class Collection extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'visibility',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Recipe, $this>
     */
    public function recipes(): BelongsToMany
    {
        // `orderByDesc('recipes.id')` rozstrzyga remisy `created_at` na
        // złączeniu — uzasadnienie: `Recipe::cookedEvents()`.
        return $this->belongsToMany(Recipe::class, 'collection_items')
            ->withPivot(['note', 'created_at'])
            ->orderByPivot('created_at', 'desc')
            ->orderByDesc('recipes.id');
    }

    /**
     * Wpisy odłożone „na potem" (UI kit v2, ekran 01).
     *
     * Ta sama tabela co przepisy — `collection_items` ma dwie kolumny
     * dopuszczające NULL i CHECK `num_nonnulls(recipe_id, post_id) = 1`,
     * dokładnie jak `comments`. Wiersz jest więc ZAWSZE albo przepisem,
     * albo wpisem, nigdy jednym i drugim ani niczym.
     *
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        // Jak wyżej: bez drugiego klucza dwie rzeczy zapisane w tej samej
        // sekundzie potrafią się przestawić między stronami.
        return $this->belongsToMany(Post::class, 'collection_items')
            ->withPivot(['note', 'created_at'])
            ->orderByPivot('created_at', 'desc')
            ->orderByDesc('posts.id');
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }
}
