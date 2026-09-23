<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Notifications\Actions\NotifyRecipeSaved;
use App\Models\Collection;
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
    public function __construct(private readonly NotifyRecipeSaved $notify) {}

    public function handle(User $user, Recipe $recipe, ?Collection $collection = null, ?string $note = null): Collection
    {
        // Oba skutki są zapisami tej samej bazy (#907). Istniejące powiązanie
        // jest znacznikiem zakończenia: zatwierdzamy je razem z powiadomieniem.
        // Nie sprawdzamy istnienia wiadomości, którą mogła usunąć retencja.
        return DB::transaction(fn (): Collection => $this->saveWithNotification($user, $recipe, $collection, $note));
    }

    private function saveWithNotification(User $user, Recipe $recipe, ?Collection $collection, ?string $note): Collection
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
     * Usuwa zapis — z JEDNEGO zeszytu, jeśli go podano, inaczej ze WSZYSTKICH
     * własnych zeszytów tej osoby (issue #775).
     *
     * PRZED TĄ ZMIANĄ ten sam przepis zapisany w dwóch zeszytach dawał się
     * wykasować obydwu naraz jednym przyciskiem „Usuń z zeszytu” na stronie
     * przepisu — bez wyboru, bez potwierdzenia zakresu i z utratą notatki
     * w zeszycie, o którym człowiek nawet nie myślał. „Poprawne dane nigdy
     * nie znikają" (AGENTS.md §5) dotyczy też danych w INNYM zeszycie niż
     * ten, z którego ktoś akurat usuwał.
     *
     * `$collection` jest tu zaufany przez wywołującego —
     * `CollectionController::selectedCollection()` już sprawdził, że należy
     * do tej samej osoby (`owner_id`), zanim dotarł tutaj.
     */
    public function remove(User $user, Recipe $recipe, ?Collection $collection = null): int
    {
        if ($collection !== null) {
            $ile = $collection->recipes()->detach($recipe->getKey()) > 0 ? 1 : 0;

            // Wyjęcie z JEDNEGO zeszytu nie wycofuje zapisu, dopóki przepis
            // leży w innym zeszycie tej osoby — powiadomienie za nią poszło
            // raz (#906) i zostaje, póki jej zapis trwa gdziekolwiek.
            $this->cofnijJesliNigdzieNieZostal($user, $recipe);

            return $ile;
        }

        // ODDAJEMY LICZBĘ ZESZYTÓW, Z KTÓRYCH NAPRAWDĘ WYJĘTO (D-231).
        //
        // Komunikat po akcji nazywa zakres („wyjęty z 3 Twoich zeszytów"),
        // a nazwać go da się tylko licząc FAKTYCZNE odpięcia — nie liczbę
        // zeszytów, które ta osoba ma. `detach()` oddaje liczbę skasowanych
        // wierszy, więc zeszyt bez tego zapisu nie podbija licznika i drugie
        // kliknięcie nie kłamie, że znowu coś zabrało.
        $ile = 0;

        $user->collections()->each(function (Collection $collection) use ($recipe, &$ile): void {
            $ile += $collection->recipes()->detach($recipe->getKey()) > 0 ? 1 : 0;
        });

        $this->cofnijJesliNigdzieNieZostal($user, $recipe);

        return $ile;
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
