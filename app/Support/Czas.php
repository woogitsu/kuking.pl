<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Czas pokazywany człowiekowi (issue #87).
 *
 * DLACZEGO NIE PRZEZ `config('app.timezone')`
 * Bo to jest strefa, w której aplikacja LICZY i ZAPISUJE, i musi zostać UTC.
 * Laravel wysyła do PostgreSQL czas bez informacji o strefie, a baza czyta go
 * w strefie sesji: przestawienie `app.timezone` na „Europe/Warsaw" sprawiało,
 * że 23:30 czasu polskiego lądowało w kolumnie jako 23:30 UTC — dwie godziny
 * za późno, po cichu, z rozjazdem między wierszami sprzed i po zmianie.
 *
 * Rozdzielenie tych dwóch stref to jedyny układ, który przeżywa zmianę czasu
 * i przenosiny serwera: baza trzyma moment, ekran pokazuje godzinę.
 *
 * CO BYŁO ZEPSUTE
 * Każda data w serwisie była pokazywana w UTC, czyli o dwie godziny za wcześnie
 * latem i o godzinę zimą. Przy wpisie sprzed pół godziny nikt by tego nie
 * zauważył. Przy TERMINIE, po którym coś się kończy — data zawieszenia konta,
 * termin na odwołanie od decyzji moderacyjnej, wygaśnięcie paczki z danymi —
 * dwie godziny to różnica między „zdążyłem" a „nie zdążyłem". Najgorszy
 * wariant: ktoś przychodzi w ostatniej chwili odwołać się od blokady, bo tak
 * wynikało z ekranu.
 */
final class Czas
{
    /** Ten sam moment, wyrażony w strefie, w której człowiek na niego patrzy. */
    public static function lokalnie(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(self::strefa());
    }

    /** Data po polsku, w polskiej strefie. */
    public static function data(CarbonInterface $moment, string $format = 'j F Y'): string
    {
        return self::lokalnie($moment)->translatedFormat($format);
    }

    /**
     * Data, gdy moment istnieje — pusty napis, gdy go nie ma.
     *
     * Widoki pytały o to przez `?->translatedFormat(...)`, więc pomocnik musi
     * umieć to samo. Bez tego trzeba by w każdym widoku dopisać `@if`,
     * a to jest dokładnie ten rodzaj zmiany, przy której ktoś kiedyś zapomni.
     */
    public static function dataLubNic(?CarbonInterface $moment, string $format = 'j F Y'): string
    {
        return $moment === null ? '' : self::data($moment, $format);
    }

    /**
     * Dzisiejsza DATA w strefie człowieka, jako `Y-m-d`.
     *
     * Nie `now()->toDateString()`. To drugie liczy dzień w `app.timezone`,
     * czyli w UTC, i przez pierwsze dwie godziny polskiej doby (jedną zimą)
     * zwraca datę wczorajszą. Wszędzie, gdzie „dzień" jest pojęciem człowieka
     * — tablica dnia, archiwum, wspomnienia — musi być stąd, a nie z `now()`.
     */
    public static function dzisiajData(): string
    {
        return self::lokalnie(now())->toDateString();
    }

    /**
     * PONIEDZIAŁEK tygodnia, w którym mieści się ten moment, jako `Y-m-d`
     * w strefie człowieka.
     *
     * PO CO ODDZIELNA METODA, A NIE `now()->startOfWeek()` NA MIEJSCU
     * Bo `now()` liczy w `app.timezone`, czyli w UTC (patrz komentarz klasy),
     * a poniedziałek w UTC zaczyna się w Polsce w niedzielę o 22:00 albo
     * 23:00. Tygodniowe podsumowanie wysyłane w poniedziałek o 00:30 czasu
     * polskiego trafiłoby więc do tygodnia POPRZEDNIEGO — a ten tydzień jest
     * kluczem, po którym baza rozpoznaje „ta osoba ma już ten okres
     * obsłużony" (`weekly_digest_sends`, D-077). Pomyłka o dwie godziny
     * znaczy tu dwa listy w jednym tygodniu albo brak listu, nie
     * przesuniętą etykietę na ekranie.
     *
     * DLACZEGO DATA PONIEDZIAŁKU, A NIE NUMER TYGODNIA ISO
     * Numer tygodnia sam z siebie nie jest identyfikatorem: `2026-12-28`
     * należy do tygodnia 1 **roku 2027**, więc numer wymaga pary
     * (rok ISO, tydzień) i pierwszego dnia, w którym ktoś dołoży ją nie po
     * kolei, klucz przestaje być unikalny. Data poniedziałku jest jedną
     * kolumną typu `date`, porównywalną, sortowalną i czytelną w zrzucie
     * bazy — a `date_trunc('week', …)` w PostgreSQL znaczy dokładnie to samo
     * (tygodnie Postgresa zaczynają się w poniedziałek).
     */
    public static function poczatekTygodniaData(?CarbonInterface $moment = null): string
    {
        return self::lokalnie($moment ?? now())->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    /**
     * Fragment SQL sprowadzający kolumnę `timestamptz` do czasu ŚCIENNEGO
     * człowieka: `<kolumna> at time zone 'Europe/Warsaw'`.
     *
     * PO CO
     * `date_trunc('week', kolumna)` i `extract(year from kolumna)` na
     * `timestamptz` liczą w strefie SESJI Postgresa — a tej to repozytorium
     * nigdzie nie ustawia (`config/database.php` nie ma klucza `timezone`
     * dla `pgsql`). Sesja bierze więc domyślną strefę SERWERA bazy: tu
     * `Etc/UTC`, gdzie indziej cokolwiek. Te same dane potrafiły dać inny
     * wynik na innym serwerze, bez jednej zmiany w kodzie i bez ostrzeżenia.
     * Ten fragment czyni strefę jawną i przestaje od tamtej zależeć.
     *
     * DLACZEGO NAZWA STREFY JEST WKLEJANA, A NIE PODANA JAKO PARAMETR
     * Bo to samo wyrażenie trzeba powtórzyć w `SELECT`, `GROUP BY`
     * i `ORDER BY`, a Laravel trzyma parametry OSOBNO dla każdej z tych
     * klauzul i wiąże je po kolejności. Pięć parametrów rozrzuconych po
     * trzech klauzulach to dokładnie ten rodzaj konstrukcji, w której
     * przestawienie jednej linijki psuje wynik po cichu.
     *
     * Wklejenie jest bezpieczne: wartość pochodzi z konfiguracji, nie od
     * człowieka. Mimo to jest sprawdzana wobec listy stref IANA — pomyłka
     * w konfiguracji też jest pomyłką, a tutaj kończyłaby się zapytaniem SQL.
     * `$kolumna` jest zawsze literałem z naszego kodu, nigdy z żądania.
     */
    public static function wStrefieCzlowieka(string $kolumna): string
    {
        $strefa = self::strefa();

        if (! in_array($strefa, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(
                "`kuking.strefa` ma wartość „{$strefa}\", która nie jest nazwą strefy IANA.",
            );
        }

        return "{$kolumna} at time zone '{$strefa}'";
    }

    public static function strefa(): string
    {
        return (string) config('kuking.strefa', 'Europe/Warsaw');
    }
}
