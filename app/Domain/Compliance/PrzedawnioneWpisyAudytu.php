<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\AuditLogEntry;
use App\Support\UsuwanieWPartiach;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Retencja `audit_log` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.1).
 *
 * Domyślnie `config('kuking.audit_log.retention_months')` miesięcy od
 * `created_at`, Z WYJĄTKIEM kategorii z `AuditLogEntry::NIGDY_NIE_KASUJ` —
 * te NIGDY nie są kandydatem do usunięcia, niezależnie od wieku wiersza (od
 * 2.10.2026 lista jest pusta, #2708).
 *
 * Wpisy `AuditLogEntry::DOWODY_USUNIECIA_KONTA` (`account.*`) są kasowane
 * zwykłą retencją dopiero po przeniesieniu do `potwierdzenia_zadan_rodo`
 * (`PrzenoszeniePotwierdzenRodo::ileBrakuje() === 0`) i nigdy dla kont
 * z zabezpieczonym dowodem.
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
     * @return array{skasowano: int, niekasowalne: int, wyczyszczono_ip: int, wstrzymane_do_przeniesienia: bool, zatrzymane_konta: int} `niekasowalne` to
     *                                                                                                                                  liczba wierszy starszych niż próg, które zostały POMINIĘTE
     *                                                                                                                                  (kategorie niekasowalne, wpisy `account.*` do czasu
     *                                                                                                                                  przeniesienia do potwierdzeń i wpisy kont z zabezpieczonym
     *                                                                                                                                  dowodem); dry-run i normalny przebieg liczą to samo.
     */
    public function posprzataj(int $miesiecyKarencji, bool $naSucho = false): array
    {
        // `subMonthsNoOverflow`, NIE `subMonths` — ta sama pułapka co
        // w `PrzedawnionePowiadomienia` (A6-04): przepełnienie daty przesuwa
        // próg w stronę nowszych wierszy i kasuje je przed czasem.
        // Pełne uzasadnienie i pomiar są tam, przy oryginalnym znalezisku.
        $prog = now()->subMonthsNoOverflow($miesiecyKarencji);

        // BRAMKA PRZENIESIENIA (#2708): wpisy `account.*` przestały być
        // niekasowalne, bo dowód przejęło `potwierdzenia_zadan_rodo`. Ale
        // dopóki choć jedno zamknięcie z audytu nie ma potwierdzenia
        // (backfill niewykonany), kasowanie wpisu byłoby utratą jedynego
        // dowodu — wtedy te wpisy zostają, niezależnie od wieku.
        $wstrzymane = (new PrzenoszeniePotwierdzenRodo)->ileBrakuje() > 0;

        // Konta z zabezpieczonym dowodem (CSAM): powiązanych wpisów nie ruszamy.
        $zatrzymaneKonta = DB::table('zabezpieczenia_dowodow')->whereNotNull('subject_user_id')->select('subject_user_id');

        $przezyja = fn (Builder $q) => $q->where(
            fn (Builder $w) => $w
                ->whereIn('action', AuditLogEntry::NIGDY_NIE_KASUJ)
                ->when($wstrzymane, fn (Builder $x) => $x->orWhereIn('action', AuditLogEntry::DOWODY_USUNIECIA_KONTA))
                ->orWhere(fn (Builder $z) => $z
                    ->whereIn('action', AuditLogEntry::DOWODY_USUNIECIA_KONTA)
                    ->whereIn('subject_id', $zatrzymaneKonta)),
        );

        $niekasowalne = $przezyja(AuditLogEntry::query()->where('created_at', '<', $prog))->count();

        $doSkasowania = fn () => AuditLogEntry::query()
            ->where('created_at', '<', $prog)
            ->whereNot(fn (Builder $q) => $przezyja($q));

        $skasowano = $naSucho
            ? $doSkasowania()->count()
            : UsuwanieWPartiach::zKonfiguracji()->usun($doSkasowania, 'id', 'audit_log');

        // SKRÓT IP WE WPISACH, KTÓRE PRZEŻYŁY, ŻYJE TYLE, CO ZWYKŁY DZIENNIK
        // (audyt B5, znalezisko 10). Skrót to HMAC z `APP_KEY`: kto ma zrzut
        // bazy i klucz, przejdzie całą przestrzeń IPv4 i odtworzy adres.
        // Dowodem jest to, CO i KIEDY się stało, a nie z jakiej sieci.
        $zeSkrotem = $przezyja(AuditLogEntry::query()->where('created_at', '<', $prog))
            ->whereNotNull('ip_hash');

        $wyczyszczonoIp = $naSucho ? $zeSkrotem->count() : $zeSkrotem->update(['ip_hash' => null]);

        return [
            'skasowano' => $skasowano,
            'niekasowalne' => $niekasowalne,
            'wyczyszczono_ip' => $wyczyszczonoIp,
            'wstrzymane_do_przeniesienia' => $wstrzymane,
            'zatrzymane_konta' => (clone $zatrzymaneKonta)->distinct()->count('subject_user_id'),
        ];
    }
}
