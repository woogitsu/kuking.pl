<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Seria identycznych alarmów to JEDNA wiadomość na okno, nie sto (#599).
 *
 * PO CO
 * Każdy raportowany błąd 500 woła webhook synchronicznie, osobnym żądaniem
 * HTTP. Awaria gorącej ścieżki (50 takich samych błędów na minutę) dawała
 * 50 wiadomości: zalany kanał, limit żądań dostawcy (Discord odpowiada 429
 * i właśnie wtedy gubi alarmy) i ruch wychodzący dokładany przeciążonej
 * aplikacji. `EpizodAlarmu` rozwiązuje to dla czujek ze STANEM; tu nie ma
 * stanu, są ZDARZENIA z odciskiem — stąd osobna, mniejsza maszyna.
 *
 * KONTRAKT (każdy punkt ma test w `SeriaIdentycznychAlarmowTest`)
 *   1. pierwsze wystąpienie odcisku idzie od razu, bez losowania;
 *   2. każde następne w oknie jest POMIJANE, ale LICZONE;
 *   3. inny odcisk w tym samym oknie idzie od razu — jedna burza nie zasłania
 *      drugiej awarii;
 *   4. pierwsza wiadomość po oknie niesie liczbę pominiętych powtórzeń;
 *   5. porażka kanału (429, 500, timeout) NIE kupuje okna ciszy — dostaje
 *      tylko krótką przerwę `PRZERWA_PO_PORAZCE_S`, żeby martwy webhook nie
 *      był wołany przy każdym żądaniu burzy (3 s czekania na każde);
 *   6. niedostępny cache = brak deduplikacji, nie brak alarmu.
 *
 * CZEGO TA KLASA NIE OGRANICZA: dziennika serwera. Laravel zapisuje każdy
 * raportowany wyjątek na kanał domyślny niezależnie od tego, co zdecyduje
 * się tu — pełny ślad zostaje, ograniczamy wyłącznie zewnętrzny kanał.
 *
 * ODCISK NIE NIESIE DANYCH. Wołający podaje go sam i odpowiada za to, żeby
 * był zbudowany z rzeczy stałych (klasa, plik, linia, wzorzec trasy) — nigdy
 * z komunikatu wyjątku ani adresu żądania.
 *
 * ZNANE OGRANICZENIE: `Cache::add()` jest atomowe, ale odczyt i wyzerowanie
 * licznika (`pull`) już nie. Dwa żądania kończące okno w tej samej chwili
 * mogą zgubić kilka sztuk z licznika. Liczba jest orientacyjna, sama
 * wiadomość — nie.
 */
final class SeriaAlarmow
{
    /** Przerwa po próbie, której kanał nie potwierdził. Sekundy, nie okno. */
    public const PRZERWA_PO_PORAZCE_S = 60;

    private const PREFIKS = 'kuking:seria-alarmow:';

    /**
     * @param  Closure(int): bool  $wyslij  dostaje liczbę pominiętych powtórzeń
     *                                      od ostatniej wiadomości; zwraca, czy
     *                                      kanał PRZYJĄŁ wiadomość
     * @return bool|null `null` = pominięte (policzone), inaczej wynik `$wyslij`
     */
    public function zglos(string $odcisk, int $oknoMinut, Closure $wyslij): ?bool
    {
        $okno = self::PREFIKS.$odcisk.':okno';
        $licznik = self::PREFIKS.$odcisk.':pominiete';
        $oknoSekund = max(1, $oknoMinut) * 60;

        try {
            if (! Cache::add($okno, 1, $oknoSekund)) {
                Cache::increment($licznik);

                return null;
            }

            $pominiete = (int) Cache::pull($licznik, 0);
        } catch (Throwable) {
            // Pamięć niedostępna (np. cache na tej samej bazie, która właśnie
            // padła). Wolimy dziesięć wiadomości o awarii niż zero.
            return $wyslij(0);
        }

        $przyjeto = $wyslij($pominiete);

        if (! $przyjeto) {
            try {
                // Nie oddajemy okna: skracamy je do krótkiej przerwy.
                Cache::put($okno, 1, self::PRZERWA_PO_PORAZCE_S);
                // Pominięte przed nieudaną próbą nadal są do zgłoszenia.
                if ($pominiete > 0) {
                    Cache::increment($licznik, $pominiete);
                }
            } catch (Throwable) {
                // Bez pamięci następna próba po prostu pójdzie od razu.
            }
        }

        return $przyjeto;
    }
}
