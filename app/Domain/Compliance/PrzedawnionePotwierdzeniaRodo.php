<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\PotwierdzenieZadaniaRodo;
use Illuminate\Support\Carbon;

/**
 * Retencja `potwierdzenia_zadan_rodo` —
 * `config('kuking.potwierdzenia_rodo.retention_months')` miesięcy (36) liczone
 * od `zakonczono`, NIE od `created_at`.
 *
 * Wymóg stoi w `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §C: „Po 36
 * miesiącach usuń potwierdzenie osobowe, o ile konkretna udokumentowana sprawa
 * nie wymaga dalszego zachowania". Cała ta tabela istnieje po to, żeby dowód
 * wykonania art. 17 przestał być BEZTERMINOWY — automat jest więc drugą
 * połową tej zmiany, nie dodatkiem do niej.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TRZY WARUNKI KANDYDATA — I KAŻDY Z NICH COŚ OCHRANIA
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. `zakonczono IS NOT NULL` — sprawa w toku nie jest kandydatem w ogóle.
 *     Kasowanie otwartej sprawy zamieniłoby retencję w sprzątanie dowodów
 *     zaniedbania (ta sama zasada co przy niezałatwionej wiadomości do
 *     operatora i przy otwartej sprawie moderacyjnej). CHECK
 *     `..._koniec_check` gwarantuje przy tym, że pusty `zakonczono` znaczy
 *     dokładnie `wynik = 'w_toku'`, a nie „ktoś zapomniał wpisać datę".
 *  2. `zakonczono < próg` — sam wiek wiersza, nic do zapamiętania między
 *     przebiegami.
 *  3. Wstrzymanie nie obowiązuje: `wstrzymanie_do IS NULL` albo
 *     `wstrzymanie_do < dziś`. Data, nie flaga — blokada wygasa sama, więc
 *     wiersz wraca pod retencję bez niczyjej pamięci. CHECK
 *     `..._wstrzymanie_check` nie pozwala wstrzymać bez wskazanej sprawy,
 *     więc ten warunek nie da się użyć jako cichej bezterminowości.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PRZEŁĄCZNIK ISTNIEJE I JEST DZIŚ WYŁĄCZONY — DECYZJA WŁAŚCICIELA (D-233)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Pierwotna wersja tej klasy nosiła tu akapit „BRAK PRZEŁĄCZNIKA — ŚWIADOMY",
 * uzasadniony zdaniem „wyłącznik retencji jest tym samym co bezterminowość,
 * tylko nazwaną inaczej". Właściciel rozstrzygnął inaczej 22.09.2026 i to
 * rozstrzygnięcie, nie ten akapit, obowiązuje: okresu nie potwierdził jeszcze
 * prawnik, a kasowanie jest twardym `DELETE` bez soft-delete i bez eksportu.
 * Pełne uzasadnienie stoi przy kluczu `potwierdzenia_rodo`
 * w `config/kuking.php` oraz w D-233; nie powtarzamy go tutaj, żeby dwa opisy
 * nie rozjechały się przy pierwszej zmianie.
 *
 * TA KLASA SAMA NIE PYTA O PRZEŁĄCZNIK — i to jest celowe. Decyduje o nim
 * `SprzatajPotwierdzeniaRodo`, żeby dało się ją wywołać wprost w teście
 * i w dry-runie bez udawania konfiguracji. Klasa liczy i kasuje to, o co ją
 * poproszono; o to, CZY wolno prosić, pyta warstwa wyżej.
 *
 * DLACZEGO ZWYKŁY MASOWY `DELETE`: wiersz nie ma odpowiednika po stronie
 * storage, więc jedno zapytanie jest i szybsze, i równie bezpieczne na
 * przerwanie w połowie — baza gwarantuje atomowość jednej instrukcji,
 * a predykat to wyłącznie wiek i wstrzymanie, więc kolejny przebieg dobierze
 * to, co zostało. Ten sam wzorzec co `PrzedawnioneWpisyAudytu`.
 */
final class PrzedawnionePotwierdzeniaRodo
{
    /**
     * @return array{skasowano: int, wstrzymane: int} liczba skasowanych wierszy
     *                                                i liczba wierszy starszych niż próg, POMINIĘTYCH
     *                                                z powodu udokumentowanego wstrzymania (informacyjnie —
     *                                                dry-run i normalny przebieg liczą to samo)
     */
    public function posprzataj(int $miesiecyKarencji, bool $naSucho = false): array
    {
        // `subMonthsNoOverflow`, NIE `subMonths` — A6-04, ta sama pułapka co
        // w `PrzedawnionePowiadomienia`, gdzie ją zmierzono. `subMonths` przy
        // przepełnieniu daty (31 marca minus miesiąc) przesuwa próg w stronę
        // NOWSZYCH wierszy i kasuje dowód wykonania art. 17 przed czasem.
        $prog = Carbon::now()->subMonthsNoOverflow($miesiecyKarencji)->toDateString();
        $dzis = Carbon::today()->toDateString();

        $przedawnione = PotwierdzenieZadaniaRodo::query()
            ->whereNotNull('zakonczono')
            ->where('zakonczono', '<', $prog);

        $wstrzymane = (clone $przedawnione)
            ->whereNotNull('wstrzymanie_do')
            ->where('wstrzymanie_do', '>=', $dzis)
            ->count();

        $doSkasowania = $przedawnione->where(
            fn ($zapytanie) => $zapytanie
                ->whereNull('wstrzymanie_do')
                ->orWhere('wstrzymanie_do', '<', $dzis),
        );

        $skasowano = $naSucho ? $doSkasowania->count() : $doSkasowania->delete();

        return ['skasowano' => $skasowano, 'wstrzymane' => $wstrzymane];
    }
}
