<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Domain\Tags\TagSuggester;
use App\Models\Recipe;
use App\Models\Tag;
use App\Support\ProgPodobienstwa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TRAFNOŚĆ wyszukiwarki po przejściu na operator `<%` (issue #187).
 *
 * PO CO OSOBNY PLIK, SKORO WYSZUKIWARKA MA JUŻ TESTY
 * Bo tamte pilnują, że fraza COŚ znajduje. Ten pilnuje drugiej połowy
 * umowy — że fraza czegoś NIE znajduje. Do 9 września 2026 wyszukiwarka
 * używała operatora `%` z progiem 0,12, czyli mierzyła podobieństwo frazy do
 * CAŁEGO tytułu. Na bazie pomiarowej (40 000 przepisów, `docs/research/
 * WYDAJNOSC.md` §3.4b) dawało to wyniki, które wyglądają jak zepsuty serwis:
 *
 *     „sajgonki z krewetkami"  → 1 526 wyników w bazie bez jednej sajgonki
 *     „rosół"                  → „Rogaliki"
 *     „barszcz"                → „Bogracz"
 *     „pierogi"                → „Piernik"
 *     „gołąbki"                → „Golonka w piwie"
 *
 * Właściciel zdecydował o przejściu na `<%` (`word_similarity`) — pytanie
 * „czy fraza pasuje do najlepszego FRAGMENTU tytułu" zamiast „czy pasuje do
 * całości". Ten plik jest zapisem tamtej decyzji w postaci wykonywalnej,
 * żeby nie dało się jej cofnąć po cichu: pierwsza połowa testów mówi, co ma
 * być znajdowane, druga — co ma nie być.
 *
 * CZEGO TEN PLIK NIE SPRAWDZA
 * Czasu. Kilkanaście wierszy w bazie testowej to szum, a nie pomiar; liczby
 * (kandydaci z indeksu, milisekundy) są w `docs/research/WYDAJNOSC.md` §3.4b
 * i w opisie Pull Requesta.
 */
class TrafnoscWyszukiwarkiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const KORPUS = [
        'Rosół z kury',
        'Rogaliki',
        'Pierogi ruskie babci Haliny',
        'Pierogi z kapustą i grzybami',
        'Piernik staropolski',
        'Sernik babci Haliny',
        'Knedle ze śliwkami',
        'Smalec ze skwarkami',
        'Kiszona kapusta',
        'Uszka z grzybami',
        'Gołąbki',
        'Golonka w piwie',
        'Barszcz czerwony',
        'Bogracz',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Trafność mierzymy na pg_trgm — na SQLite ten test niczego nie sprawdza.');
        }

        $autor = $this->user('halina');

        foreach (self::KORPUS as $i => $tytul) {
            Recipe::factory()->create([
                'author_id' => $autor->getKey(),
                'title' => $tytul,
                'slug' => 'trafnosc-'.$i,
                // Opis ustawiony ręcznie: domyślny z fabryki jest losowy,
                // a wtedy gałąź `summary LIKE` potrafi trafić przypadkiem
                // i test mierzyłby loterię zamiast operatora.
                'summary' => 'Przepis domowy, robimy go u nas od lat.',
            ]);
        }
    }

    /** @return list<string> */
    private function szukaj(string $fraza): array
    {
        return app(SearchQuery::class)->recipes($fraza, null, 50)->pluck('title')->all();
    }

    // -----------------------------------------------------------------
    // CO MA BYĆ ZNAJDOWANE
    // -----------------------------------------------------------------

    public function test_dania_po_polsku_z_ogonkami_i_bez(): void
    {
        $this->assertContains('Gołąbki', $this->szukaj('gołąbki'));
        $this->assertContains('Gołąbki', $this->szukaj('golabki'));
        $this->assertContains('Rosół z kury', $this->szukaj('rosół'));
        $this->assertContains('Rosół z kury', $this->szukaj('ROSÓŁ'));
        $this->assertContains('Barszcz czerwony', $this->szukaj('barszcz'));
    }

    /**
     * LITERÓWKI, KTÓRE MUSZĄ DZIAŁAĆ.
     *
     * „rosul" to nie jest wymyślony przypadek: tak wygląda „rosuł" po
     * zdjęciu ogonków, czyli najczęstsza pomyłka w tym słowie. Jego
     * `word_similarity` wobec „rosół" wynosi DOKŁADNIE 0,5000, czyli tyle,
     * ile próg — operator porównuje `>=`, więc trafienie wchodzi. To jest
     * jedyne miejsce w tym repozytorium, które o tym wie, i dlatego ten
     * test istnieje osobno: gdyby PostgreSQL kiedykolwiek zmienił to na
     * ostre „większe", rosół zniknąłby z wyszukiwarki bez żadnego innego
     * objawu.
     */
    public function test_literowki_nadal_znajduja_przepis(): void
    {
        $this->assertContains('Sernik babci Haliny', $this->szukaj('sernk'));
        $this->assertContains('Pierogi ruskie babci Haliny', $this->szukaj('pierogy'));
        $this->assertContains('Rosół z kury', $this->szukaj('rosul'), 'Literówka „rosul” przestała znajdować rosół.');
    }

    /**
     * DŁUGA FRAZA WIELOWYRAZOWA — miejsce, o które issue #187 pytało wprost.
     * Fraza, która jest tytułem przepisu, ma ten przepis znajdować.
     */
    public function test_dluga_fraza_znajduje_przepis_o_takim_tytule(): void
    {
        $wyniki = $this->szukaj('pierogi z kapustą i grzybami');

        $this->assertContains('Pierogi z kapustą i grzybami', $wyniki);
        $this->assertSame('Pierogi z kapustą i grzybami', $wyniki[0] ?? null);
    }

    /** Fraza 2-znakowa (`SearchController` je przepuszcza) nie wysypuje zapytania. */
    public function test_fraza_dwuznakowa_dziala_i_znajduje_po_fragmencie(): void
    {
        $this->assertContains('Rogaliki', $this->szukaj('ro'));
    }

    // -----------------------------------------------------------------
    // CO MA NIE BYĆ ZNAJDOWANE — to jest treść decyzji z issue #187
    // -----------------------------------------------------------------

    /** Kanarek z issue #187: w bazie nie ma sajgonek, więc nie ma wyników. */
    public function test_fraza_bez_dania_w_bazie_nie_znajduje_niczego(): void
    {
        $this->assertSame([], $this->szukaj('sajgonki z krewetkami'));
        $this->assertSame([], $this->szukaj('tortilla z kurczakiem'));
        $this->assertSame([], $this->szukaj('kartacze'));
    }

    /**
     * Cztery pary, które przy progu 0,12 na operatorze `%` chodziły ze sobą
     * w parze mimo braku jakiegokolwiek związku. Każda z nich jest
     * zmierzonym trafieniem sprzed zmiany, nie hipotezą.
     */
    public function test_slowa_tylko_podobnie_wygladajace_nie_wpadaja_do_wynikow(): void
    {
        $this->assertNotContains('Rogaliki', $this->szukaj('rosół'));
        $this->assertNotContains('Bogracz', $this->szukaj('barszcz'));
        $this->assertNotContains('Golonka w piwie', $this->szukaj('gołąbki'));
        $this->assertNotContains('Knedle ze śliwkami', $this->szukaj('sajgonki z krewetkami'));
    }

    /**
     * Długa fraza nie ma prawa przyciągać wszystkiego, co ma z nią wspólne
     * pojedyncze słowo. Przy `%` @0,12 „pierogi z kapustą i grzybami"
     * zwracało na bazie pomiarowej 4 971 wierszy zamiast 272.
     */
    public function test_dluga_fraza_nie_zaciaga_wszystkiego_po_jednym_slowie(): void
    {
        $wyniki = $this->szukaj('pierogi z kapustą i grzybami');

        $this->assertNotContains('Kiszona kapusta', $wyniki);
        $this->assertNotContains('Uszka z grzybami', $wyniki);
    }

    // -----------------------------------------------------------------
    // KOLEJNOŚĆ (punkt 2 z issue #187)
    // -----------------------------------------------------------------

    /**
     * Sortowanie idzie po `word_similarity`, a `similarity` jest dopiero
     * drugim kryterium — i to jest różnica widoczna gołym okiem, nie
     * kosmetyka. Przy samym `similarity` (kryterium sprzed issue #187)
     * „Piernik staropolski" ma wobec frazy „pierogi" 0,33, a „Pierogi ruskie
     * babci Haliny" tylko 0,29, więc PIERNIK wychodził wyżej niż pierogi.
     * Na bazie pomiarowej stało tak 722 przepisów „Pierogi …".
     */
    public function test_prawdziwe_trafienie_stoi_przed_podobnym_slowem(): void
    {
        $wyniki = $this->szukaj('pierogi');

        $pozycjaPierogow = array_search('Pierogi ruskie babci Haliny', $wyniki, true);
        $pozycjaPiernika = array_search('Piernik staropolski', $wyniki, true);

        $this->assertNotFalse($pozycjaPierogow, 'Pierogi wypadły z wyników dla frazy „pierogi”.');

        if ($pozycjaPiernika !== false) {
            $this->assertLessThan(
                $pozycjaPiernika,
                $pozycjaPierogow,
                'Piernik stanął przed pierogami — kolejność wróciła do sortowania po podobieństwie do całego tytułu.',
            );
        }
    }

    // -----------------------------------------------------------------
    // GDZIE MIESZKA PRÓG (punkt 3 z issue #187)
    // -----------------------------------------------------------------

    public function test_prog_ustawia_jedna_klasa_i_naprawde_trafia_do_sesji(): void
    {
        ProgPodobienstwa::ustaw();

        $this->assertSame(
            (string) ProgPodobienstwa::PROG,
            DB::selectOne("SELECT current_setting('pg_trgm.word_similarity_threshold') AS prog")->prog,
        );
    }

    /**
     * JEDEN OPERATOR, JEDEN PRÓG. Operator `%` czyta INNE ustawienie sesji
     * (`set_limit`) niż `<%`, więc każde jego użycie w kodzie oznaczałoby
     * drugi próg, o którym `ProgPodobienstwa` nic nie wie — a taki rozjazd
     * nie daje żadnego objawu poza dziwnymi wynikami wyszukiwania.
     */
    public function test_kod_wyszukiwarki_nie_uzywa_juz_operatora_procent(): void
    {
        $pliki = [
            app_path('Domain/Search/SearchQuery.php'),
            app_path('Domain/Tags/TagSuggester.php'),
        ];

        foreach ($pliki as $plik) {
            $kod = (string) file_get_contents($plik);

            // Interesuje nas operator w SQL-u (`coś % ?`), a nie znak procenta
            // w wiązaniach `LIKE` ani w komentarzach.
            $this->assertDoesNotMatchRegularExpression(
                '/^(?!\s*(\*|\/\/)).*\w\s%\s\?/m',
                $kod,
                basename($plik).' używa operatora `%`, który czyta próg z `set_limit()` — '
                .'czyli drugi próg poza `App\Support\ProgPodobienstwa`.',
            );
        }
    }

    // -----------------------------------------------------------------
    // PODPOWIEDZI TAGÓW (punkt 4 z issue #187)
    // -----------------------------------------------------------------

    /**
     * Podpowiedzi tagów przeszły na ten sam operator co wyszukiwarka —
     * inaczej słowo „podobne" znaczyłoby w jednym produkcie dwie różne
     * rzeczy, zależnie od pola, w które człowiek pisze.
     *
     * UCZCIWA UWAGA: „piernik" wobec „pierogy" ma `word_similarity` równe
     * DOKŁADNIE 0,5, czyli tyle, ile próg — więc z listy nie znika, tylko
     * spada pod pierogi. Na pełnym słowniku (1 446 tagów) wypada przez to
     * poza ósemkę podpowiedzi, ale przy trzech tagach w tym teście jest
     * widoczny i tak ma być: test pilnuje KOLEJNOŚCI, a nie ukrywania.
     */
    public function test_podpowiedzi_tagow_ida_tym_samym_operatorem(): void
    {
        foreach (['pierogi', 'piernik', 'pierogi ruskie'] as $nazwa) {
            Tag::create([
                'name' => $nazwa,
                'normalized_name' => $nazwa,
                'slug' => str_replace(' ', '-', $nazwa),
                'is_seeded' => true,
            ]);
        }

        $podpowiedzi = app(TagSuggester::class)->sugeruj('pierogy')->pluck('name')->all();

        $this->assertContains('pierogi', $podpowiedzi, 'Literówka „pierogy” przestała podpowiadać tag „pierogi”.');
        $this->assertContains('pierogi ruskie', $podpowiedzi);

        $piernik = array_search('piernik', $podpowiedzi, true);

        if ($piernik !== false) {
            $this->assertLessThan(
                $piernik,
                array_search('pierogi', $podpowiedzi, true),
                'Podpowiedź „piernik” stanęła przed „pierogami” przy wpisanym „pierogy”.',
            );
            $this->assertLessThan(
                $piernik,
                array_search('pierogi ruskie', $podpowiedzi, true),
                'Podpowiedź „piernik” stanęła przed „pierogami ruskimi” przy wpisanym „pierogy”.',
            );
        }
    }
}
