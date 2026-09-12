<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usuwa `media.perceptual_hash` — kolumnę, której NIC nie liczy i NIC nie czyta.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Przegląd schematu wobec `docs/DATABASE.md` (D-166) wymienił trzy kolumny
 * podejrzane o martwotę: `media.perceptual_hash`, `collection_items.note`
 * i `daily_picks.note`. Weryfikacja w kodzie przed napisaniem tej migracji
 * potwierdziła martwotę **tylko pierwszej z nich** — dwie pozostałe są żywe
 * i ZOSTAJĄ (uzasadnienie niżej, żeby następny przegląd nie zaczynał od zera).
 *
 * DLACZEGO TA JEDNA JEST MARTWA — ZMIERZONE, NIE ZAŁOŻONE
 * Jedyne wystąpienie w kodzie produkcyjnym to `$fillable` modelu `Media`
 * (`grep -rn "perceptual_hash" app/` → 1 trafienie, usuwane tą samą zmianą).
 * Nie ma liczenia skrótu w potoku zdjęć, nie ma odczytu w moderacji, nie ma
 * wyjścia w eksporcie danych użytkownika ani w żadnym API. `$fillable` bez
 * zapisu nie jest użyciem — to lista pól, których nikt nie podaje.
 *
 * DLACZEGO DWÓCH POZOSTAŁYCH TU NIE MA
 *  - `collection_items.note` — zapisywana przez `SavePostToCollection`
 *    i `SaveRecipeToCollection` (parametr `$note`), a przede wszystkim
 *    CZYTANA w eksporcie danych osobowych jako `moja_notatka`
 *    (`CollectUserExportData`). Usunięcie zabrałoby człowiekowi pole
 *    z paczki RODO.
 *  - `daily_picks.note` — zdanie gospodarza pod kartą na tablicy dnia.
 *    Zapisywana z formularza panelu (`DailyBoardController`), czytana przez
 *    `DailyBoard` i WYŚWIETLANA w `kuking-board.blade.php`.
 *
 * Obie sprawdzone doświadczalnie: próbne skasowanie ich obu oblało pięć
 * testów w `DataExportTest` i `DailyBoardTest` błędem
 * `SQLSTATE[42703] … column "note" does not exist`. Skasowanie
 * `perceptual_hash` nie oblewa niczego — i to jest cała różnica.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PLAN ROLLBACKU (opisany, nie założony — AGENTS.md §6 pkt 4)
 * ─────────────────────────────────────────────────────────────────────────
 * `php artisan migrate:rollback` przywraca kolumnę `varchar(128) NULL`
 * w tabeli `media`. Po cofnięciu schemat jest identyczny co do typu,
 * długości i dopuszczania `NULL`; różni się wyłącznie POZYCJĄ kolumny
 * (wraca na koniec tabeli zamiast między `checksum_sha256` a `metadata`).
 * Kolejność kolumn nie jest w tym projekcie niczyim kontraktem — nigdzie nie
 * ma `SELECT *` zależnego od pozycji ani `INSERT` bez listy kolumn — więc ta
 * różnica nie zmienia zachowania. Danych do przywrócenia nie ma, bo nie było
 * ich przed usunięciem (patrz akapit o D-088).
 *
 * Cofnięcie nie wymaga okna serwisowego ani kopii zapasowej: `ADD COLUMN`
 * z wartością `NULL` nie przepisuje tabeli w PostgreSQL.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * D-088: CZY `down()` NISZCZY WARTOŚĆ SEMANTYCZNĄ? **NIE** — i oto dlaczego
 * ─────────────────────────────────────────────────────────────────────────
 * D-088 każe `down()` ODMÓWIĆ, gdy cofnięcie przywraca stan groźny albo
 * podmienia znaczenie decyzji człowieka (zgoda, zakres usunięcia danych,
 * widoczność, prywatność zeszytu). Tu nie zachodzi żaden z tych warunków
 * i nie milczymy o tym, tylko nazywamy powód:
 *
 *  1. W kolumnie NIE MA ANI JEDNEGO WIERSZA Z DANYMI — nic jej nigdy nie
 *     zapisywało, więc `down()` nie ma czego zgubić ani czego przekłamać.
 *  2. Nie jest to decyzja człowieka. Skrót percepcyjny to wartość WYLICZANA
 *     z pliku — gdyby kiedyś powstał kod, który ją liczy, policzyłby ją
 *     ponownie z tego samego zdjęcia. To odwrotność `delete_scope` czy
 *     `memories_enabled`, których nie da się odtworzyć znikąd.
 *  3. `down()` nie przywraca stanu groźnego: pusta kolumna `NULL` nie włącza
 *     żadnej funkcji ani nie odsłania żadnej treści.
 *
 * STRAŻNIK STOI PRZY `up()`, NIE PRZY `down()` — I TO JEST ŚWIADOME
 * W tej migracji kierunkiem NISZCZĄCYM jest `up()`, nie `down()`: to skasowanie
 * kolumny zabiera dane, a jej odtworzenie niczego nie zabiera. Wzorzec z D-088
 * (policz wiersze, rzuć `RuntimeException` z instrukcją i furtką przez
 * `getenv()` — `2026_09_10_400000_create_dziennik_zgod_table.php:215`) zostaje
 * więc zastosowany, ale po właściwej stronie.
 *
 * Powód jest praktyczny, nie ceremonialny. Zdanie „każdy wiersz ma `NULL`"
 * zmierzono na bazie testowej i wyprowadzono z kodu — a NIE odczytano
 * z produkcji, do której ta sesja nie ma dostępu. Gdyby między przeglądem
 * a wdrożeniem ktoś jednak tę kolumnę wypełnił (migracja danych, ręczny
 * `UPDATE`, dograny skrypt), `up()` bez strażnika skasowałby te wartości
 * bez śladu. Strażnik zamienia cichą stratę w zatrzymane wdrożenie
 * z instrukcją — dokładnie tak, jak D-088 chce tego przy `down()`.
 *
 * Odmowa jest WĄSKA: na kolumnie pustej (czyli wszędzie, gdzie ustalenie
 * z D-166 jest prawdziwe) migracja przechodzi bez pytania. Zablokowanie jej
 * na zawsze byłoby błędem tej samej wagi w drugą stronę.
 */
return new class extends Migration
{
    /**
     * Furtka dla właściciela, gdyby kolumna jednak miała dane, a mimo to
     * miała zniknąć. Ta sama forma co w pozostałych migracjach z odmową.
     */
    private const FURTKA = 'KUKING_USUN_NIEPUSTY_PERCEPTUAL_HASH';

    public function up(): void
    {
        if (! Schema::hasColumn('media', 'perceptual_hash')) {
            return;
        }

        $wypelnionych = $this->ileWypelnionych();

        if ($wypelnionych > 0 && ! $this->wolnoKasowac()) {
            throw new RuntimeException(
                'Kolumna media.perceptual_hash miała być pusta, a nie jest — '
                .'ta migracja zatrzymuje się, zamiast skasować dane bez śladu. '
                .'Liczba zapisów, które znikną: '.$wypelnionych.". \n\n"
                ."DLACZEGO TO ZATRZYMANIE\n"
                .'Migracja powstała na ustaleniu, że kolumny nic nie wypełnia '
                .'(D-166). Skoro wypełnia, ustalenie jest nieaktualne: ktoś '
                ."dopisał liczenie skrótu percepcyjnego albo wgrał je skryptem.\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'1. Sprawdź, co te wartości zapisało — jeśli potok zdjęć liczy '
                .'dziś skrót, kolumna jest ŻYWA i tej migracji nie wolno '
                ."wykonywać; usuń ją z gałęzi.\n"
                .'2. Jeśli to jednorazowy import do wyrzucenia, odłóż go poza '
                ."bazę przed skasowaniem:\n"
                .'   \\copy (SELECT id, perceptual_hash FROM media WHERE perceptual_hash IS NOT NULL) '
                ."to 'perceptual_hash.csv' csv header\n\n"
                ."JEŚLI TE WARTOŚCI MAJĄ NAPRAWDĘ ZNIKNĄĆ\n"
                .'Powiedz to wprost: '.self::FURTKA.'=true php artisan migrate',
            );
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('perceptual_hash');
        });
    }

    /**
     * Przywraca kolumnę w tej samej postaci: `varchar(128)`, dopuszczająca
     * `NULL`, bez wartości domyślnej, bez indeksu (nie miała go nigdy —
     * indeks `media_checksum_idx` stoi na `checksum_sha256`, to inna kolumna).
     *
     * Pusta po cofnięciu, bo pusta była przed usunięciem. To nie jest
     * przemilczenie straty — to jej brak, uzasadniony w nagłówku pliku.
     */
    public function down(): void
    {
        if (Schema::hasColumn('media', 'perceptual_hash')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->string('perceptual_hash', 128)->nullable();
        });
    }

    private function ileWypelnionych(): int
    {
        if (! Schema::hasTable('media')) {
            return 0;
        }

        return (int) DB::table('media')->whereNotNull('perceptual_hash')->count();
    }

    /**
     * `getenv()`, a nie `env()` ani `config()` — tak jak w pozostałych
     * migracjach z tym samym zabezpieczeniem. `env()` oddaje `null` przy
     * zbuforowanej konfiguracji, więc zgoda na skasowanie danych nie ma
     * prawa zależeć od tego, czy ktoś uruchomił wcześniej `config:cache`.
     */
    private function wolnoKasowac(): bool
    {
        return in_array(strtolower((string) getenv(self::FURTKA)), ['1', 'true', 'yes'], true);
    }
};
