<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Compliance\UsuwanieWPartiach;
use App\Models\ProductSignal;

/**
 * Retencja `product_signals` (issue #115): 90 dni, konfigurowalne przez
 * `config('kuking.analytics.signal_retention_days')`.
 *
 * DLACZEGO `DELETE` PARTIAMI, A NIE `chunkById` JAK PRZY ZDJĘCIACH
 * `OsieroconeZdjecia::posprzataj()` idzie wiersz po wierszu, bo każde
 * usunięcie zdjęcia kasuje też PLIK na dysku — operację, którą trzeba
 * powtórzyć per wiersz i której nie da się cofnąć jednym `ROLLBACK`.
 * Wiersz `product_signals` nie ma żadnego odpowiednika po stronie storage,
 * więc wystarcza `DELETE ... WHERE occurred_at < ? AND id IN (...)` po
 * partii identyfikatorów (`UsuwanieWPartiach`, #1657). Jeden `DELETE` na cały
 * backlog NIE był bezpieczny na przerwanie: przerwana instrukcja wycofuje
 * się w całości i następny przebieg zaczynał od zera.
 */
final class PrzedawnioneSygnaly
{
    /** @return int ile wierszy skasowano (albo skasowałoby, przy `$naSucho`) */
    public function posprzataj(int $dniKarencji = 90, bool $naSucho = false): int
    {
        $prog = now()->subDays($dniKarencji);
        $kandydaci = fn () => ProductSignal::query()->where('occurred_at', '<', $prog);

        return $naSucho
            ? $kandydaci()->count()
            : UsuwanieWPartiach::zKonfiguracji()->usun($kandydaci, 'id', 'product_signals');
    }
}
