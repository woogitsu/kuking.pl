<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use Illuminate\Support\Facades\DB;

/**
 * Retencja tabeli `sessions` (RZ-01, 21.09.2026).
 *
 * DLACZEGO TO W OGÓLE POWSTAŁO, SKORO LARAVEL SAM SPRZĄTA SESJE
 * Bo „sam sprząta" znaczy tu: `config/session.php` → `'lottery' => [2, 100]`,
 * czyli `DatabaseSessionHandler::gc()` uruchamia się przy DWÓCH PROCENTACH
 * żądań. To jest sprzątanie probabilistyczne i zależne od ruchu: przy małym
 * ruchu (a serwis jest przed startem) wiersze leżą DŁUŻEJ niż `lifetime`,
 * bo loteria długo nie pada. Nie ma żadnej gwarantowanej górnej granicy.
 *
 * Dla każdej innej tabeli z danymi osobowymi ten projekt ma nocne zadanie
 * i twardą liczbę w `config/kuking.php` (audyt, powiadomienia, sygnały,
 * wiadomości, sprawy moderacyjne, zmiany adresu, zaproszenia). `sessions`
 * była jedyną, która takiej gwarancji nie miała — i jednocześnie jedyną,
 * której `docs/DATABASE.md` nie opisywał. To nie jest przypadek; obie rzeczy
 * biorą się stąd, że tabelę założyła domyślna migracja Laravela.
 *
 * LOTERIA ZOSTAJE. To nie jest zapomnienie, tylko wybór: dwa niezależne
 * mechanizmy o różnych trybach awarii. Loteria czyści przy ruchu, nawet gdy
 * padnie harmonogram; zadanie czyści co noc, nawet gdy ruchu nie ma. Wyłączenie
 * loterii zabrałoby jedną z tych dwóch dróg i niczego by nie dało.
 *
 * DLACZEGO ZWYKŁY MASOWY `DELETE`, A NIE PĘTLA PER WIERSZ
 * Ten sam powód co przy `PrzedawnioneWpisyAudytu`: wiersz `sessions` nie ma
 * żadnego odpowiednika po stronie storage, a predykat to wyłącznie wiek
 * wiersza, więc przerwanie w połowie niczego nie psuje — kolejny przebieg
 * dobierze resztę.
 */
final class PrzedawnioneSesje
{
    /**
     * PRÓG NIGDY NIE SCHODZI PONIŻEJ `SESSION_LIFETIME`.
     *
     * To jest bariera, nie ozdoba. Wiersz MŁODSZY niż `session.lifetime`
     * należy do sesji ŻYWEJ — skasowanie go wylogowuje człowieka w środku
     * pracy, bez żadnego komunikatu i bez jego decyzji. `AGENTS.md` mówi
     * o tym wprost („poprawne dane nigdy nie znikają"), a `docs/UX_50_PLUS.md`
     * traktuje nagłe wylogowanie jako usterkę, nie jako niedogodność.
     *
     * Liczba w konfiguracji jest więc SUFITEM retencji, a nie pozwoleniem
     * na cięcie żywych sesji. Rzeczywisty `SESSION_LIFETIME` na produkcji
     * nie jest dziś znany z repozytorium (`config/session.php` mówi 120 minut,
     * `.env.example` 10080, `.railway/railway.ts` 43200), więc próg musi
     * wynikać z tego, co aplikacja ma NAPRAWDĘ ustawione, a nie z liczby
     * wpisanej kiedyś do pliku.
     *
     * @return array{skasowano: int, dni: int, podniesiony: bool}
     */
    public function posprzataj(int $dniKarencji, bool $naSucho = false): array
    {
        $zKonfiguracji = max(1, $dniKarencji);

        // `ceil`, nie `round` ani `intdiv` — 120 minut ma dać 1 dzień, a nie 0.
        $dniZyciaSesji = max(1, (int) ceil(((int) config('session.lifetime')) / 1440));

        $dni = max($zKonfiguracji, $dniZyciaSesji);

        // `last_activity` to liczba całkowita (uniksowy znacznik czasu),
        // a nie kolumna daty — porównujemy w tej samej walucie, w której
        // zapisuje ją `DatabaseSessionHandler`.
        $prog = now()->subDays($dni)->getTimestamp();

        $doSkasowania = DB::table((string) config('session.table', 'sessions'))
            ->where('last_activity', '<', $prog);

        $skasowano = $naSucho ? $doSkasowania->count() : $doSkasowania->delete();

        return [
            'skasowano' => $skasowano,
            'dni' => $dni,
            'podniesiony' => $dni > $zKonfiguracji,
        ];
    }
}
