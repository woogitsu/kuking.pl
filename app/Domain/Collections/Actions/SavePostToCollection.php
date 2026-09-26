<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Collections\ZamekZapisuDoZeszytu;
use App\Models\Collection;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * „Zapisz" na karcie wpisu (UI kit v2, ekran 01) — decyzja właściciela.
 *
 * PO CO ZAPISYWAĆ WPIS, SKORO JEST ZESZYT PRZEPISÓW
 * To są dwie różne potrzeby. Zapisany przepis znaczy „chcę to ugotować i mam
 * listę składników". Zapisane zdjęcie znaczy „chcę kiedyś zrobić coś TAKIEGO" —
 * i tego drugiego nie da się wyrazić przepisem, bo przy wpisie żadnego
 * przepisu zwykle nie ma.
 *
 * BLIŹNIAK `SaveRecipeToCollection` I TO JEST CELOWE
 * Ta sama ochrona przed podwójnym kliknięciem, ta sama domyślna kolekcja,
 * ten sam wyjątek na wyścig dwóch żądań. Podwójne kliknięcie w grupie 50+
 * to norma, nie pomyłka (issue #43).
 *
 * CZEGO TU NIE MA: POWIADOMIENIA DLA AUTORA
 * Przy przepisie autor dostaje „ktoś zapisał Twój przepis" i to jest jedno
 * z najprzyjemniejszych powiadomień w serwisie. Przy wpisie świadomie tego
 * nie ma — ale nie dlatego, że byłoby niemiłe. Ekran powiadomień renderuje
 * KAŻDY typ osobno, więc nowy typ bez własnego tekstu dałby pusty wiersz.
 * To jest praca do zrobienia razem z treścią komunikatu, nie przy okazji.
 */
final class SavePostToCollection
{
    public function handle(User $user, Post $post, ?Collection $collection = null, ?string $note = null): Collection
    {
        // Ta sama granica co przy przepisie (#1022) — uzasadnienie
        // w `ZamekZapisuDoZeszytu`.
        return app(ZamekZapisuDoZeszytu::class)->zapisz(
            $user,
            $post,
            $collection,
            fn (User $user, Post $post, Collection $collection): Collection => $this->save($user, $post, $collection, $note),
        );
    }

    private function save(User $user, Post $post, Collection $collection, ?string $note): Collection
    {
        Gate::forUser($user)->authorize('update', $collection);

        if ($collection->posts()->whereKey($post->getKey())->exists()) {
            if ($note !== null) {
                $collection->posts()->updateExistingPivot($post->getKey(), ['note' => $note]);
            }

            return $collection;
        }

        try {
            // Savepoint: jesteśmy w transakcji zamka, a złapane 23505 bez
            // niego zostawiłoby ją w stanie „transaction aborted”.
            DB::transaction(fn () => $collection->posts()->attach($post->getKey(), [
                'note' => $note,
                'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Dwa żądania równocześnie: oba przeszły sprawdzenie wyżej, drugie
            // odbiło się o indeks częściowy. Dla człowieka to nadal jedno
            // zapisanie, więc kończymy cicho.
            return $collection;
        }

        return $collection;
    }

    /**
     * Wyjęcie wpisu z zeszytu — bliźniak `SaveRecipeToCollection::remove()`.
     *
     * Ta sama wada i ta sama naprawa co przy przepisie (issue #775): wiersz
     * `collection_items` niesie kolumnę `note`, a kasowanie po wszystkich
     * zeszytach naraz zabierało ją bez ostrzeżenia i bez możliwości odtworzenia.
     * Uzasadnienie w komplecie stoi przy przepisie — tu nie powtarzamy go
     * drugi raz, żeby nie rozjechało się między bliźniakami.
     *
     * @return list<array{collection_id: string, note: ?string, created_at: ?string}>
     */
    public function remove(User $user, Post $post, ?Collection $collection = null): array
    {
        $zeszyty = $collection !== null
            ? $user->collections()->whereKey($collection->getKey())->get()
            : $user->collections()->get();

        $zdjete = [];

        foreach ($zeszyty as $zeszyt) {
            $wiersz = $zeszyt->posts()->whereKey($post->getKey())->first();

            if ($wiersz === null) {
                continue;
            }

            $zdjete[] = [
                'collection_id' => (string) $zeszyt->getKey(),
                'note' => $wiersz->pivot->note,
                'created_at' => $wiersz->pivot->created_at === null
                    ? null
                    : (string) $wiersz->pivot->created_at,
            ];

            $zeszyt->posts()->detach($post->getKey());
        }

        return $zdjete;
    }

    /**
     * Droga powrotu — bliźniak `SaveRecipeToCollection::restore()`.
     *
     * @param  list<array{collection_id: string, note: ?string, created_at: ?string}>  $zdjete
     */
    public function restore(User $user, Post $post, array $zdjete): int
    {
        $wrocilo = 0;

        foreach ($zdjete as $pozycja) {
            $zeszyt = $user->collections()->whereKey($pozycja['collection_id'] ?? null)->first();

            if ($zeszyt === null) {
                continue;
            }

            if ($zeszyt->posts()->whereKey($post->getKey())->exists()) {
                continue;
            }

            try {
                $zeszyt->posts()->attach($post->getKey(), [
                    'note' => $pozycja['note'] ?? null,
                    'created_at' => $pozycja['created_at'] ?? now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $wrocilo++;
        }

        return $wrocilo;
    }

    /** Czy ta osoba ma już ten wpis w którymkolwiek ze swoich zeszytów. */
    public function czyZapisany(User $user, Post $post): bool
    {
        return $post->collections()
            ->where('collections.owner_id', $user->getKey())
            ->exists();
    }
}
