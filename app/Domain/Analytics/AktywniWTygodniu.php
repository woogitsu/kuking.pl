<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Ilu ludzi było w serwisie w ciągu ostatnich siedmiu dni — licząc z
 * `users.ostatnio_widziany_at` (issue #114/#115, bramka V1 z
 * `docs/ROADMAP.md`).
 *
 * TO NIE JEST TO SAMO CO `WeeklyActiveCooks`
 * Tamta klasa liczy konta, które w danym tygodniu kalendarzowym OPUBLIKOWAŁY
 * post, przepis albo „Ugotowałem" — wąska definicja „aktywny = wyprodukował
 * treść". Ta klasa liczy konta, które w serwisie PO PROSTU BYŁY —
 * przeglądały feed, przepis, czyjeś zdjęcia — bez wymogu, żeby cokolwiek
 * opublikowały. To jest szerszy i, dla pytania „czy ludzie w ogóle wracają"
 * z bramki V1, właściwszy licznik: większość osób w grupie 50+ czyta
 * i gotuje z cudzych przepisów częściej, niż sama publikuje (AGENTS.md,
 * część 1 — centrum produktu to LUDZIE i ich gotowanie, nie wyłącznie
 * publikowanie).
 *
 * DLACZEGO „OSTATNIE 7 DNI OD TERAZ", A NIE TYDZIEŃ KALENDARZOWY
 * `WeeklyActiveCooks` może pokazać historię tydzień po tygodniu, bo liczy
 * z LOGU zdarzeń (`posts`/`recipes`/`cooked_events` mają swój `created_at`/
 * `published_at`/`cooked_at` na zawsze). `ostatnio_widziany_at` jest
 * NADPISYWANY — nie ma jak odtworzyć z niego, kto był aktywny w tygodniu
 * sprzed miesiąca, bo kolumna niesie wyłącznie NAJNOWSZĄ wartość. Raport
 * jest więc migawką na TERAZ („ilu jest aktywnych licząc od chwili
 * uruchomienia komendy wstecz"), nie historycznym trendem — i to jest
 * świadomy kompromis, opisany też w `App\Console\Commands\RaportPowrotow`.
 *
 * TE SAME WYKLUCZENIA CO WAC/KOHORTA (`CookEligibility`)
 * Gospodarz i konta testowe/zalążkowe nie mają zasilać liczby czytanej jako
 * dowód żywej społeczności — dokładnie ten sam powód, dla którego wyklucza
 * je `WeeklyActiveCooks`.
 */
final class AktywniWTygodniu
{
    public function __construct(private readonly CookEligibility $eligibility = new CookEligibility) {}

    public function liczba(?CarbonInterface $teraz = null): int
    {
        $teraz ??= now();

        $aktywni = User::query()
            ->whereNotNull('ostatnio_widziany_at')
            ->where('ostatnio_widziany_at', '>=', $teraz->copy()->subDays(7));
        $this->eligibility->tylkoLiczeni($aktywni, 'users.id');

        return $aktywni->count();
    }
}
