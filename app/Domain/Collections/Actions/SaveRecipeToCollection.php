<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Collections\ZamekZapisuDoZeszytu;
use App\Domain\Notifications\Actions\NotifyRecipeSaved;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
        // Oba skutki są zapisami tej samej bazy (#907). Istniejące powiązanie
        // jest znacznikiem zakończenia: zatwierdzamy je razem z powiadomieniem.
        // Nie sprawdzamy istnienia wiadomości, którą mogła usunąć retencja.
        //
        // Transakcję otwiera `ZamekZapisuDoZeszytu` (#1022): pod jego zamkami
        // dostajemy ŚWIEŻE konto, przepis i zeszyt, już sprawdzone przez
        // Policy — nie te odczytane w kontrolerze przed chwilą.
        return app(ZamekZapisuDoZeszytu::class)->zapisz(
            $user,
            $recipe,
            $collection,
            fn (User $user, Recipe $recipe, Collection $collection): Collection => $this->saveWithNotification($user, $recipe, $collection, $note),
        );
    }

    private function saveWithNotification(User $user, Recipe $recipe, Collection $collection, ?string $note): Collection
    {
        Gate::forUser($user)->authorize('update', $collection);

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
            DB::transaction(fn () => $collection->recipes()->attach($recipe->getKey(), [
                'note' => $note,
                'created_at' => now(),
            ]));
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
        //
        // Liczymy POD blokadą partii (przegląd PR #1213): dwa równoległe
        // zapisy tej samej osoby do dwóch jej zeszytów bez niej oba widzą
        // tylko własny, niezatwierdzony wiersz, oba liczą „1” i oba uznają
        // się za pierwszy zapis. Z blokadą drugi liczy dopiero po
        // zatwierdzeniu pierwszego i widzi „2”. Jesteśmy w transakcji
        // z `handle()`, więc blokada trzyma do jej końca.
        $this->notify->zablokujPartie($recipe);

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

    /**
     * Wyjęcie przepisu z zeszytu — z JEDNEGO, gdy wiadomo z którego (issue #775).
     *
     * CO TU BYŁO ŹLE
     * `remove()` chodziło po WSZYSTKICH zeszytach tej osoby i kasowało wiersz
     * w każdym z nich. Ekran przepisu wysyłał DELETE bez `collection_id`, więc
     * innego zachowania nie dało się nawet poprosić: jedno kliknięcie „Usuń
     * z zeszytu" przy przepisie leżącym w pięciu zeszytach kasowało pięć
     * wierszy. Razem z wierszem szła kolumna `note` — notatka własna
     * („mniej soli", „dla Ani bez orzechów"), której nie ma skąd odtworzyć:
     * `collection_items` nie ma miękkiego kasowania ani historii.
     * To jest wprost sprzeczne z „poprawne dane nigdy nie znikają" (AGENTS.md).
     *
     * CO ROBI TERAZ
     *  • `$collection` podany  → wyjmujemy WYŁĄCZNIE z tego zeszytu;
     *  • `$collection` pusty   → wyjmujemy ze wszystkich zeszytów tej osoby,
     *    bo tego wymaga stary kształt ekranu — ale zwracamy komplet zdjętych
     *    wierszy, żeby wywołujący mógł POWIEDZIEĆ, ile ich było, i mógł je
     *    przywrócić co do notatki (`restore()` niżej).
     *
     * Zakres zawsze ogranicza `$user->collections()`, więc identyfikator
     * w adresie nie sięga cudzego zeszytu (AGENTS.md §7) — tak było i tak
     * zostaje.
     *
     * @return list<array{collection_id: string, note: ?string, created_at: ?string}>
     *                                                                                Zdjęte wiersze w kolejności zdejmowania. Pusta lista znaczy
     *                                                                                „nie było czego zdejmować" i to NIE jest błąd.
     */
    public function remove(User $user, Recipe $recipe, ?Collection $collection = null): array
    {
        $zeszyty = $collection !== null
            // Przez `$user->collections()`, a nie prosto po `$collection` —
            // cudzy zeszyt ma tu wyjść jako brak zeszytu, a nie jako zeszyt.
            ? $user->collections()->whereKey($collection->getKey())->get()
            : $user->collections()->get();

        $zdjete = [];

        foreach ($zeszyty as $zeszyt) {
            $wiersz = $zeszyt->recipes()->whereKey($recipe->getKey())->first();

            if ($wiersz === null) {
                continue;
            }

            // Notatkę i datę zapisu czytamy PRZED `detach()`. Po nim nie ma
            // ich już nigdzie — to jest ten moment, w którym dane ginęły.
            $zdjete[] = [
                'collection_id' => (string) $zeszyt->getKey(),
                'note' => $wiersz->pivot->note,
                'created_at' => $wiersz->pivot->created_at === null
                    ? null
                    : (string) $wiersz->pivot->created_at,
            ];

            $zeszyt->recipes()->detach($recipe->getKey());
        }

        // Wyjęcie z JEDNEGO zeszytu nie wycofuje zapisu, dopóki przepis
        // leży w innym zeszycie tej osoby — powiadomienie za nią poszło
        // raz (#906) i zostaje, póki jej zapis trwa gdziekolwiek.
        $this->cofnijJesliNigdzieNieZostal($user, $recipe);

        return $zdjete;
    }

    /**
     * Droga powrotu: wiersze zdjęte przez `remove()` wracają tam, skąd zeszły.
     *
     * Wracają Z NOTATKĄ i z pierwotnym `created_at`, więc zeszyt nie przestawia
     * się na górę listy i nikt nie traci tego, co sam napisał. Samo „zapisz
     * ponownie" tego nie daje: zrobiłoby nowy wiersz z pustą notatką i dzisiejszą
     * datą, czyli zgubiłoby dokładnie to, o co chodzi w #775.
     *
     * @param  list<array{collection_id: string, note: ?string, created_at: ?string}>  $zdjete
     * @return int ile wierszy faktycznie wróciło
     */
    public function restore(User $user, Recipe $recipe, array $zdjete): int
    {
        // Pod blokadą partii, jak zapis i wycofanie (#906): „czy przepis
        // leżał już gdzieś u tej osoby” i dopisanie jej do partii muszą być
        // jednym krokiem względem równoległego zapisu do innego zeszytu.
        return DB::transaction(function () use ($user, $recipe, $zdjete): int {
            $this->notify->zablokujPartie($recipe);

            $lezalGdzies = $user->collections()
                ->whereHas('recipes', fn ($q) => $q->whereKey($recipe->getKey()))
                ->exists();

            $wrocilo = 0;

            foreach ($zdjete as $pozycja) {
                // Zeszyt mógł w międzyczasie zniknąć albo nigdy nie był tej osoby.
                $zeszyt = $user->collections()->whereKey($pozycja['collection_id'] ?? null)->first();

                if ($zeszyt === null) {
                    continue;
                }

                // Ktoś mógł zapisać przepis ponownie, zanim kliknął powrót —
                // wtedy zostawiamy to, co jest, zamiast nadpisywać świeższy wiersz.
                if ($zeszyt->recipes()->whereKey($recipe->getKey())->exists()) {
                    continue;
                }

                try {
                    // Punkt zapisu: w PostgreSQL błąd klucza w transakcji
                    // unieważnia ją całą, więc łapiemy go tylko wewnątrz
                    // zagnieżdżonej (SAVEPOINT), jak przy zwykłym zapisie.
                    DB::transaction(fn () => $zeszyt->recipes()->attach($recipe->getKey(), [
                        'note' => $pozycja['note'] ?? null,
                        'created_at' => $pozycja['created_at'] ?? now(),
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // Dwa kliknięcia „wróć" naraz — dla człowieka to jeden powrót.
                    continue;
                }

                $wrocilo++;
            }

            // Powrót, po którym przepis znów leży u osoby, która nie miała go
            // nigdzie, to dla autora ZAPIS od nowa (D-070): `remove()` wycofał
            // jej udział z partii, więc bez tego „Cofnij” oddawałoby przepis
            // do zeszytu, a powiadomienie o nim zostawiało zniknięte. Powrót
            // do jednego z kilku zeszytów niczego w partii nie zmienia — jak
            // zapis do drugiego zeszytu. Granice (aktywne konto, blokada,
            // własny przepis) sprawdza `NotifyRecipeSaved::handle()`.
            if ($wrocilo > 0 && ! $lezalGdzies) {
                $this->notify->handle($user, $recipe);
            }

            return $wrocilo;
        });
    }

    /**
     * Wycofanie PRZED przeczytaniem cofa też udział tej osoby w partii
     * zbiorczego powiadomienia — patrz `NotifyRecipeSaved::cofnij()`.
     * Tylko gdy przepisu nie ma już w ŻADNYM jej zeszycie: to lustro
     * warunku z zapisu, który powiadamia wyłącznie przy pierwszym zeszycie.
     */
    private function cofnijJesliNigdzieNieZostal(User $user, Recipe $recipe): void
    {
        // Ta sama blokada partii co przy zapisie: sprawdzenie „nigdzie nie
        // został” i wycofanie z partii muszą być jednym krokiem względem
        // równoległego zapisu tej osoby do innego zeszytu — inaczej zapis
        // liczy wyjmowany jeszcze zeszyt, nie powiadamia, a wycofanie
        // potem wyrzuca tę osobę z partii, choć przepis u niej leży.
        DB::transaction(function () use ($user, $recipe): void {
            $this->notify->zablokujPartie($recipe);

            $zostal = $user->collections()
                ->whereHas('recipes', fn ($q) => $q->whereKey($recipe->getKey()))
                ->exists();

            if (! $zostal) {
                $this->notify->cofnij($user, $recipe);
            }
        });
    }
}
