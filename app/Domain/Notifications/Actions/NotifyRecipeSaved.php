<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Powiadomienie autora o zapisaniu jego przepisu do cudzego zeszytu —
 * DECYZJA WŁAŚCICIELA z 20.09.2026, domyka issue #906 i idzie dalej
 * (zapisana jako D-070 w `docs/DECISIONS.md`).
 *
 * GRANICE `NotifyUser` POWTÓRZONE TU WPROST (D-070): ta klasa nie woła
 * `NotifyUser`, bo musi zaktualizować istniejący wiersz pod blokadą, więc
 * własna akcja, konto autora, które nie może czytać, i blokada w obie
 * strony są sprawdzane niżej — dla nowego wiersza i dla dołączenia do
 * partii jednakowo.
 *
 * DWIE RZECZY NARAZ, BO SĄ TYM SAMYM PROBLEMEM:
 *
 *  1. Jedna osoba, kilka SWOICH zeszytów = JEDEN zapis. Woła się to stąd
 *     dopiero, gdy `SaveRecipeToCollection` ustali, że to pierwsze zapisanie
 *     TEJ osoby (w którymkolwiek z jej zeszytów) — patrz tam.
 *  2. Zapisy od RÓŻNYCH osób zlewają się w JEDNO powiadomienie. Pierwsza
 *     osoba dostaje swoje powiadomienie NATYCHMIAST — to jest moment, który
 *     ma cieszyć autora. Każda kolejna, RÓŻNA osoba nie tworzy nowego
 *     wiersza — dokłada się do tego samego, jeszcze NIEPRZECZYTANEGO
 *     powiadomienia. Odczytanie zamyka partię: następny zapis zaczyna nową.
 *
 * ZAMEK: BLOKADA DORADCZA NA PARZE (autor, przepis), NIE `FOR UPDATE`.
 * Pierwsza wersja tej klasy zamykała się `SELECT ... FOR UPDATE` na otwartej
 * partii — a gdy partii jeszcze NIE MA, `FOR UPDATE` nie ma czego zablokować.
 * Dwa równoległe pierwsze zapisy (dwie różne osoby albo jedna osoba do
 * dwóch swoich zeszytów naraz) oba widziały „brak partii” i oba zakładały
 * wiersz (przegląd PR #1213). Blokada doradcza istnieje, zanim powstanie
 * jakikolwiek wiersz, i trzyma do końca transakcji — także ZEWNĘTRZNEJ,
 * w której siedzi `SaveRecipeToCollection`, więc drugi uczestnik szuka
 * partii dopiero po zatwierdzeniu pierwszego. Pomiar:
 * `tests/Dwa/ZbiorczyZapisNaDwochPolaczeniachTest.php`.
 */
final class NotifyRecipeSaved
{
    /**
     * Przestrzeń blokad doradczych partii zapisów (numer issue #906) —
     * ten sam wzorzec co `PublishComment::PRZESTRZEN_BLOKAD`: pierwszy
     * argument dzieli globalną przestrzeń PostgreSQL, żeby hasz pary nie
     * trafił w blokadę założoną w zupełnie innej sprawie.
     */
    private const PRZESTRZEN_BLOKAD = 906;

    /**
     * Zamyka partię (autor, przepis) do końca BIEŻĄCEJ transakcji.
     *
     * Publiczna, bo `SaveRecipeToCollection` musi ją wziąć WCZEŚNIEJ niż
     * `handle()` — przed policzeniem, ile zeszytów tej osoby ma już przepis.
     * Inaczej dwa równoległe zapisy jednej osoby do dwóch zeszytów oba
     * liczą „1” (drugi zeszyt jeszcze niezatwierdzony) i oba uznają się za
     * pierwszy zapis. Blokady doradcze są wielokrotnego wejścia w obrębie
     * jednego połączenia, więc ponowne wzięcie w `handle()` nie zakleszcza.
     *
     * Wymaga otwartej transakcji: poza nią `pg_advisory_xact_lock` zwalnia
     * się po jednym zapytaniu i nie chroni niczego.
     */
    public function zablokujPartie(Recipe $recipe): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock('.self::PRZESTRZEN_BLOKAD.', hashtext(?))',
            [$recipe->author_id.':'.$recipe->getKey()],
        );
    }

    public function handle(User $saver, Recipe $recipe): void
    {
        $recipient = $recipe->author;

        if ($recipient === null || $recipient->getKey() === $saver->getKey()) {
            return;
        }

        if (! $recipient->mozeCzytac()) {
            return;
        }

        if ($recipient->hasBlockRelationWith($saver)) {
            return;
        }

        // Zapis spoza aktywnego konta nie mówi autorowi nic, co mógłby
        // zobaczyć: zawieszona osoba zapisuje najwyżej do PRYWATNEGO zeszytu
        // (decyzja właściciela #926), a konta zamknięte nie zapisują wcale.
        // Stoi TU, a nie w `SaveRecipeToCollection`, bo ta klasa jest jedynym
        // miejscem, przez które powstaje powiadomienie o zapisie (D-070).
        if (! $saver->isActive()) {
            return;
        }

        DB::transaction(function () use ($saver, $recipe, $recipient): void {
            $this->zablokujPartie($recipe);

            $otwarta = $this->otwartaPartia($recipient->getKey(), $recipe);

            if ($otwarta === null) {
                // PIERWSZA OSOBA — powiadamia NATYCHMIAST, osobnym wierszem.
                Notification::create([
                    'user_id' => $recipient->getKey(),
                    'actor_id' => $saver->getKey(),
                    'type' => Notification::TYPE_SAVED,
                    'data' => [
                        'recipe_id' => $recipe->getKey(),
                        'recipe_title' => $recipe->title,
                        'recipe_slug' => $recipe->slug,
                        'savers' => [$saver->getKey()],
                        'others_count' => 0,
                    ],
                ]);

                return;
            }

            $savers = $otwarta->data['savers'] ?? [$otwarta->actor_id];

            if (in_array($saver->getKey(), $savers, true)) {
                // Już jest w tej partii (np. zapisała, wyszła z jednego
                // zeszytu i wróciła w innym) — nic nowego do dołożenia.
                return;
            }

            $savers[] = $saver->getKey();

            // Partia, w której pierwsza osoba jest dziś niewidoczna dla
            // autora (blokada albo ban po jej zapisie), PRZYJMUJE nową osobę
            // i to jest bezpieczne: widoczność zapisu liczy się po całej
            // liście `savers`, nie po `actor_id` (`Notification::scopeVisibleTo()`),
            // a ta osoba właśnie przeszła kontrolę blokady wyżej — wiersz
            // staje się więc widoczny i pokazuje ją z imienia. Osobny wiersz
            // złamałby zasadę „jedna otwarta partia na (autor, przepis)”,
            // na której stoi blokada doradcza.
            //
            // `created_at` IDZIE DO PRZODU: lista powiadomień jest ułożona
            // od najnowszego, a retencja (`SprzatajPowiadomienia`) liczy wiek
            // od `created_at`. Bez tego nowa osoba dopisana do partii sprzed
            // tygodnia lądowała głęboko na liście, a partia żywa od trzech
            // miesięcy znikała razem z dopiero co dopisanym zapisem.
            $otwarta->forceFill([
                'actor_id' => $savers[0],
                'created_at' => now(),
                'data' => array_merge($otwarta->data, [
                    'savers' => $savers,
                    'others_count' => count($savers) - 1,
                ]),
            ])->save();
        });
    }

    /**
     * Otwarta (nieprzeczytana) partia tego autora dla tego przepisu.
     *
     * Wołana WYŁĄCZNIE pod `zablokujPartie()`, więc drugiej otwartej partii
     * nowy kod nie założy. `orderByDesc` jest dla wierszy sprzed tej
     * poprawki — wyścig mógł wtedy zostawić dwie — i wybiera zawsze tę samą,
     * zamiast zdawać się na kolejność fizyczną tabeli.
     */
    private function otwartaPartia(string $autorId, Recipe $recipe): ?Notification
    {
        return Notification::query()
            ->where('user_id', $autorId)
            ->where('type', Notification::TYPE_SAVED)
            ->where('data->recipe_id', $recipe->getKey())
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Wycofanie zapisu (issue #906, pytanie właściciela): jeśli osoba
     * wyjmuje przepis ze WSZYSTKICH swoich zeszytów, ZANIM autor przeczytał
     * powiadomienie o tej partii, jej udział znika z treści — tak, jakby
     * nigdy nie zapisała. Jeśli to ona była "pierwszą" i została wymieniona
     * z nazwy, miejsce przejmuje kolejna osoba z tej samej partii. Jeśli
     * była jedyna — partia znika całkowicie, bo nie ma już czego zbierać.
     *
     * PO PRZECZYTANIU powiadomienie jest już historią i się go nie rusza —
     * "poprawne dane nigdy nie znikają" (AGENTS.md), a przeczytana wiadomość
     * jest dowodem tego, co autor faktycznie zobaczył.
     */
    public function cofnij(User $saver, Recipe $recipe): void
    {
        DB::transaction(function () use ($saver, $recipe): void {
            $this->zablokujPartie($recipe);

            $otwarta = $this->otwartaPartia((string) $recipe->author_id, $recipe);

            if ($otwarta === null) {
                return;
            }

            $savers = $otwarta->data['savers'] ?? [$otwarta->actor_id];

            if (! in_array($saver->getKey(), $savers, true)) {
                return;
            }

            $pozostali = array_values(array_filter(
                $savers,
                fn (?string $id) => $id !== $saver->getKey(),
            ));

            if ($pozostali === []) {
                $otwarta->delete();

                return;
            }

            $otwarta->forceFill([
                'actor_id' => $pozostali[0],
                'data' => array_merge($otwarta->data, [
                    'savers' => $pozostali,
                    'others_count' => count($pozostali) - 1,
                ]),
            ])->save();
        });
    }
}
