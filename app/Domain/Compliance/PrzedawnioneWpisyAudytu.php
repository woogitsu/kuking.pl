<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\AuditLogEntry;

/**
 * Retencja `audit_log` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.1).
 *
 * Domyślnie `config('kuking.audit_log.retention_months')` miesięcy od
 * `created_at`, Z WYJĄTKIEM kategorii z `AuditLogEntry::NIGDY_NIE_KASUJ` —
 * te NIGDY nie są kandydatem do usunięcia, niezależnie od wieku wiersza.
 *
 * DLACZEGO ZWYKŁY MASOWY `DELETE`, A NIE PĘTLA PER WIERSZ
 * Ten sam powód co przy `PrzedawnioneSygnaly` (product_signals): wiersz
 * `audit_log` nie ma żadnego odpowiednika po stronie storage, więc jeden
 * `DELETE ... WHERE created_at < ? AND action NOT IN (...)` jest i szybszy,
 * i równie bezpieczny na przerwanie w połowie — baza sama gwarantuje
 * atomowość jednego zapytania, a predykat to wyłącznie wiek wiersza, więc
 * kolejny przebieg po prostu dobierze to, co zostało.
 */
final class PrzedawnioneWpisyAudytu
{
    /**
     * @return array{skasowano: int, niekasowalne: int} liczba skasowanych
     *                                                  wierszy i liczba wierszy starszych niż próg,
     *                                                  które zostały POMINIĘTE jako niekasowalne
     *                                                  (informacyjnie — dry-run i normalny przebieg
     *                                                  liczą to samo, bo druga liczba nigdy nie zależy
     *                                                  od trybu).
     */
    public function posprzataj(int $miesiecyKarencji, bool $naSucho = false): array
    {
        // `subMonthsNoOverflow`, NIE `subMonths` — ta sama pułapka co
        // w `PrzedawnionePowiadomienia` (A6-04): przepełnienie daty przesuwa
        // próg w stronę nowszych wierszy i kasuje je przed czasem.
        // Pełne uzasadnienie i pomiar są tam, przy oryginalnym znalezisku.
        $prog = now()->subMonthsNoOverflow($miesiecyKarencji);

        $niekasowalne = AuditLogEntry::query()
            ->where('created_at', '<', $prog)
            ->whereIn('action', AuditLogEntry::NIGDY_NIE_KASUJ)
            ->count();

        $doSkasowania = AuditLogEntry::query()
            ->where('created_at', '<', $prog)
            ->whereNotIn('action', AuditLogEntry::NIGDY_NIE_KASUJ);

        $skasowano = $naSucho ? $doSkasowania->count() : $doSkasowania->delete();

        return ['skasowano' => $skasowano, 'niekasowalne' => $niekasowalne];
    }
}
