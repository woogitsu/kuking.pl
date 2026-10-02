<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie\Wspolne;

use App\Models\CookingSession;

/**
 * Nocne sprzątanie wygasłych sesji wspólnego gotowania (#2385).
 *
 * Termin każdej sesji stoi w jej własnej kolumnie `expires_at`, a odczyt i tak
 * ignoruje wygasłe sesje (`CookingSession::trwa()`), więc to sprzątanie jest
 * higieną danych (minimalizacja, RODO), nie warunkiem poprawności. Klucze
 * obce zabierają razem z sesją odhaczenia, pomocników i zaproszenia.
 */
final class SprzatanieWspolnegoGotowania
{
    /**
     * `$wszystkie` kasuje też trwające sesje (tylko do wycofania migracji).
     */
    public function posprzataj(bool $naSucho = false, bool $wszystkie = false): int
    {
        $zapytanie = CookingSession::query();

        if (! $wszystkie) {
            $zapytanie->where('expires_at', '<=', now());
        }

        return $naSucho ? $zapytanie->count() : $zapytanie->delete();
    }
}
