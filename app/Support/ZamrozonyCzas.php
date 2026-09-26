<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Zamrożenie zegara aplikacji na jeden, wspólny moment (issue #1836).
 *
 * PRZYCZYNA
 * Job CI „Panel marki — puste i pełne widoki" buduje fixture jednym
 * procesem PHP (`scripts/fixtures/panel-marki.php`), a ogląda ją drugim,
 * osobnym procesem (`php artisan serve`, wypytywany przez Playwrighta).
 * Oba wołały `Czas::dzisiajData()`, czyli licznik CZASU RZECZYWISTEGO,
 * w RÓŻNYCH chwilach: między zapisem `DailyPick::shown_on` a zrzutem ekranu
 * mija logowanie, TOTP i kilka żądań HTTP. 25.09 o 22:00 UTC (00:00 CEST)
 * te kilka sekund wystarczyło, żeby fixture zapisał wybór na „dziś", a panel
 * już pokazywał „dziś" jutrzejsze — selektor zaznaczonych wpisów nie
 * znajdował nic.
 *
 * NAPRAWA
 * Jedna zmienna środowiskowa, odczytana RAZ na początku joba CI i podana
 * OBU procesom (`fixture` i `artisan serve` dziedziczą to samo `env`
 * w `scripts/panel-marki-run.mjs`). Oba procesy bootują tę samą aplikację
 * (`bootstrap/app.php`), więc `AppServiceProvider::boot()` zamraża w OBU
 * ten sam moment przez `Carbon::setTestNow()` — od tej chwili `now()`,
 * `Czas::dzisiajData()` i wszystko, co na nich stoi, przestaje zależeć od
 * zegara systemowego i przechodzenia przez północ.
 *
 * DLACZEGO NIE `Carbon::setTestNow()` WPROST W FIXTURZE
 * Bo `setTestNow()` jest stanem WEWNĄTRZ jednego procesu PHP. Fixture
 * i `artisan serve` to DWA procesy — ustawienie w jednym nie jest widoczne
 * w drugim. Zmienna środowiskowa jest jedynym kanałem, który obie strony
 * dzielą.
 *
 * DLACZEGO TO JEST BEZPIECZNE POZA CI
 * Guard `App::environment(['local', 'testing'])` — zmienna ustawiona
 * przypadkiem na produkcji jest IGNOROWANA po cichu tam, gdzie środowisko
 * na to nie pozwala... a raczej: NIE jest ignorowana po cichu, tylko rzuca
 * wyjątek, żeby błędna konfiguracja nie zamroziła zegara serwisu, który
 * ludzie faktycznie używają. Patrz `zastosuj()`.
 */
final class ZamrozonyCzas
{
    /** Nazwa zmiennej środowiskowej: ISO-8601, dowolna strefa (patrz `Carbon::parse`). */
    public const ZMIENNA = 'KUKING_ZAMROZONY_CZAS';

    /**
     * Zamraża zegar aplikacji na wskazany moment.
     *
     * @param  string|null  $wartosc  Moment do zamrożenia. `null` czyta
     *                                zmienną środowiskową `self::ZMIENNA`;
     *                                pusty napis (brak zmiennej) nic nie robi
     *                                i zegar biegnie normalnie.
     *
     * @throws RuntimeException Zmienna ustawiona poza `local`/`testing`.
     * @throws InvalidArgumentException Wartość nie jest poprawną datą/czasem.
     */
    public static function zastosuj(?string $wartosc = null): void
    {
        // getenv(), nie env() — env() poza katalogiem config/ zwraca null,
        // gdy konfiguracja jest zbuforowana (Larastan: noEnvCallsOutsideOfConfig).
        $wartosc ??= (string) (getenv(self::ZMIENNA) ?: '');
        if ($wartosc === '') {
            return;
        }

        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                self::ZMIENNA.' wolno ustawić wyłącznie w środowisku `local` albo `testing` — '.
                'to jest zamrożenie zegara wyłącznie do celów CI/fixture, nigdy dla ruchu produkcyjnego.',
            );
        }

        try {
            $moment = CarbonImmutable::parse($wartosc);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                self::ZMIENNA.' ma wartość „'.$wartosc.'", która nie jest poprawną datą/czasem (oczekiwany ISO-8601).',
                previous: $e,
            );
        }

        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    /** Zdejmuje zamrożenie — zegar znowu biegnie. Do użytku w testach (`tearDown`). */
    public static function odmroz(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
