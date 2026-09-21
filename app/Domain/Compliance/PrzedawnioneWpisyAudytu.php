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

    /**
     * RETENCJA KATEGORII DOWODOWYCH (`AuditLogEntry::NIGDY_NIE_KASUJ`).
     *
     * PO CO TO POWSTAŁO
     * `posprzataj()` wyżej NIGDY nie rusza trzech kategorii dowodowych, więc
     * te wiersze leżą w bazie BEZTERMINOWO. Zewnętrzna ocena prawna
     * (`docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §C) nazywa to wprost
     * "nie do obrony w opisanym kształcie": rozliczalność z art. 5 ust. 2
     * RODO wymaga UMIEĆ WYKAZAĆ obsługę żądania, ale nie ustanawia
     * nieskończonej retencji osobowych logów — a art. 5 ust. 1 lit. e
     * (ograniczenie przechowywania) wymaga terminu. §D proponuje 36 miesięcy.
     *
     * DOMYŚLNIE WYŁĄCZONE — I TO JEST ŚWIADOME.
     * `$miesiecyKarencji === null` (domyślna wartość konfiguracji) znaczy
     * "zachowuj się dokładnie tak, jak przed tą zmianą", czyli nie kasuj nic.
     * Ta metoda jest MECHANIZMEM, nie decyzją: skrócenie retencji dowodu
     * wykonania RODO jest nieodwracalne i należy do właściciela, nie do
     * automatu ani do agenta. Dopóki właściciel nie ustawi liczby,
     * ta ścieżka jest martwa i nie kasuje ani jednego wiersza.
     *
     * DLACZEGO GRUPĄ PO PODMIOCIE, A NIE WIERSZ PO WIERSZU
     * §C liczy termin "od ZAKOŃCZENIA OBSŁUGI ŻĄDANIA", nie od zapisu
     * pojedynczego zdarzenia, a §B.2 pokazuje dokładnie ten błąd na sprawach
     * moderacyjnych: jednakowa liczba miesięcy dla rekordów o różnych datach
     * nie daje wspólnego końca. Tutaj byłoby to widać od razu —
     * `account.delete_requested` jest ZAWSZE starsze od
     * `account.delete_cancelled`, więc kasowanie wiersz po wierszu
     * zostawiałoby "cofnął usunięcie" bez "zgłosił usunięcie": dowód
     * okrojony do połowy, mylący bardziej niż jego brak.
     *
     * Dlatego kandydatem jest CAŁA historia jednego podmiotu
     * (`subject_type` + `subject_id`, czyli konkretne konto) i dopiero
     * wtedy, gdy NAJNOWSZY jej wpis jest starszy od progu. Wiersz bez
     * podmiotu nie jest kasowany nigdy — nie ma jak stwierdzić, do czyjej
     * sprawy należy, a domyślną odpowiedzią przy wątpliwości jest zachowanie.
     *
     * CZEGO TA METODA NIE ROBI — druga połowa §C
     * §C prosi też, żeby ZASTĄPIĆ pełne wiersze dziennika "minimalnym
     * potwierdzeniem obsługi żądania" (numer sprawy, daty, rodzaj, wynik,
     * wersja procedury). To jest nowa tabela i zmiana schematu, a nie skrócenie
     * okresu — świadomie poza zakresem tej poprawki i do decyzji właściciela.
     *
     * @param  int|null  $miesiecyKarencji  `null` = nie kasuj nic (stan sprzed zmiany).
     * @return array{skasowano: int, grup: int, wlaczone: bool}
     */
    public function posprzatajKategorieDowodowe(?int $miesiecyKarencji, bool $naSucho = false): array
    {
        if ($miesiecyKarencji === null || $miesiecyKarencji < 1) {
            return ['skasowano' => 0, 'grup' => 0, 'wlaczone' => false];
        }

        // `subMonthsNoOverflow`, NIE `subMonths` — ten sam powód co wyżej.
        $prog = now()->subMonthsNoOverflow($miesiecyKarencji);

        $grupy = AuditLogEntry::query()
            ->selectRaw('subject_type, subject_id')
            ->whereIn('action', AuditLogEntry::NIGDY_NIE_KASUJ)
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->groupBy('subject_type', 'subject_id')
            ->havingRaw('MAX(created_at) < ?', [$prog])
            ->get();

        $skasowano = 0;

        foreach ($grupy as $grupa) {
            $wiersze = AuditLogEntry::query()
                ->whereIn('action', AuditLogEntry::NIGDY_NIE_KASUJ)
                ->where('subject_type', $grupa->subject_type)
                ->where('subject_id', $grupa->subject_id);

            $skasowano += $naSucho ? $wiersze->count() : $wiersze->delete();
        }

        return ['skasowano' => $skasowano, 'grup' => $grupy->count(), 'wlaczone' => true];
    }
}
