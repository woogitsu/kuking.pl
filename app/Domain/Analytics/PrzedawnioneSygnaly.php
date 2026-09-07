<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\ProductSignal;

/**
 * Retencja `product_signals` (issue #115): 90 dni, konfigurowalne przez
 * `config('kuking.analytics.signal_retention_days')`.
 *
 * DLACZEGO ZWYKŁY MASOWY `DELETE`, A NIE `chunkById` JAK PRZY ZDJĘCIACH
 * `OsieroconeZdjecia::posprzataj()` idzie wiersz po wierszu, bo każde
 * usunięcie zdjęcia kasuje też PLIK na dysku — operację, którą trzeba
 * powtórzyć per wiersz i której nie da się cofnąć jednym `ROLLBACK`.
 * Wiersz `product_signals` nie ma żadnego odpowiednika po stronie storage:
 * to czysto tabelaryczne dane, więc jeden `DELETE ... WHERE occurred_at < ?`
 * jest i szybszy, i prostszy, i równie bezpieczny na przerwanie w połowie
 * (baza sama gwarantuje atomowość jednego zapytania).
 */
final class PrzedawnioneSygnaly
{
    /** @return int ile wierszy skasowano (albo skasowałoby, przy `$naSucho`) */
    public function posprzataj(int $dniKarencji = 90, bool $naSucho = false): int
    {
        $zapytanie = ProductSignal::query()->where('occurred_at', '<', now()->subDays($dniKarencji));

        return $naSucho ? $zapytanie->count() : $zapytanie->delete();
    }
}
