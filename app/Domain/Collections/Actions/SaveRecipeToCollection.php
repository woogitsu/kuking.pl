<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Zapisuję" — dodanie przepisu do zeszytu.
 *
 * Kolekcja "Zapisane" tworzy się sama przy pierwszym zapisie. Nikt nie musi
 * wymyślać nazwy folderu, żeby zachować przepis na potem — to jest dokładnie
 * ten moment, w którym połowa ludzi rezygnuje.
 */
final class SaveRecipeToCollection
{
    public function __construct(private readonly NotifyUser $notify) {}

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

        /*
         * ZAPIS I POWIADOMIENIE W JEDNEJ TRANSAKCJI (issue #772).
         *
         * Przedtem `attach()` i `NotifyUser::handle()` były dwoma osobnymi
         * zapisami. Wyjątek w powiadomieniu (poczta, kolejka, cokolwiek
         * rzuci `NotifyUser`) zostawiał przepis PRZYPIĘTY do zeszytu, a samo
         * żądanie kończyło się błędem. Ponowienie tego samego kliknięcia
         * trafiało w gałąź wyżej („już zapisane") i wracało PRZED
         * powiadomieniem — więc brakujące powiadomienie było już nie do
         * odzyskania żadnym ponowieniem.
         *
         * W ODRÓŻNIENIU OD ZGŁOSZEŃ (`NotifyReporterReceipt`, issue #797)
         * zapis do zeszytu nie ma tu własnego powodu, żeby przeżyć awarię
         * powiadomienia — to nie jest sprawa z obowiązkiem prawnym, tylko
         * prywatna półka. Cofnięcie OBU rzeczy naraz jest tu więc właściwym
         * zachowaniem: ponowienie tego samego kliknięcia po awarii robi
         * zapis i powiadomienie od nowa, tym razem razem albo wcale.
         *
         * `attach()` STOI W ZAGNIEŻDŻONEJ TRANSAKCJI (SAVEPOINT), NIE
         * W TEJ SAMEJ. Naruszenie klucza głównego (dwa równoległe kliknięcia)
         * zostawia połączenie PostgreSQL w stanie „aborted" aż do końca
         * transakcji — złapanie wyjątku w PHP samo w sobie tego nie leczy.
         * Zagnieżdżone `DB::transaction()` w tym miejscu robi realny
         * `SAVEPOINT`/`ROLLBACK TO SAVEPOINT`, więc transakcja zewnętrzna
         * (i możliwość zwrócenia `$collection` bez rzucania dalej) zostaje
         * sprawna.
         */
        return DB::transaction(function () use ($collection, $recipe, $user, $note): Collection {
            try {
                DB::transaction(function () use ($collection, $recipe, $note): void {
                    $collection->recipes()->attach($recipe->getKey(), [
                        'note' => $note,
                        'created_at' => now(),
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                // Dwa kliknięcia potrafią wejść RÓWNOCZEŚNIE — wtedy oba
                // przechodzą sprawdzenie wyżej i drugie odbija się o klucz
                // główny. Dla człowieka to nadal jest jedno zapisanie, więc
                // kończymy cicho, bez błędu 500 i bez drugiego powiadomienia
                // dla autora. Zagnieżdżona transakcja wyżej już cofnęła sam
                // nieudany `attach()` do SAVEPOINT-u — ta transakcja zostaje
                // sprawna do zwrócenia wyniku.
                return $collection;
            }

            // Autor dowiaduje się, że ktoś odłożył jego przepis "na potem".
            // To jedno z najprzyjemniejszych powiadomień w serwisie.
            $this->notify->handle(
                recipient: $recipe->author,
                type: Notification::TYPE_SAVED,
                actor: $user,
                data: [
                    'recipe_id' => $recipe->getKey(),
                    'recipe_title' => $recipe->title,
                    'recipe_slug' => $recipe->slug,
                ],
            );

            return $collection;
        });
    }

    public function remove(User $user, Recipe $recipe): void
    {
        $user->collections()->each(
            fn (Collection $collection) => $collection->recipes()->detach($recipe->getKey()),
        );
    }
}
