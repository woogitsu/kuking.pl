<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kolekcja = zeszyt z przepisami. Domyślnie prywatna.
 */
class Collection extends Model
{
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
        // RĘCZNA KOLEJNOŚĆ (#2544): przepisy z pozycją idą pierwsze, rosnąco;
        // bez pozycji (`NULL`) — jak zawsze, od najnowszego zapisu. W zeszycie,
        // którego nikt nie układał, wszystkie pozycje są `NULL`, więc wynik
        // jest dokładnie dawny. Przepis dopisany do ułożonego zeszytu dostaje
        // pozycję na końcu już przy zapisie (`KolejnoscPrzepisow`).
        //
        // `orderByDesc('recipes.id')` rozstrzyga remisy `created_at` na
        // złączeniu — uzasadnienie: `Recipe::cookedEvents()`.
        return $this->belongsToMany(Recipe::class, 'collection_items')
            ->withPivot(['note', 'created_at', 'added_by_id', 'position'])
            ->orderByRaw('collection_items.position ASC NULLS LAST')
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
        // Kształt `owner_id = ? OR id IN (podzapytanie od członkostw)` zamiast
        // `OR EXISTS(...)` skorelowanego z każdym wierszem `collections`: ten
        // drugi zmuszał planer do Seq Scan po całej tabeli (koszt ok. 137 tys.
        // przy 6 tys. zeszytów, ponad `jit_above_cost`, więc JIT przy każdej
        // stronie z kartami). Podzapytanie startuje od `collection_members`
        // (PK i indeks `user_id`) i trafia w kilka wierszy (audyt wydajności W1).
        $osoba = $user->getKey();

        $query->where(fn (Builder $q) => $q
            ->where('collections.owner_id', $osoba)
            ->orWhereIn('collections.id', fn ($wspolne) => $wspolne->select('collection_members.collection_id')
                ->from('collection_members')
                ->join('collections as zeszyt_wspolny', 'zeszyt_wspolny.id', '=', 'collection_members.collection_id')
                ->join('users as wlasciciel_wspolny', 'wlasciciel_wspolny.id', '=', 'zeszyt_wspolny.owner_id')
                ->where('collection_members.user_id', $osoba)
                ->where('zeszyt_wspolny.is_default', false)
                ->whereNotIn('wlasciciel_wspolny.status', User::STATUSY_ZAMKNIETEGO_KONTA)
                ->whereNotExists(fn ($blokada) => $blokada->selectRaw('1')
                    ->from('blocks')
                    ->where(fn ($para) => $para
                        ->where(fn ($a) => $a->whereColumn('blocks.blocker_id', 'zeszyt_wspolny.owner_id')->where('blocks.blocked_id', $osoba))
                        ->orWhere(fn ($b) => $b->where('blocks.blocker_id', $osoba)->whereColumn('blocks.blocked_id', 'zeszyt_wspolny.owner_id'))))));
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }
}
