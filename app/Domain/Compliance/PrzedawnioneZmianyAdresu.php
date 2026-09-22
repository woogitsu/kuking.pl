<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\PendingEmailChange;

/**
 * Sprzątanie wygasłych żądań zmiany adresu e-mail (issue #195).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO, SKORO WYGASŁEGO ŻĄDANIA I TAK NIE DA SIĘ POTWIERDZIĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo `pending_email_changes.new_email` to DANA OSOBOWA — adres skrzynki,
 * której właściciel może nie mieć w Kuking żadnego konta i nigdy o niczym
 * nie prosił (ktoś mógł się pomylić przy przepisywaniu). Minimalizacja
 * danych (AGENTS.md §7, `docs/decyzje/ADR_RETENCJE.md`) mówi, że taka
 * wartość znika, gdy przestaje być do czegokolwiek potrzebna — a wygasłe
 * żądanie jest już tylko śmieciem.
 *
 * Bramką bezpieczeństwa to sprzątanie NIE JEST i nie ma prawa nią być:
 * chodzi raz na dobę, a odnośnik ma przestać działać co do minuty. Tego
 * pilnuje `PendingEmailChange::jestWazne()`, sprawdzane przy każdym
 * potwierdzeniu i przy wyświetleniu ekranu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO `expires_at < now()`, A NIE ODEJMOWANIE OD DZISIAJ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Sąsiednie klasy w tym katalogu liczą próg jako `now()->subMonthsNoOverflow(N)`
 * i mają przy tym ostrzeżenie: `subMonths()` przy przepełnieniu daty (31
 * marca minus miesiąc) przesuwa próg w stronę NOWSZYCH wierszy i kasuje je
 * przed czasem (A6-04, `PrzedawnionePowiadomienia`).
 *
 * Tutaj tej pułapki nie ma i to nie jest przypadek: termin jest policzony
 * RAZ, w chwili powstania żądania, i zapisany w kolumnie `expires_at`.
 * Sprzątanie porównuje więc dwie konkretne chwile, zamiast odtwarzać próg
 * arytmetyką na datach przy każdym przebiegu. Ten wybór jest opisany także
 * w migracji zakładającej tabelę.
 *
 * Zwykły masowy `DELETE`, nie pętla po wierszach — ten sam powód co
 * w `PrzedawnioneWpisyAudytu`: wiersz nie ma odpowiednika po stronie
 * storage, jedno zapytanie jest atomowe, a przerwany przebieg po prostu
 * dobierze resztę następnym razem.
 */
final class PrzedawnioneZmianyAdresu
{
    /**
     * @param  bool  $naSucho  policz, ale nie kasuj
     * @return int liczba skasowanych (albo policzonych) żądań
     */
    public function posprzataj(bool $naSucho = false): int
    {
        $przedawnione = PendingEmailChange::query()->where('expires_at', '<', now());

        return $naSucho ? $przedawnione->count() : $przedawnione->delete();
    }
}
