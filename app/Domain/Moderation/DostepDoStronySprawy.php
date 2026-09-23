<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Appeal;
use App\Models\ModerationAction;
use Carbon\CarbonInterface;

/**
 * Jak długo żyje strona śledzenia sprawy zgłaszającego (issue #798,
 * decyzja właściciela 20.09.2026).
 *
 * CO TU JEST ROZDZIELONE I DLACZEGO
 * Do 20 września 2026 podpisany link z `DecyzjaWSprawieZgloszenia` wygasał
 * DOKŁADNIE z terminem na ZŁOŻENIE odwołania (`temporarySignedRoute(...,
 * $decyzja->appealDeadline())`). To mieszało dwie różne rzeczy: termin na
 * złożenie pisma i dostęp do strony, na której to pismo się śledzi. Skutek
 * był taki, że osoba, która odwołała się tydzień przed terminem, dostawała
 * 403 na WŁASNĄ, wciąż nierozpatrzoną sprawę — a odpowiedź szła do niej
 * pocztą (`NotifyReporterAppealOutcome`), więc strona była już tylko ścianą.
 *
 * TERAZ WAŻNOŚĆ ZALEŻY OD STANU SPRAWY, NIE OD DATY Z CHWILI WYSYŁKI LISTU.
 * Sprawa jest OTWARTA, dopóki:
 *  * trwa termin na złożenie odwołania (`isAppealableByReporter()`), albo
 *  * odwołanie leży złożone i nierozstrzygnięte (`Appeal::isOpen()`).
 * Zamyka ją rozstrzygnięcie (`decided_at`) albo — gdy nikt się nie odwołał —
 * upływ terminu na odwołanie.
 *
 * PO ZAMKNIĘCIU STRONA ŻYJE JESZCZE `reporter_case_link_days` DNI i gaśnie.
 * To jest cena za to, żeby link nie był wieczny: odpowiedź i tak poszła
 * mailem, a adres, który działa zawsze, zaczyna być czymś innym niż linkiem
 * do jednej sprawy — wystarczy, że trafi do cudzej skrzynki albo do archiwum
 * listy dyskusyjnej. Okno jest po to, żeby człowiek zdążył wrócić po odpowiedź
 * z maila, nie po to, żeby trzymać sprawę na zawsze.
 *
 * CZEGO TA KLASA NIE ROBI
 * Nie decyduje, czy odwołanie WOLNO ZŁOŻYĆ — to jest termin z art. 20 ust. 1
 * i pilnuje go `FileReporterAppeal` razem z `isAppealableByReporter()`.
 * Można więc wejść na stronę po terminie i przeczytać, że termin minął — i to
 * jest dokładnie ta gałąź widoku, która przed tą zmianą była nieosiągalna.
 *
 * NIE ZASTĘPUJE TEŻ PODPISU. Podpis nadal jest jedyną autoryzacją wejścia
 * (`middleware('signed')`, AGENTS.md §7 — UUID w adresie nią nie jest). Ta
 * klasa odpowiada wyłącznie na drugie pytanie: czy ta sprawa ma jeszcze stronę.
 */
final class DostepDoStronySprawy
{
    /** Kiedy sprawa się zamknęła; `null` znaczy: jeszcze się nie zamknęła. */
    public static function zamknietaOd(ModerationAction $decyzja, ?Appeal $odwolanie): ?CarbonInterface
    {
        if ($odwolanie !== null) {
            // Odwołanie leży w kolejce — sprawa trwa, niezależnie od tego,
            // ile czasu minęło od decyzji. To jest sedno issue #798.
            if ($odwolanie->isOpen()) {
                return null;
            }

            // `decided_at` bywa puste tylko w danych sprzed migracji; wtedy
            // nie wiemy, kiedy sprawa się zamknęła, więc nie zamykamy strony.
            return $odwolanie->decided_at;
        }

        // Nikt się nie odwołał. Sprawa jest otwarta, dopóki trwa termin na
        // odwołanie — potem zamyka ją sam upływ tego terminu.
        return $decyzja->isAppealableByReporter() ? null : $decyzja->appealDeadline();
    }

    /** Czy strona sprawy jeszcze istnieje dla tego linku. */
    public static function zywa(ModerationAction $decyzja, ?Appeal $odwolanie): bool
    {
        $zamknieta = self::zamknietaOd($decyzja, $odwolanie);

        if ($zamknieta === null) {
            return true;
        }

        return $zamknieta
            ->copy()
            ->addDays((int) config('kuking.moderation.reporter_case_link_days'))
            ->isFuture();
    }
}
