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
 * DLACZEGO `DELETE` PARTIAMI, A NIE PĘTLA PER WIERSZ
 * Ten sam powód co przy `PrzedawnioneSygnaly` (product_signals): wiersz
 * `audit_log` nie ma żadnego odpowiednika po stronie storage, więc
 * `DELETE ... WHERE created_at < ? AND action NOT IN (...) AND id IN (...)`
 * po partii identyfikatorów (`UsuwanieWPartiach`, #1657). Predykat z
 * `NIGDY_NIE_KASUJ` jest powtórzony w każdym `DELETE`, a zatwierdzona partia
 * zostaje po przerwaniu — następny przebieg dobiera resztę naprawdę.
 */
final class PrzedawnioneWpisyAudytu
{
    /**
     * @return array{skasowano: int, niekasowalne: int, wyczyszczono_ip: int} liczba skasowanych
     *                                                                        wierszy i liczba wierszy starszych niż próg,
     *                                                                        które zostały POMINIĘTE jako niekasowalne
     *                                                                        (informacyjnie — dry-run i normalny przebieg
     *                                                                        liczą to samo, bo druga liczba nigdy nie zależy
     *                                                                        od trybu).
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

        $doSkasowania = fn () => AuditLogEntry::query()
            ->where('created_at', '<', $prog)
            ->whereNotIn('action', AuditLogEntry::NIGDY_NIE_KASUJ);

        $skasowano = $naSucho
            ? $doSkasowania()->count()
            : UsuwanieWPartiach::zKonfiguracji()->usun($doSkasowania, 'id', 'audit_log');

        // SKRÓT IP WE WPISACH DOWODOWYCH ŻYJE TYLE, CO ZWYKŁY DZIENNIK (audyt
        // B5, znalezisko 10). Wpis `NIGDY_NIE_KASUJ` zostaje na zawsze — ale
        // jego dowodem jest to, CO i KIEDY się stało, a nie z jakiej sieci.
        // Skrót to HMAC z `APP_KEY`: kto ma zrzut bazy i klucz, przejdzie całą
        // przestrzeń IPv4 i odtworzy adres. Przez okres retencji skrót zostaje
        // (tyle samo, co przy każdym innym wpisie, na wypadek sporu o świeże
        // żądanie), potem zerujemy go, a sam wpis zostaje nietknięty.
        $zeSkrotem = AuditLogEntry::query()
            ->where('created_at', '<', $prog)
            ->whereIn('action', AuditLogEntry::NIGDY_NIE_KASUJ)
            ->whereNotNull('ip_hash');

        $wyczyszczonoIp = $naSucho ? $zeSkrotem->count() : $zeSkrotem->update(['ip_hash' => null]);

        return ['skasowano' => $skasowano, 'niekasowalne' => $niekasowalne, 'wyczyszczono_ip' => $wyczyszczonoIp];
    }
}
