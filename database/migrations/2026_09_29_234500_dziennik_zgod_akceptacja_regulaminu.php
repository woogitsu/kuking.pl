<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trwały dowód akceptacji regulaminu przy rejestracji (#2217).
 *
 * Formularze rejestracji wymagały `terms_accepted`, ale nic z tego nie
 * zostawało w bazie: w sporze nie dałoby się wykazać, którą WERSJĘ regulaminu
 * ktoś zaakceptował, kiedy i którą drogą. Nie budujemy drugiego mechanizmu —
 * `dziennik_zgod` (D-072) jest już tabelą append-only z wyzwalaczem, eksportem
 * danych i odmową `down()` (D-088). Ta migracja dokłada do niego:
 *
 *  - cel `regulamin` (akceptacja; czynność zawsze `udzielona` — akceptacji
 *    regulaminu nie „wycofuje” się wierszem, umowę kończy usunięcie konta);
 *  - trzy źródła rejestracji: `rejestracja_haslo`, `rejestracja_google`,
 *    `rejestracja_facebook`;
 *  - kolumnę `wersja_regulaminu` (`varchar(20) NULL`) — wersja OBOWIĄZUJĄCA
 *    w chwili akceptacji (`WersjaDokumentu::regulamin()->obowiazujaca()`,
 *    D-327). Wypełniona dokładnie przy celu `regulamin` (CHECK), więc wiersz
 *    o digeście nie niesie fałszywej „wersji regulaminu”, a akceptacja bez
 *    wersji nie przejdzie.
 *
 * BACKFILL: CELOWO BRAK. Konta założone przed tą migracją nie mają wiersza
 * `regulamin`, i to jest prawdziwy stan: „brak dowodu”. Dopisanie im
 * akceptacji z datą rejestracji byłoby dokumentem, którego nikt nie
 * wystawił. Kolumna jest `NULL`-owalna bez wartości domyślnej właśnie po to,
 * żeby stare wiersze (digest, życzenia, odczyt AI) zostały bez zmian.
 *
 * ISTNIEJĄCA TABELA → `ADD COLUMN` bez domyślnej (metadane, bez przepisywania)
 * oraz `NOT VALID` + `VALIDATE` poza jedną transakcją (AGENTS.md §6). Nowe
 * CHECK-i cel/źródło są nadzbiorami starych, a CHECK wersji jest prawdziwy dla
 * każdego istniejącego wiersza (`cel <> 'regulamin'` i kolumna świeżo `NULL`).
 *
 * `down()` ODMAWIA, gdy w dzienniku jest choć jeden zapis akceptacji
 * regulaminu (D-088): dziennik jest append-only (wyzwalacz blokuje `DELETE`),
 * więc zwężenie CHECK-ów i skasowanie kolumny oznaczałoby utratę dowodu.
 * Wyjściem jest eksport poza bazę i decyzja człowieka, nie skutek rollbacku.
 * Na świeżej bazie i bez takich wierszy cofnięcie przechodzi bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CELE_NOWE = "('tygodniowy_digest', 'zyczenia_urodzinowe', 'odczyt_ai', 'regulamin')";

    private const CELE_STARE = "('tygodniowy_digest', 'zyczenia_urodzinowe', 'odczyt_ai')";

    private const ZRODLA_NOWE = "('ustawienia', 'link_wypisania', 'link_powrotny', 'usuniecie_konta', 'ekran_importu', 'rejestracja_haslo', 'rejestracja_google', 'rejestracja_facebook')";

    private const ZRODLA_STARE = "('ustawienia', 'link_wypisania', 'link_powrotny', 'usuniecie_konta', 'ekran_importu')";

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        if (! Schema::hasColumn('dziennik_zgod', 'wersja_regulaminu')) {
            DB::statement('ALTER TABLE dziennik_zgod ADD COLUMN wersja_regulaminu varchar(20) NULL');
        }

        $this->podmien('dziennik_zgod_cel_check', 'cel IN '.self::CELE_NOWE);
        $this->podmien('dziennik_zgod_zrodlo_check', 'zrodlo IN '.self::ZRODLA_NOWE);
        $this->podmien(
            'dziennik_zgod_wersja_regulaminu_check',
            "(cel = 'regulamin') = (wersja_regulaminu IS NOT NULL)",
        );
        $this->podmien(
            'dziennik_zgod_regulamin_tylko_udzielony_check',
            "cel <> 'regulamin' OR czynnosc = 'udzielona'",
        );
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $wiersze = (int) DB::table('dziennik_zgod')
            ->where('cel', 'regulamin')
            ->orWhereNotNull('wersja_regulaminu')
            ->orWhere('zrodlo', 'like', 'rejestracja\\_%')
            ->count();

        if ($wiersze > 0) {
            throw new RuntimeException(
                'Cofnięcie tej migracji zwęziłoby dziennik zgód i skasowało kolumnę wersji regulaminu, '
                .'a w dzienniku jest '.$wiersze.' zapisów akceptacji regulaminu przy rejestracji '
                ."(cel regulamin, źródło rejestracja_*). Dziennik jest tylko do dopisywania — tych zapisów nie da się usunąć bez zdjęcia wyzwalacza, a to są dowody akceptacji (D-072, D-088, #2217).\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'Zostaw tę migrację. Jeśli dowody mają naprawdę zniknąć, najpierw wyeksportuj je '
                ."(\\copy (SELECT * FROM dziennik_zgod WHERE cel = 'regulamin') to 'akceptacje_regulaminu.csv' csv header) "
                .'i zdecyduj o tym jako człowiek, poza migracją.',
            );
        }

        DB::statement('ALTER TABLE dziennik_zgod DROP CONSTRAINT IF EXISTS dziennik_zgod_regulamin_tylko_udzielony_check');
        DB::statement('ALTER TABLE dziennik_zgod DROP CONSTRAINT IF EXISTS dziennik_zgod_wersja_regulaminu_check');
        $this->podmien('dziennik_zgod_zrodlo_check', 'zrodlo IN '.self::ZRODLA_STARE);
        $this->podmien('dziennik_zgod_cel_check', 'cel IN '.self::CELE_STARE);
        DB::statement('ALTER TABLE dziennik_zgod DROP COLUMN IF EXISTS wersja_regulaminu');
    }

    private function podmien(string $nazwa, string $warunek): void
    {
        DB::statement("ALTER TABLE dziennik_zgod DROP CONSTRAINT IF EXISTS {$nazwa}");
        DB::statement("ALTER TABLE dziennik_zgod ADD CONSTRAINT {$nazwa} CHECK ({$warunek}) NOT VALID");
        DB::statement("ALTER TABLE dziennik_zgod VALIDATE CONSTRAINT {$nazwa}");
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
