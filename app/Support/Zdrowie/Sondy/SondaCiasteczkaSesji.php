<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `sesja` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaCiasteczkaSesji implements Sonda
{
    public function nazwa(): string
    {
        return 'sesja';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_SESJA_BEZ_SECURE;
    }

    /**
     * Produkcja z ciasteczkiem sesji bez `Secure` (audyt B10-04). Domyślna
     * wartość na produkcji to `true` (`config/session.php`), więc ten sygnał
     * zapala tylko JAWNE `SESSION_SECURE_COOKIE=false`.
     */
    public function sprawdz(): void
    {
        if (! app()->environment('production') || (bool) config('session.secure')) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_SESJA_BEZ_SECURE,
            'Ciasteczko sesji bez flagi Secure na produkcji. '
                .'Ustaw SESSION_SECURE_COOKIE=true albo usuń tę zmienną (domyślnie true na produkcji).',
        );
    }
}
