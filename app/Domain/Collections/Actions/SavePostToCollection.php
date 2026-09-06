<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Models\Collection;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

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
        $collection ??= $user->defaultCollection();

        if ($collection->posts()->whereKey($post->getKey())->exists()) {
            if ($note !== null) {
                $collection->posts()->updateExistingPivot($post->getKey(), ['note' => $note]);
            }

            return $collection;
        }

        try {
            $collection->posts()->attach($post->getKey(), [
                'note' => $note,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dwa żądania równocześnie: oba przeszły sprawdzenie wyżej, drugie
            // odbiło się o indeks częściowy. Dla człowieka to nadal jedno
            // zapisanie, więc kończymy cicho.
            return $collection;
        }

        return $collection;
    }

    public function remove(User $user, Post $post): void
    {
        $user->collections()->each(
            fn (Collection $collection) => $collection->posts()->detach($post->getKey()),
        );
    }

    /** Czy ta osoba ma już ten wpis w którymkolwiek ze swoich zeszytów. */
    public function czyZapisany(User $user, Post $post): bool
    {
        return $post->collections()
            ->where('collections.owner_id', $user->getKey())
            ->exists();
    }
}
