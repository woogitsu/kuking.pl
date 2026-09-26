<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kolekcja = zeszyt z przepisami. Domyślnie prywatna.
 */
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Czy oglądający w TYM żądaniu może wyjmować pozycje jako współpracownik
     * wspólnego zeszytu (#1743). Ustawia go `CollectionController::show()`
     * raz na stronę z `CollectionPolicy::removeItem()`, żeby karta wpisu nie
     * pytała bazy przy każdej pozycji. Zwykła właściwość, nie kolumna:
     * nigdy nie trafia do bazy ani do `toArray()`.
     */
    public bool $wyjmowanieDozwolone = false;

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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function recipes(): BelongsToMany
    {
        // `orderByDesc('recipes.id')` rozstrzyga remisy `created_at` na
        // złączeniu — uzasadnienie: `Recipe::cookedEvents()`.
        return $this->belongsToMany(Recipe::class, 'collection_items')
            ->withPivot(['note', 'created_at', 'added_by_id'])
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
            ->withPivot(['note', 'created_at', 'added_by_id'])
            ->orderByPivot('created_at', 'desc')
            ->orderByDesc('posts.id');
    }

    /**
     * Osoby zaproszone do wspólnego zapisywania (#1743, D-302).
     *
     * SAMO CZŁONKOSTWO NIE JEST DOSTĘPEM. O dostępie rozstrzyga
     * `CollectionPolicy` — sprawdza też stan obu kont i blokadę. Ta relacja
     * mówi tylko, kogo właściciel wpuścił.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'collection_members')
            ->withPivot(['created_at'])
            ->orderByPivot('created_at')
            ->orderBy('users.id');
    }

    /** @return HasMany<CollectionInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(CollectionInvitation::class);
    }

    /** Czy ta osoba jest wpisana jako współpracownik (bez oceny dostępu). */
    public function maCzlonka(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->members()->whereKey($user->getKey())->exists();
    }

    /**
     * Zeszyty, do których ta osoba może DOPISYWAĆ i z których może wyjmować:
     * własne oraz te, do których ją zaproszono (#1743).
     *
     * Warunki współpracownika są te same co w `CollectionPolicy`
     * (`dostepWspolpracownika()`): właściciel może czytać serwis (nie jest
     * zbanowany, nie kasuje konta), a między nimi nie ma blokady. Policy
     * pilnuje WEJŚCIA na zeszyt, ten zakres — LISTY do wyboru i reguły
     * walidacji. Blokada i tak kasuje członkostwo (`ZerwijWspoldzielenie`);
     * warunek tutaj jest drugą linią, nie jedyną.
     *
     * @param  Builder<Collection>  $query
     */
    public function scopeDostepneDoZapisuDla(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('collections.owner_id', $user->getKey())
            ->orWhere(fn (Builder $wspolne) => $wspolne
                ->where('collections.is_default', false)
                ->whereExists(fn ($czlonek) => $czlonek->selectRaw('1')
                    ->from('collection_members')
                    ->whereColumn('collection_members.collection_id', 'collections.id')
                    ->where('collection_members.user_id', $user->getKey()))
                ->whereHas('owner', fn (Builder $wlasciciel) => $wlasciciel->widocznyJakoOsoba())
                ->whereNotExists(fn ($blokada) => $blokada->selectRaw('1')
                    ->from('blocks')
                    ->where(fn ($para) => $para
                        ->where(fn ($a) => $a->whereColumn('blocks.blocker_id', 'collections.owner_id')->where('blocks.blocked_id', $user->getKey()))
                        ->orWhere(fn ($b) => $b->where('blocks.blocker_id', $user->getKey())->whereColumn('blocks.blocked_id', 'collections.owner_id'))))));
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }
}
