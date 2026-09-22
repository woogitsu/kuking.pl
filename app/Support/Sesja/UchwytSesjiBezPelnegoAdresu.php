<?php

declare(strict_types=1);

namespace App\Support\Sesja;

use App\Support\MaskaAdresuIp;
use Illuminate\Session\DatabaseSessionHandler;

/**
 * Sterownik sesji `database`, który zapisuje ZGRUBNY adres IP (RZ-01).
 *
 * Zmienia dokładnie jedną rzecz wobec `DatabaseSessionHandler` Laravela:
 * to, co ląduje w kolumnie `sessions.ip_address`. Cała reszta — zapis,
 * odczyt, `gc()`, `user_agent` — zostaje frameworkowa.
 *
 * DLACZEGO NADPISANIE `ipAddress()`, A NIE MIDDLEWARE ALBO TRIGGER W BAZIE
 * Adres dokłada `DatabaseSessionHandler::addRequestInformation()` przy KAŻDYM
 * zapisie sesji, czyli praktycznie przy każdym żądaniu zalogowanego człowieka,
 * i robi to już po wyjściu z warstwy middleware. Jedyne miejsce, w którym da
 * się w to wejść bez dublowania logiki frameworka, to ta jedna metoda.
 *
 * KOSZT, KTÓRY TRZEBA ZNAĆ: to jest nadpisanie klasy frameworka, więc przy
 * każdej większej aktualizacji Laravela trzeba sprawdzić, czy
 * `addRequestInformation()` nadal woła `ipAddress()`. Gdyby przestało,
 * kolumna po cichu wróciłaby do pełnych adresów, a testy w
 * `tests/Feature/SesjaZapisujeTylkoZgrubnyAdresTest.php` zrobią z tego
 * czerwień, zanim zrobi to audyt.
 */
final class UchwytSesjiBezPelnegoAdresu extends DatabaseSessionHandler
{
    /**
     * Adres bieżącego żądania obcięty do podsieci.
     *
     * `parent::ipAddress()` to `$request->ip()` — po `NormalizeForwardedFor`
     * jest to PRAWDZIWY adres człowieka zza Cloudflare, nie adres proxy.
     * Właśnie dlatego nie zapisujemy go w całości.
     */
    protected function ipAddress()
    {
        $adres = parent::ipAddress();

        return MaskaAdresuIp::zgrubny(is_string($adres) ? $adres : null);
    }
}
