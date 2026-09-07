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

    public static function strefa(): string
    {
        return (string) config('kuking.strefa', 'Europe/Warsaw');
    }
}
