<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Support\NumerSprawy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Backfill numerów spraw naprawdę się wykonuje — na tabeli, w której coś leży.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Migracja `2026_09_07_910000_add_numer_sprawy_to_reports` ma prywatną metodę
 * `uzupelnijIstniejace()`, która nadaje numer wierszom obecnym w tabeli PRZED
 * dołożeniem kolumny. `docs/DATABASE.md` i decyzja D-029 mówią „Backfill nadał
 * numery istniejącym wierszom" jako o fakcie — ale nie było testu, który by ten
 * backfill choć raz wykonał.
 *
 * DLACZEGO POZOSTAŁE TESTY TEGO NIE ŁAPIĄ
 * `RefreshDatabase` migruje PUSTĄ bazę. `whereNull('numer_sprawy')->chunkById(…)`
 * nie wchodzi wtedy ani razu do ciała pętli, więc `NumerSprawyTest`
 * i `CofniecieMigracjiNumerSprawyTest` sprawdzają wszystko dookoła: hak modelu,
 * UNIQUE, CHECK, strażnika w `down()` — a jedyny fragment tej migracji, który
 * DOTYKA ISTNIEJĄCYCH DANYCH, przechodzi przez CI nieuruchomiony.
 *
 * DLACZEGO TO NIE JEST test dla samego pokrycia
 * Numery są LOSOWE, a kolumna dostaje UNIQUE zaraz po backfillu. Powtórzone
 * losowanie nie kończy się brzydkim numerem, tylko `CREATE UNIQUE INDEX`, który
 * pada w środku migracji na produkcji. Numer w innym formacie nie kończy się
 * ostrzeżeniem, tylko CHECK-iem, który tej wartości nie przyjmie. Obie te
 * rzeczy widać wyłącznie wtedy, gdy w tabeli COŚ JEST.
 *
 * KSZTAŁT TESTU
 * `down()` na świeżej bazie zdejmuje kolumnę (na pustej tabeli strażnik nie ma
 * czego bronić) → surowy `INSERT` wierszy bez numeru, bo kolumny wtedy po
 * prostu nie ma → `up()` → sprawdzenie wyniku. To jest dokładnie ta sama droga,
 * którą przejdzie produkcja, a nie jej wyobrażenie.
 *
 * WIĘCEJ NIŻ JEDEN KAWAŁEK. Backfill czyta tabelę przez `chunkById(200, …)`.
 * Test wstawia 250 wierszy, żeby kursor musiał się przesunąć na drugi kawałek —
 * inaczej sprawdzałby pętlę, która wykonuje się raz i nigdy nie dochodzi do
 * miejsca, gdzie kursor może zgubić resztę tabeli. Pilnuje tego osobno
 * `test_liczba_wierszy_w_tescie_przekracza_kawalek_chunkbyid`: gdy ktoś podniesie
 * rozmiar kawałka w migracji, test powie o tym wprost, zamiast po cichu przestać
 * sprawdzać to, po co powstał.
 *
 * IDENTYFIKATORY WSTAWIAMY W INNEJ KOLEJNOŚCI, NIŻ ROSNĄ. `reports.id` to UUID
 * v7, a `chunkById` kasuje własne `orderBy` i sortuje po kursorze. Wiersze idą
 * więc do bazy w kolejności losowej (`shuffle`), żeby przejście całej tabeli nie
 * wynikało z tego, że kolejność fizyczna przypadkiem zgadza się z kolejnością
 * identyfikatorów.
 */
class BackfillNumerowSprawTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ile wierszy wstawiamy przed uruchomieniem migracji.
     *
     * Musi być WIĘCEJ niż rozmiar kawałka `chunkById` w migracji (200), inaczej
     * backfill zmieściłby się w jednym przebiegu pętli — patrz komentarz klasy
     * i `test_liczba_wierszy_w_tescie_przekracza_kawalek_chunkbyid`.
     */
    private const LICZBA_WIERSZY = 250;

    private const MIGRACJA = 'migrations/2026_09_07_910000_add_numer_sprawy_to_reports.php';

    private const INDEKS = 'reports_numer_sprawy_unique';

    private const CHECK = 'reports_numer_sprawy_check';

    private function migracja(): object
    {
        return require database_path(self::MIGRACJA);
    }

    private function kolumnaIstnieje(): bool
    {
        return DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['reports', 'numer_sprawy'],
        ) !== [];
    }

    private function kolumnaPrzyjmujeNull(): bool
    {
        $wiersz = DB::selectOne(
            'SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['reports', 'numer_sprawy'],
        );

        return $wiersz !== null && $wiersz->is_nullable === 'YES';
    }

    /**
     * Rozmiar kawałka wyczytany Z SAMEJ MIGRACJI, nie przepisany tutaj z pamięci.
     *
     * Przepisana stała rozjechałaby się przy pierwszej zmianie po drugiej
     * stronie i nikt by tego nie zauważył — bo test dalej byłby zielony,
     * tyle że przestałby dotykać drugiego kawałka.
     */
    private function rozmiarKawalkaZMigracji(): int
    {
        $zrodlo = (string) file_get_contents(database_path(self::MIGRACJA));

        $this->assertSame(
            1,
            preg_match('/chunkById\(\s*(\d+)/', $zrodlo, $dopasowanie),
            'W migracji nie ma już wywołania `chunkById(…)` — backfill czyta tabelę inaczej '
            .'i ten test opisuje nieistniejący mechanizm.',
        );

        return (int) $dopasowanie[1];
    }

    /**
     * Tabela `reports` w stanie SPRZED migracji: bez kolumny `numer_sprawy`
     * i z wierszami, które ta migracja ma dopiero ponumerować.
     *
     * Wiersze są zgłoszeniami prawnymi BEZ KONTA (`reporter_id` = NULL) —
     * i to jest ten przypadek, o który w D-029 chodzi: osoba bez konta nie ma
     * listy zgłoszeń, więc numer jest jedynym śladem sprawy. Przy okazji
     * `reporter_id IS NULL` wypada poza częściowy indeks
     * `reports_one_open_per_pair`, więc 250 otwartych spraw da się w ogóle
     * wstawić; `klucz_wyslania` zostaje pusty z tego samego powodu.
     *
     * @return list<string> identyfikatory wstawionych wierszy
     */
    private function wstawWierszeBezNumeru(): array
    {
        $this->migracja()->down();

        $this->assertFalse(
            $this->kolumnaIstnieje(),
            'Cofnięcie migracji nie zdjęło kolumny `numer_sprawy` — surowy `INSERT` niżej '
            .'odbiłby się o `NOT NULL`, a test mierzyłby coś innego, niż zakłada.',
        );

        $teraz = now();
        $identyfikatory = [];
        $wiersze = [];

        for ($i = 0; $i < self::LICZBA_WIERSZY; $i++) {
            // Znaczniki czasu rozsunięte co minutę: UUID v7 są przez to
            // rosnące i różne, a więc porównywalne kursorem `chunkById`.
            $powstalo = $teraz->copy()->subMinutes(self::LICZBA_WIERSZY - $i);
            $id = (string) Str::uuid7($powstalo);

            $identyfikatory[] = $id;

            $wiersze[] = [
                'id' => $id,
                'reporter_id' => null,
                'source' => Report::SOURCE_LEGAL_NOTICE,
                'notifier_name' => 'Zgłaszający '.($i + 1),
                'target_type' => 'unknown',
                'target_id' => null,
                'target_url' => 'https://kuking.pl/przepisy/rosol-babci-'.($i + 1),
                'reason' => 'copyright',
                'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
                'good_faith_at' => $powstalo,
                'status' => Report::STATUS_OPEN,
                'created_at' => $powstalo,
                'updated_at' => $powstalo,
            ];
        }

        // Kolejność wstawiania inna niż kolejność identyfikatorów — patrz
        // komentarz klasy.
        shuffle($wiersze);

        foreach (array_chunk($wiersze, 50) as $paczka) {
            DB::table('reports')->insert($paczka);
        }

        // ASERCJA KONTROLNA: bez niej cały test przeszedłby także na PUSTEJ
        // tabeli, czyli nie mierzyłby niczego — a to jest dokładnie ta pułapka,
        // przez którą `uzupelnijIstniejace()` nie miało pokrycia.
        $this->assertSame(
            self::LICZBA_WIERSZY,
            DB::table('reports')->count(),
            'W tabeli nie leży tyle wierszy, ile test wstawił — backfill nie miałby czego robić.',
        );

        return $identyfikatory;
    }

    /**
     * ASERCJA KONTROLNA CAŁEGO PLIKU — patrz komentarz klasy.
     */
    public function test_liczba_wierszy_w_tescie_przekracza_kawalek_chunkbyid(): void
    {
        $kawalek = $this->rozmiarKawalkaZMigracji();

        $this->assertLessThan(
            self::LICZBA_WIERSZY,
            $kawalek,
            'Migracja czyta tabelę kawałkami po '.$kawalek.' wierszy, a test wstawia ich '
            .self::LICZBA_WIERSZY.'. Backfill zmieściłby się w jednym przebiegu pętli, więc test '
            .'przestał sprawdzać to, po co powstał: że kursor przechodzi CAŁĄ tabelę. '
            .'Podnieś LICZBA_WIERSZY powyżej '.$kawalek.'.',
        );
    }

    public function test_backfill_nadaje_numer_kazdemu_wierszowi_takze_za_pierwszym_kawalkiem(): void
    {
        $identyfikatory = $this->wstawWierszeBezNumeru();

        $this->migracja()->up();

        $this->assertTrue($this->kolumnaIstnieje(), 'Migracja nie dołożyła kolumny `numer_sprawy`.');

        $this->assertSame(
            0,
            DB::table('reports')->whereNull('numer_sprawy')->count(),
            'Backfill zostawił wiersze bez numeru sprawy.',
        );

        $numery = DB::table('reports')->orderBy('id')->pluck('numer_sprawy', 'id');

        $this->assertEqualsCanonicalizing(
            $identyfikatory,
            $numery->keys()->all(),
            'Po migracji w tabeli są inne wiersze niż wstawione — backfill coś zgubił albo dołożył.',
        );

        // CAŁA TABELA, NIE PIERWSZY KAWAŁEK. Wiersze za granicą pierwszego
        // przebiegu `chunkById` sprawdzamy WPROST: gdyby kursor po UUID-zie
        // v7 nie przesuwał się poprawnie, to właśnie one zostałyby puste.
        $ogon = array_slice($numery->values()->all(), $this->rozmiarKawalkaZMigracji());

        $this->assertNotEmpty(
            $ogon,
            'Za pierwszym kawałkiem nie ma ani jednego wiersza — test nie dotyka drugiego przebiegu pętli.',
        );

        foreach ($ogon as $numer) {
            $this->assertNotNull(
                $numer,
                'Wiersz za pierwszym kawałkiem został bez numeru — kursor `chunkById` nie przeszedł całej tabeli.',
            );
        }

        // I druga strona tej samej rzeczy: skoro kolumna jest już `NOT NULL`,
        // to znaczy, że `up()` doszło do końca, a nie zatrzymało się na
        // wierszu, którego backfill nie dosięgnął.
        $this->assertFalse(
            $this->kolumnaPrzyjmujeNull(),
            'Kolumna `numer_sprawy` została `NULL`-owalna — migracja nie doszła do końca.',
        );
    }

    public function test_backfill_nadaje_numery_rozne_i_w_formacie_z_ekranu(): void
    {
        $this->wstawWierszeBezNumeru();

        $this->migracja()->up();

        /** @var list<string> $numery */
        $numery = array_map(
            static fn (mixed $numer): string => (string) $numer,
            DB::table('reports')->pluck('numer_sprawy')->all(),
        );

        $this->assertCount(self::LICZBA_WIERSZY, $numery);

        // SEDNO. Generator jest LOSOWY, a kolumna dostaje UNIQUE zaraz po
        // backfillu — powtórzone losowanie nie kończy się brzydkim numerem,
        // tylko `CREATE UNIQUE INDEX`, który pada w środku migracji.
        $this->assertCount(
            self::LICZBA_WIERSZY,
            array_unique($numery),
            'Backfill nadał dwóm sprawom ten sam numer. Dla zgłaszającego bez konta numer jest '
            .'jedynym śladem sprawy, a każda ma własny termin odpowiedzi z DSA art. 16.',
        );

        // Wzór bierzemy z `NumerSprawy::WZOR` — z tej samej stałej, z której
        // składa się CHECK w bazie. Sprawdzamy CAŁY zbiór naraz, żeby komunikat
        // pokazał wszystkie złe numery, a nie tylko pierwszy z brzegu.
        $zleZlozone = array_values(array_filter(
            $numery,
            static fn (string $numer): bool => preg_match(NumerSprawy::WZOR, $numer) !== 1,
        ));

        $this->assertSame(
            [],
            $zleZlozone,
            'Backfill wstawił numer spoza formatu `KU-XXXX-XXXX` — a ten numer trafia do pisma '
            .'i do korespondencji ze zgłaszającym.',
        );

        // Ograniczenia w BAZIE, nie sam wynik losowania: to one sprawiają, że
        // powtórzony albo źle złożony numer jest niemożliwy, a nie tylko
        // nieprawdopodobny (AGENTS.md §6).
        $this->assertSame(
            1,
            DB::table('pg_indexes')->where('indexname', self::INDEKS)->count(),
            'Po backfillu nie ma indeksu UNIQUE na numerze sprawy.',
        );

        $this->assertSame(
            1,
            DB::table('pg_constraint')->where('conname', self::CHECK)->count(),
            'Po backfillu nie ma CHECK-a na formacie numeru sprawy.',
        );
    }
}
