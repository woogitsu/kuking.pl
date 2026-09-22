<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZDEJMUJE `potwierdzenia_zadan_rodo_wykonane_bez_konta_check`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TO JEST DECYZJA WŁAŚCICIELA Z 21.09.2026, NIE WNIOSEK Z OCENY
 * ────────────────────────────────────────────────────────────────────────
 *
 * Migracja `2026_09_21_100000_utworz_potwierdzenia_zadan_rodo` założyła CHECK
 * zabraniający `konto_id` przy `wynik = 'wykonane'`. Projekt
 * (`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §3.2 punkt 3) nazywał go
 * „najważniejszym CHECK-iem w tej tabeli", a §3.3 punkt 3 zostawiał
 * właścicielowi jedną decyzję: czy godzimy się NIE UMIEĆ odpowiedzieć
 * regulatorowi na pytanie o konkretną osobę bez jej numeru sprawy.
 *
 * **Właściciel odpowiedział: nie godzimy się.** `konto_id` ma zostać także po
 * wykonaniu żądania, żeby dało się odpowiedzieć o konkretną osobę bez pytania
 * jej o numer. To jest wybór właściciela po przeczytaniu argumentów przeciw —
 * a NIE rekomendacja oceny zewnętrznej i nie zmiana zdania przez autora
 * projektu. Kto czyta to za pół roku: §3.3 punkt 7 projektu opisuje cenę tej
 * decyzji, a §3.1 wariant A nadal opisuje, dlaczego argument przeciw był
 * poważny. Oba zostały, bo decyzja nie unieważnia argumentu.
 *
 * ZAWĘŻENIE, KTÓRE IDZIE RAZEM Z TĄ MIGRACJĄ. „Ma się dać odpowiedzieć
 * regulatorowi" to nie to samo co „ma być wyszukiwarka". Rejestr NIE DOSTAJE
 * ekranu, trasy ani endpointu czytającego tę tabelę po `konto_id` — pilnuje
 * tego `tests/Feature/RejestrPotwierdzenRodoNieMaEkranuTest.php`, a korzystanie
 * z powiązania jest ODCZYTEM RĘCZNYM przy sprawie od regulatora, opisanym
 * w `docs/DATABASE.md`.
 *
 * CZEGO TA MIGRACJA NIE RUSZA — i to jest połowa jej treści. Zostają nietknięte
 * wszystkie pozostałe ograniczenia tabeli, w szczególności:
 *  - `..._wstrzymanie_check` — para `wstrzymanie_do` + `wstrzymanie_sprawa`,
 *    bez której wstrzymanie kasowania byłoby retencją bezterminową pisaną
 *    inną kolumną;
 *  - `..._koniec_check` — wiązanie `zakonczono` ⟺ `wynik = 'w_toku'`, bez
 *    którego wiersz „wykonane" bez daty końca nigdy nie trafiłby pod retencję;
 *  - `..._zakres_tylko_wykonane_check`, `..._kolejnosc_dat_check`,
 *    `..._numer_check`, `..._rodzaj_check`, `..._wynik_check`, `..._zakres_check`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  WYCOFANIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` zakłada CHECK z powrotem — ale ODMAWIA WĄSKO, gdy w tabeli stoi
 * choć jeden wiersz, który by go złamał. Bez tej odmowy PostgreSQL rzuciłby
 * i tak, tylko komunikatem o naruszeniu ograniczenia, z którego nie wynika,
 * że decyzja właściciela z 21.09.2026 właśnie jest cofana i że cofnięcie
 * wymaga wyzerowania `konto_id` w istniejących potwierdzeniach. Odmowa jest
 * wąska: pusta tabela i tabela bez takich wierszy cofają się bez pytania.
 */
return new class extends Migration
{
    private const NAZWA = 'potwierdzenia_zadan_rodo_wykonane_bez_konta_check';

    public function up(): void
    {
        if (! $this->dotyczy()) {
            return;
        }

        DB::statement('ALTER TABLE potwierdzenia_zadan_rodo DROP CONSTRAINT IF EXISTS '.self::NAZWA);
    }

    public function down(): void
    {
        if (! $this->dotyczy()) {
            return;
        }

        $zeWskaznikiem = DB::table('potwierdzenia_zadan_rodo')
            ->where('wynik', 'wykonane')
            ->whereNotNull('konto_id')
            ->count();

        if ($zeWskaznikiem > 0) {
            throw new RuntimeException(
                'Odmawiam przywrócenia zakazu `konto_id` przy wykonanym żądaniu. Potwierdzeń, '
                .'które go łamią: '.$zeWskaznikiem.'. Każde z nich powstało zgodnie z decyzją '
                .'właściciela z 21.09.2026, a przywrócenie CHECK-a wymaga wcześniejszego '
                ."wyzerowania w nich `konto_id` — czyli TRWAŁEJ utraty powiązania.\n\n"
                .'CO ZROBIĆ: jeśli decyzja właściciela naprawdę jest cofana, najpierw zapisz, '
                .'kto ją cofnął i kiedy, w `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`, potem '
                .'wykonaj `UPDATE potwierdzenia_zadan_rodo SET konto_id = NULL WHERE wynik = '
                ."'wykonane'` i dopiero wtedy powtórz wycofanie. Jeśli wycofujesz wdrożenie, "
                .'a nie decyzję — cofnij sam KOD, nie tę migrację.',
            );
        }

        DB::statement(<<<'SQL'
            ALTER TABLE potwierdzenia_zadan_rodo
            ADD CONSTRAINT potwierdzenia_zadan_rodo_wykonane_bez_konta_check
            CHECK (wynik <> 'wykonane' OR konto_id IS NULL)
        SQL);
    }

    /**
     * CHECK-i zakłada wyłącznie gałąź PostgreSQL migracji zakładającej tabelę,
     * więc i zdejmować jest co wyłącznie tam.
     */
    private function dotyczy(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql'
            && Schema::hasTable('potwierdzenia_zadan_rodo');
    }
};
