<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\User;

/**
 * Wartości dopuszczalne w `potwierdzenia_zadan_rodo` — jedno źródło dla PHP
 * i dla CHECK-ów w bazie (`docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §C).
 *
 * PO CO OSOBNA KLASA, A NIE STAŁE W MODELU
 * Bo modelu jeszcze NIE MA i to jest celowe: ta tura projektuje tabelę
 * i jej ograniczenia, a nie ścieżkę zapisu (patrz
 * `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`, „Czego ta gałąź NIE robi").
 * Migracja i testy potrzebują jednak tej samej listy wartości, a dwie kopie
 * rozjechałyby się przy pierwszej zmianie — tak samo jak `PowodOdmowy`
 * karmi CHECK w `mail_failures`.
 *
 * LISTY SĄ KRÓTKIE I TO JEST WYNIK, NIE NIEDOKOŃCZENIE. Wartość, której nic
 * nie zapisuje, nie jest zapasem na przyszłość — jest zaproszeniem do
 * wpisania czegoś, czego nikt nie przemyślał, w rejestr, który ma być
 * dowodem. Każda pozycja niżej ma powód przy sobie.
 */
final class SlownikPotwierdzenRodo
{
    /**
     * RODZAJ ŻĄDANIA. Dziś jedna wartość — i tylko jedna ma powód.
     *
     * Ocena zewnętrzna (§C) każe zastąpić potwierdzeniem TRZY bezterminowe
     * wyjątki `audit_log`: `account.delete_requested`, `account.delete_cancelled`
     * i `account.data_erased`. Wszystkie trzy dotyczą JEDNEGO żądania —
     * usunięcia konta (RODO art. 17) — w trzech jego momentach. To nie są
     * trzy rodzaje żądań, tylko jeden rodzaj i trzy stany, więc stanem
     * zajmuje się `WYNIKI`, a nie ta lista.
     *
     * `eksport_danych` (art. 15/20) ŚWIADOMIE TU NIE STOI: eksport ma własną
     * tabelę `data_exports` z własnym cyklem życia, nie ma go na liście
     * `AuditLogEntry::NIGDY_NIE_KASUJ` i ocena zewnętrzna go nie wymienia.
     * Dopisanie go tutaj „na wszelki wypadek" utworzyłoby drugi rejestr
     * tego samego zdarzenia.
     *
     * @var list<string>
     */
    public const RODZAJE = [
        'usuniecie_konta',
    ];

    /**
     * WYNIK OBSŁUGI — stan końcowy, nie historia przejść.
     *
     * Ocena zewnętrzna mówi wprost: „Żądanie cofnięte jest DOWODEM COFNIĘCIA,
     * nie wykonania usunięcia. Przechowuj właściwy stan końcowy, nie trzy
     * niekasowalne kopie wszelkich danych". Dlatego jeden wiersz na żądanie
     * i jedna wartość tutaj, a nie trzy wiersze po jednym na zdarzenie.
     *
     *  - `w_toku` — żądanie przyjęte, karencja 30 dni jeszcze biegnie.
     *    Jedyny stan, w którym wolno trzymać `konto_id` (patrz CHECK
     *    `potwierdzenia_zadan_rodo_wykonane_bez_konta_check`);
     *  - `wykonane` — dane wymazane (`EraseAccountData`). Odpowiednik
     *    `account.data_erased`;
     *  - `cofniete` — człowiek zmienił zdanie w karencji
     *    (`CancelAccountDeletion`). Odpowiednik pary `delete_requested` +
     *    `delete_cancelled`. To jest ten wynik, o który pyta spór „nigdy nie
     *    prosiłem o usunięcie konta" (ADR §3.1);
     *  - `odmowa` — żądania nie wykonano i podano powód. Rejestr, który umie
     *    zapisać wyłącznie „tak", nie jest dowodem obsługi żądań, tylko
     *    dowodem tych obsłużonych po myśli wnioskodawcy; RODO art. 12 ust. 5
     *    i art. 17 ust. 3 przewidują odmowę, a bez tej wartości musiałaby
     *    wrócić do pełnego dziennika, czyli tam, skąd ją wyjmujemy.
     *
     * @var list<string>
     */
    public const WYNIKI = [
        'w_toku',
        'wykonane',
        'cofniete',
        'odmowa',
    ];

    /**
     * ZAKRES WYKONANIA — wprost wymieniony w §C oceny („zakres wykonania").
     *
     * Te same dwie wartości co `users.delete_scope` (D-022), bo opisują
     * dokładnie tę samą decyzję człowieka: czy razem z danymi osobowymi
     * znikają też jego treści. Bierzemy je ze stałych `User`, a nie
     * przepisujemy — rozjazd tych dwóch list znaczyłby, że potwierdzenie
     * mówi co innego niż to, co się stało.
     *
     * @return list<string>
     */
    public static function zakresy(): array
    {
        return [
            User::DELETE_SCOPE_MINIMUM,
            User::DELETE_SCOPE_EVERYTHING,
        ];
    }

    /**
     * Wersja procedury obsługi żądania, którą wykonano — §C („wersja
     * procedury"). Format `RRRR-MM-DD` daty, od której obowiązuje opisany
     * przebieg; zmiana przebiegu to nowa wartość, nie poprawka starej.
     *
     * Dzisiejsza procedura: zgłoszenie z `/ustawienia/twoje-dane`, 30 dni karencji,
     * `kuking:usun-wygasle-konta` → `EraseAccountData` z zakresem wybranym
     * przez człowieka (D-022).
     */
    public const WERSJA_PROCEDURY_DZIS = '2026-09-06';
}
