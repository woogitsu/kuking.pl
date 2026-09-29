<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Collections\ZamekZapisuDoZeszytu;
use App\Domain\Notifications\Actions\NotifyRecipeSaved;
use App\Exceptions\BladDlaCzlowieka;
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
        // `addItem`, nie `update`: dopisywać może też współpracownik
        // wspólnego zeszytu (#1743), a zmieniać nazwę i widoczność — nie.
        Gate::forUser($user)->authorize('addItem', $collection);

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
                // Kto dodał — widać przy pozycji we wspólnym zeszycie (D-302).
                'added_by_id' => $user->getKey(),
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
     * @return list<array{collection_id: string, note: ?string, created_at: ?string, added_by_id?: ?string}>
     *                                                                                                       Zdjęte wiersze w kolejności zdejmowania. Pusta lista znaczy
     *                                                                                                       „nie było czego zdejmować" i to NIE jest błąd.
     */
    public function remove(User $user, Recipe $recipe, ?Collection $collection = null): array
    {
        // JEDNA TRANSAKCJA NA CAŁE WYJĘCIE (issue #1384). Bez niej każdy
        // `detach()` zatwierdzał się osobno: awaria przy drugim zeszycie
        // zostawiała pierwszy już pusty, a człowiek dostawał błąd zamiast
        // zdania, co zniknęło — razem z notatką, której nie ma skąd odtworzyć.
        // Teraz albo zeszły wszystkie wskazane wiersze, albo żaden, a lista
        // zdjętych (i komunikat z ich liczbą) wraca dopiero po zatwierdzeniu.
        return DB::transaction(fn (): array => $this->zdejmij($user, $recipe, $collection));
    }

    /** @return list<array{collection_id: string, note: ?string, created_at: ?string}> */
    private function zdejmij(User $user, Recipe $recipe, ?Collection $collection): array
    {
        $zeszyty = $collection !== null
            // Przez `$user->collections()`, a nie prosto po `$collection` —
            // cudzy zeszyt ma tu wyjść jako brak zeszytu, a nie jako zeszyt.
            // Wskazany zeszyt: własny ALBO wspólny z ważnym dostępem (#1743).
            // Bez wskazania — wyłącznie własne: „ze wszystkich moich" nigdy
            // nie sięga do cudzego zeszytu, nawet wspólnego.
            ? Collection::query()->dostepneDoZapisuDla($user)->whereKey($collection->getKey())->get()
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
                'added_by_id' => $wiersz->pivot->added_by_id === null ? null : (string) $wiersz->pivot->added_by_id,
                'created_at' => $wiersz->pivot->created_at === null
                    ? null
                    : (string) $wiersz->pivot->created_at,
            ];

            $zeszyt->recipes()->detach($recipe->getKey());
        }

        // Wyjęcie z JEDNEGO zeszytu nie wycofuje zapisu, dopóki przepis
        // leży w innym zeszycie tej osoby — powiadomienie za nią poszło
        // raz (#906) i zostaje, póki jej zapis trwa gdziekolwiek.
        $this->notify->cofnijJesliNigdzieNieZostal($user, $recipe);

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
     * @param  list<array{collection_id: string, note: ?string, created_at: ?string, added_by_id?: ?string}>  $zdjete
     * @return int ile wierszy faktycznie wróciło
     */
    public function restore(User $user, Recipe $recipe, array $zdjete): int
    {
        usort($zdjete, static fn (array $a, array $b): int => strcmp(
            (string) ($a['collection_id'] ?? ''),
            (string) ($b['collection_id'] ?? ''),
        ));

        // Zamki konta, treści i kolejnych zeszytów z `zapisz()` są trzymane
        // do końca tej transakcji. Powiadomienie też jest jej częścią.
        return DB::transaction(function () use ($user, $recipe, $zdjete): int {
            $wrocilo = 0;
            $swiezyKucharz = null;
            $swiezyPrzepis = null;

            foreach ($zdjete as $pozycja) {
                // Zeszyt mógł w międzyczasie zniknąć albo nigdy nie był tej osoby.
                $zeszyt = Collection::query()->dostepneDoZapisuDla($user)->whereKey($pozycja['collection_id'] ?? null)->first();
                if ($zeszyt === null) {
                    continue; // usunięty zeszyt albo ponowione kliknięcie
                }

                $dodano = false;

                try {
                    app(ZamekZapisuDoZeszytu::class)->zapisz(
                        $user,
                        $recipe,
                        $zeszyt,
                        function (User $swiezy, Recipe $przepis, Collection $cel) use ($pozycja, &$dodano, &$swiezyKucharz, &$swiezyPrzepis): Collection {
                            Gate::forUser($swiezy)->authorize('addItem', $cel);

                            if ($cel->recipes()->whereKey($przepis->getKey())->exists()) {
                                return $cel; // nie nadpisuj nowszej notatki ani daty
                            }

                            DB::transaction(fn () => $cel->recipes()->attach($przepis->getKey(), [
                                'note' => $pozycja['note'] ?? null,
                                'created_at' => $pozycja['created_at'] ?? now(),
                                'added_by_id' => array_key_exists('added_by_id', $pozycja) ? $pozycja['added_by_id'] : $swiezy->getKey(),
                            ]));
                            $dodano = true;
                            $swiezyKucharz = $swiezy;
                            $swiezyPrzepis = $przepis;

                            return $cel;
                        },
                    );
                } catch (UniqueConstraintViolationException) {
                    continue;
                } catch (BladDlaCzlowieka $e) {
                    if ($e->getMessage() !== ZamekZapisuDoZeszytu::BRAK_ZESZYTU) {
                        throw $e;
                    }

                    continue;
                }

                $wrocilo += (int) $dodano;
            }

            // Aktor jest zablokowany przez zewnętrzną transakcję, więc
            // liczba po zapisie minus nasze wstawienia to liczba sprzed
            // restore. Powiadomienie idzie tylko przy pierwszym zeszycie.
            if ($wrocilo > 0 && $swiezyKucharz !== null && $swiezyPrzepis !== null) {
                $ileTeraz = $swiezyKucharz->collections()
                    ->whereHas('recipes', fn ($q) => $q->whereKey($swiezyPrzepis->getKey()))
                    ->count();

                if ($ileTeraz === $wrocilo) {
                    $this->notify->handle($swiezyKucharz, $swiezyPrzepis);
                }
            }

            return $wrocilo;
        });
    }
}
