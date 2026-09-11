<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FACEBOOK WCHODZI NA ZAMKNIĘTĄ LISTĘ DOSTAWCÓW (issue #259, D-098).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TA MIGRACJA ROBI I DLACZEGO JEST OSOBNYM PLIKIEM
 * ────────────────────────────────────────────────────────────────────────
 *
 * Podmienia `tozsamosci_dostawca_check` z `dostawca IN ('google')` na
 * `dostawca IN ('google', 'facebook')`. To wszystko — ani jednej kolumny,
 * ani jednego indeksu więcej, bo tabela `tozsamosci_zewnetrzne` była
 * projektowana pod dwóch dostawców i Facebook dostaje od niej wszystko
 * gotowe: oba `UNIQUE`, puste `$fillable`, kaskadę, kształt identyfikatora.
 *
 * Osobnym plikiem jest z powodu wypisanego w migracji tworzącej tabelę:
 * `'facebook'` NIE BYŁO na liście świadomie, żeby `INSERT` z tą nazwą się
 * ODBIŁ, dopóki ktoś nie napisze tej migracji — a napisanie jej zmusza do
 * przeczytania, czym Facebook różni się od Google'a. Różnica jest jedna
 * i jest fundamentem, nie szczegółem:
 *
 *   **Facebook nie mówi, czy adres e-mail jest potwierdzony.** Warunek
 *   z D-069 („bez `email_verified` nie robimy nic") jest dla niego
 *   NIESPEŁNIALNY — nie trudny, niespełnialny, bo danych, na których stoi,
 *   po prostu nie ma. Dlatego adres z Facebooka **nigdy** nie łączy
 *   z istniejącym kontem Kuking i **nigdy** nie trafia do bazy jako
 *   potwierdzony (D-098, `FacebookLoginController`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO `DROP CONSTRAINT` + `ADD CONSTRAINT`, A NIE `ALTER`
 * ────────────────────────────────────────────────────────────────────────
 *
 * PostgreSQL nie umie zmienić treści CHECK-a w miejscu. Podmiana jest przy
 * tym tania: tabela ma tyle wierszy, ile jest powiązanych kont (dziś:
 * jednostki), a `ADD CONSTRAINT` musi je przejrzeć raz. Blokada trwa
 * milisekundy i nie ma tu potrzeby `NOT VALID` ani dwóch kroków.
 *
 * KOLEJNOŚĆ MA ZNACZENIE W DRUGĄ STRONĘ: przy cofnięciu najpierw pytamy,
 * czy w tabeli są wiersze Facebooka, a dopiero potem cokolwiek zmieniamy —
 * patrz `down()`.
 */
return new class extends Migration
{
    private const TABELA = 'tozsamosci_zewnetrzne';

    private const OGRANICZENIE = 'tozsamosci_dostawca_check';

    /** Lista PO tej migracji. */
    private const DOSTAWCY_PO = ['google', 'facebook'];

    /** Lista PRZED tą migracją — do niej wraca `down()`. */
    private const DOSTAWCY_PRZED = ['google'];

    public function up(): void
    {
        if (! $this->pgZTabela()) {
            return;
        }

        $this->ustawListe(self::DOSTAWCY_PO);
    }

    /**
     * COFNIĘCIE ODMAWIA, GDY KTOŚ WCHODZI KONTEM FACEBOOKA — D-088.
     *
     * ────────────────────────────────────────────────────────────────────
     *  DLACZEGO TU JEST `throw`, A NIE KOMENTARZ „UWAGA PRZY COFANIU"
     * ────────────────────────────────────────────────────────────────────
     *
     * Zwężenie CHECK-a z powrotem do `('google')` nie da się wykonać, gdy
     * w tabeli leży choć jeden wiersz Facebooka — PostgreSQL odrzuci
     * `ADD CONSTRAINT`. Kuszące „rozwiązanie" tego problemu jest jedno
     * i jest dokładnie tą chorobą, którą opisuje D-088: skasować te wiersze
     * po cichu, żeby cofnięcie „przeszło". Skutek byłby taki, że osoba,
     * która weszła do Kuking kontem Facebooka i **nigdy nie ustawiła hasła**
     * (w `password` leży skrót wartości losowej, której nie zna nikt, także
     * my), traci jedyną drogę wejścia, jaką zna — a `down()` prawie nigdy nie
     * występuje sam: po nim idzie kolejny `migrate`, więc CHECK wraca, żaden
     * wiersz nie brakuje w sposób widoczny i **nie ma błędu do zauważenia**.
     *
     * Dlatego zabezpieczeniem jest `throw` w `down()`, a nie zdanie
     * w komentarzu przenoszące ochronę na czyjąś pamięć o drugiej w nocy.
     *
     * ODMOWA JEST WĄSKA (też D-088): gdy wierszy Facebooka nie ma —
     * na świeżej bazie, w CI, na stagingu przed pierwszym wejściem —
     * cofnięcie przechodzi bez pytania. Migracja, która nie cofa się nigdy,
     * jest błędem tej samej wagi w drugą stronę, więc ma to test
     * (kontrola dodatnia w `CofniecieMigracjiFacebookaOdmawiaTest`).
     *
     * UWAGA NA `migrate:rollback --step 1`: cofa migrację NAJPÓŹNIEJSZĄ,
     * niekoniecznie tę. Żeby cofnąć właśnie ją, wołaj `down()` wprost albo
     * podaj odpowiednią liczbę kroków.
     */
    public function down(): void
    {
        if (! $this->pgZTabela()) {
            return;
        }

        $powiazane = $this->ileKontFacebooka();

        if ($powiazane > 0 && ! $this->wolnoKasowacPowiazania()) {
            // Rzeczownik PRZED liczbą, liczba na końcu zdania — „dla 1 kont"
            // to nie polszczyzna, a jedno konto jest stanem
            // prawdopodobniejszym niż pięć. Mianownik przed dwukropkiem nie
            // odmienia się wcale, więc zdanie jest poprawne dla 1, 2, 5 i 22.
            throw new RuntimeException(
                'Cofnięcie tej migracji musiałoby skasować powiązania z kontami Facebooka. '
                .'Liczba kont, których to dotyczy: '.$powiazane.'. '
                ."Dla części z nich to jedyna droga wejścia, jaką znają (hasła nigdy nie ustawiały).\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'Wyłącz funkcję bez migracji: KUKING_WEJSCIE_FACEBOOK=false i restart serwisu. '
                ."Przycisk znika, konta działają dalej, powiązania zostają nietknięte.\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ SKASOWAĆ POWIĄZANIA Z FACEBOOKIEM\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_FACEBOOK=true '
                .'php artisan migrate:rollback — i uprzedź te osoby, że wejdą przez '
                .'„Nie pamiętam hasła” albo przez wiadomość z linkiem do zalogowania '
                .'(adres z Facebooka jest u nas niepotwierdzony, więc część z nich musi '
                .'najpierw potwierdzić adres — sprawdź to, zanim skasujesz).',
            );
        }

        /*
         * ZGODA WYPOWIEDZIANA WPROST kasuje wiersze Facebooka, bo inaczej
         * zwężenie CHECK-a odbiłoby się o bazę i cofnięcie stanęłoby
         * w połowie. Kasujemy WYŁĄCZNIE wiersze tego dostawcy — powiązania
         * z Google nie mają z tą migracją nic wspólnego i nie wolno ich
         * ruszyć przy okazji.
         */
        if ($powiazane > 0) {
            DB::table(self::TABELA)->where('dostawca', 'facebook')->delete();
        }

        $this->ustawListe(self::DOSTAWCY_PRZED);
    }

    /**
     * @param  list<string>  $dostawcy
     */
    private function ustawListe(array $dostawcy): void
    {
        $lista = "'".implode("', '", $dostawcy)."'";

        DB::statement('ALTER TABLE '.self::TABELA.' DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);

        DB::statement(
            'ALTER TABLE '.self::TABELA.' ADD CONSTRAINT '.self::OGRANICZENIE
            ." CHECK (dostawca IN ({$lista}))",
        );
    }

    private function ileKontFacebooka(): int
    {
        return (int) DB::table(self::TABELA)->where('dostawca', 'facebook')->count();
    }

    /**
     * `getenv()`, a nie `env()` ani `config()` — tak jak w pozostałych
     * migracjach z tym samym zabezpieczeniem. `env()` oddaje `null` przy
     * zbuforowanej konfiguracji, a zgoda na skasowanie cudzych drzwi nie ma
     * prawa zależeć od tego, czy ktoś uruchomił wcześniej `config:cache`.
     */
    private function wolnoKasowacPowiazania(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_FACEBOOK'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * CHECK-i zakłada tylko PostgreSQL (tak samo jak migracja tworząca
     * tabelę), a tabeli może nie być wcale, gdy ta migracja jest cofana
     * po tamtej — wtedy nie ma czego zmieniać i nie ma o co krzyczeć.
     */
    private function pgZTabela(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql'
            && Schema::hasTable(self::TABELA);
    }
};
