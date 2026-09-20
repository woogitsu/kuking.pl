<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Notifications\Actions\NotifyRecipeSaved;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * "Zapisuję" — dodanie przepisu do zeszytu.
 *
 * Kolekcja "Zapisane" tworzy się sama przy pierwszym zapisie. Nikt nie musi
 * wymyślać nazwy folderu, żeby zachować przepis na potem — to jest dokładnie
 * ten moment, w którym połowa ludzi rezygnuje.
 */
final class SaveRecipeToCollection
{
    public function __construct(private readonly NotifyRecipeSaved $notify) {}

    public function handle(User $user, Recipe $recipe, ?Collection $collection = null, ?string $note = null): Collection
    {
        $collection ??= $user->defaultCollection();

        // DRUGIE KLIKNIĘCIE „ZAPISUJĘ” NIE JEST NOWYM ZAPISEM (issue #43).
        //
        // Klucz główny `collection_items (collection_id, recipe_id)` pilnował
        // bazy przed drugim wierszem, więc na pierwszy rzut oka wszystko było
        // w porządku. Ale `syncWithoutDetaching` na istniejącej parze robi
        // UPDATE, a nie nic — i to miało dwa widoczne skutki:
        //
        //  1. `created_at` w zeszycie było nadpisywane, więc przepis skakał
        //     na górę listy (zeszyt jest ułożony od najnowszego zapisu).
        //     Człowiek nie zrobił nic poza powtórzeniem tej samej akcji,
        //     a kolejność jego zeszytu się zmieniała;
        //  2. autor przepisu dostawał DRUGIE powiadomienie „ktoś zapisał
        //     Twój przepis" — od jednej osoby, za jedno zapisanie.
        //
        // Podwójne kliknięcie w grupie 50+ to norma, nie pomyłka, więc
        // sprawdzamy stan przed zapisem: dwa kliknięcia mają dać dokładnie
        // ten sam skutek co jedno.
        if ($collection->recipes()->whereKey($recipe->getKey())->exists()) {
            // Jedyne, co wolno tu zmienić, to notatka — jeśli ktoś ją
            // faktycznie podał. `created_at` zostaje takie, jak było.
            if ($note !== null) {
                $collection->recipes()->updateExistingPivot($recipe->getKey(), ['note' => $note]);
            }

            return $collection;
        }

        try {
            $collection->recipes()->attach($recipe->getKey(), [
                'note' => $note,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dwa kliknięcia potrafią wejść RÓWNOCZEŚNIE — wtedy oba przechodzą
            // sprawdzenie wyżej i drugie odbija się o klucz główny. Dla
            // człowieka to nadal jest jedno zapisanie, więc kończymy cicho,
            // bez błędu 500 i bez drugiego powiadomienia dla autora.
            return $collection;
        }

        // JEDNA OSOBA, KILKA SWOICH ZESZYTÓW = JEDEN ZAPIS (issue #906,
        // decyzja właściciela z 20.09.2026). Klucz główny na
        // `collection_items` broni tylko PARY (zeszyt, przepis) — ta sama
        // osoba, ten sam przepis, ale DRUGI jej zeszyt, przechodzi przez
        // niego bez przeszkód, więc dopiero tutaj liczymy, ile WŁASNYCH
        // zeszytów tej osoby ma już ten przepis. Więcej niż jeden (ten,
        // do którego dopiero co dopisaliśmy) znaczy, że powiadomienie za tę
        // osobę już poszło przy jej pierwszym zeszycie — nowe by je
        // zdublowało.
        $wlasneZeszytyZTymPrzepisem = $user->collections()
            ->whereHas('recipes', fn ($q) => $q->whereKey($recipe->getKey()))
            ->count();

        if ($wlasneZeszytyZTymPrzepisem > 1) {
            return $collection;
        }

        // Autor dowiaduje się, że ktoś odłożył jego przepis "na potem".
        // To jedno z najprzyjemniejszych powiadomień w serwisie — pierwsza
        // osoba dostaje je natychmiast, kolejne różne osoby dokładają się
        // do tej samej, jeszcze nieprzeczytanej wiadomości
        // (`NotifyRecipeSaved`, issue #906).
        $this->notify->handle($user, $recipe);

        return $collection;
    }

    public function remove(User $user, Recipe $recipe): void
    {
        $user->collections()->each(
            fn (Collection $collection) => $collection->recipes()->detach($recipe->getKey()),
        );

        // Wycofanie PRZED przeczytaniem cofa też udział tej osoby w partii
        // zbiorczego powiadomienia — patrz `NotifyRecipeSaved::cofnij()`.
        $this->notify->cofnij($user, $recipe);
    }
}
