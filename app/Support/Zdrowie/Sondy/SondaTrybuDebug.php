<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `debug` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaTrybuDebug implements Sonda
{
    public function nazwa(): string
    {
        return 'debug';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_DEBUG_WLACZONY;
    }

    /**
     * Produkcja z `APP_DEBUG=true` (audyt B10-04) — wzorzec `sprawdzTurnstile()`.
     *
     * `degraded`, nie 503: kontener w pętli restartów nie wyłączy trybu
     * debugowania, zrobi to człowiek w panelu, a `check()` zapisuje błąd
     * w dzienniku i dzwoni na `blad_webhook`. Poza produkcją debug jest
     * stanem normalnym (`.env.example`, CI).
     */
    public function sprawdz(): void
    {
        if (! app()->environment('production') || ! (bool) config('app.debug')) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_DEBUG_WLACZONY,
            'APP_DEBUG=true na produkcji: strona błędu pokazuje ślad stosu i zmienne środowiska. '
                .'Ustaw APP_DEBUG=false w zmiennych serwisu (wzorzec: .railway/railway.ts).',
        );
    }
}
