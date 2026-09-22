<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `docs/DATABASE.md` nazywa każdą kolumnę tekstową, która naprawdę jest w bazie.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * `AGENTS.md` stawia regułę „zmiana schematu = migracja + test +
 * `docs/DATABASE.md` + rollback". Reguła obowiązuje od pierwszego dnia,
 * a mimo to 11 września 2026 przy PR #403 (D-156) okazało się, że kolumny
 * `recipes.source_person`, `recipes.source_note` i `recipes.source_url`
 * **nie ma w tym dokumencie ani razu** — sekcja `### recipes` składała się
 * z dwóch słów („Aktualny stan."). Nie był to jeden przypadek: pełny przegląd
 * schematu wobec dokumentu znalazł 22 takie kolumny w 13 tabelach.
 *
 * CO TO KOSZTOWAŁO — ZMIERZONE, NIE HIPOTETYCZNE
 * `source_person` nazywa się „person" i jest `varchar(120)`. Z nazwy i typu
 * wynika, że leży tam człowiek. Naprawdę leży tam **nazwa grupy na Facebooku**,
 * tytuł gazety albo zdanie „od mamy" — właściciel potwierdził to dopiero przy
 * zgłoszeniu. Zanim ktoś zapytał, ta sama nieprawda została zbudowana DWA razy:
 * widok doklejał przyimek i pokazywał „Po Nasze smaki." (D-153, PR #397),
 * a blok JSON-LD wypuszczał tę wartość do Google jako `Person` ze sklejonym
 * `name` z jednej encji i `url` z drugiej (D-156, PR #403). Obie naprawy
 * kosztowały cudzą pracę. **Dokument, który by powiedział, co w tej kolumnie
 * naprawdę leży, zatrzymałby obie przed napisaniem pierwszej linijki.**
 *
 * DLACZEGO TEN TEST NIE WYMAGA OPISANIA KAŻDEJ KOLUMNY
 * Wersja „każda kolumna schematu ma być w dokumencie" oblewałaby przy
 * KAŻDEJ migracji — także takiej, która dokłada `position`, `created_at`
 * albo `cos_id`. Taki strażnik zostaje wyłączony w tydzień, a strażnik,
 * którego się wyłącza, nie jest strażnikiem. Ten test bierze więc wyłącznie
 * **kolumny tekstowe** (`text`, `varchar`, `char`) i to jest cała granica
 * zakresu. Powód nie jest arbitralny:
 *
 *  - kolumna tekstowa to **jedyny rodzaj kolumny, której zawartości nie da
 *    się odczytać z nazwy i typu**. `family_since_year smallint` mówi o sobie
 *    wszystko; `source_person varchar(120)` mówi nieprawdę;
 *  - to tam trafia treść wpisana przez człowieka — a o niej dokument ma
 *    powiedzieć PRAWDĘ, nie powtórzyć nazwę pola;
 *  - każdy znany nam błąd tej klasy siedział w kolumnie tekstowej.
 *
 * Nowa migracja dokładająca `varchar` wymusi więc jedno zdanie w dokumencie —
 * i to jest dokładnie ta praca, którą `AGENTS.md` już nakazuje. Migracja
 * z samymi liczbami, datami i kluczami obcymi tego testu nie obudzi.
 *
 * DLACZEGO LISTA TABEL JEST LISTĄ WYKLUCZEŃ, A NIE LISTĄ OBJĘTYCH
 * Lista tabel „objętych obowiązkiem" cichłaby przy każdej nowej tabeli:
 * kto jej nie dopisze, ten nie zostanie o nią zapytany. Odwrotnie jest tylko
 * siedem tabel, każda z jawnym powodem (`TABELE_FRAMEWORKA`), a wszystko poza
 * nimi jest objęte z urzędu. Nowa tabela wchodzi pod obowiązek sama.
 *
 * SKĄD BIERZE SIĘ SCHEMAT
 * Z `information_schema` żywej bazy testowej, po wykonaniu migracji — nie
 * z czytania plików migracji. Migracje bywają wielokrotne (kolumna dodana,
 * potem przetypowana, potem usunięta — `posts.topic_id`), a liczy się stan
 * końcowy. Test czytający migracje potrafiłby wymagać opisu kolumny, której
 * dziś nie ma.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE PILNUJE — TO GRANICE, NIE PRZEOCZENIA
 *  - **Czy opis jest PRAWDZIWY.** Sprawdzamy, że dokument kolumnę NAZYWA,
 *    nie że mówi o niej prawdę. Prawdy o danych nie da się wyprowadzić ze
 *    schematu — `source_person` był `varchar(120) NULL` także wtedy, gdy
 *    wszyscy myśleli, że to człowiek. Ta sama granica stoi przy
 *    `TabelaStackuMowiPrawdeTest`.
 *  - **GDZIE w dokumencie kolumna jest nazwana.** Liczy się wzmianka w całym
 *    pliku, nie w sekcji tej tabeli. Wymaganie sekcji oblewałoby przy każdym
 *    przestawieniu dokumentu, czyli byłoby tą kruchością, przez którą
 *    strażników się wyłącza. Cena jest jawna: kolumna o nazwie powtarzającej
 *    się w kilku tabelach (`note`, `body`, `name`) zalicza się na wzmiance
 *    z dowolnej z nich. Pilnowana usterka to „o tej kolumnie nie napisano
 *    NIC" — a nazwa nowa i nieopisana nie zderzy się z niczym.
 *  - **Kolumn nietekstowych, indeksów, kluczy obcych, CHECK-ów i wartości
 *    domyślnych.** Osobna sprawa i osobna naprawa.
 *  - **Kierunku odwrotnego: wpisów w dokumencie o kolumnach, których już nie
 *    ma.** Dokument świadomie opisuje też rzeczy USUNIĘTE i ODRZUCONE
 *    (`users.google_sub`, `posts.topic_id`, `users.pending_email`) — nazywając
 *    je wprost „nie ma ich". Test odróżniający taki wpis od zapomnianego
 *    musiałby czytać zdania po polsku, a nie nazwy.
 */
class DokumentacjaBazyOpisujeSchematTest extends TestCase
{
    use RefreshDatabase;

    private const DOKUMENT = 'docs/DATABASE.md';

    /**
     * Typy, które ten test uważa za „kolumna tekstowa".
     *
     * Nazwy z `information_schema.columns.data_type`, czyli takie, jakimi
     * mówi o nich PostgreSQL — nie `varchar`/`char` z języka migracji.
     */
    private const TYPY_TEKSTOWE = ['text', 'character varying', 'character'];

    /**
     * Tabele poza obowiązkiem opisu — **z powodem przy każdej**.
     *
     * Wszystkie siedem zakłada Laravel swoimi migracjami z `0001_01_01_*`
     * i żadnej z nich nie projektowaliśmy. `docs/DATABASE.md` nosi nagłówek
     * „Model danych" i opisuje NASZE dane; przepisywanie do niego kolumn
     * kolejki zadań byłoby szumem, przez który trudniej znaleźć rzecz ważną.
     *
     * Lista jest krótka i ma taka zostać. Dopisanie do niej NASZEJ tabeli to
     * wyłączanie tego testu, a nie sprzątanie — od tego jest próg
     * MIN_KOLUMN_W_ZAKRESIE niżej.
     *
     * `sessions` BYŁA na tej liście do 21.09.2026 i została z niej ZDJĘTA.
     * Tabelę rzeczywiście zakłada Laravel, ale leży w niej para
     * (`user_id`, adres IP, `User-Agent`) — NASZE dane osobowe, nie techniczne
     * wnętrzności frameworka. Badanie RZ-01 ustaliło, że sześć kolejnych
     * audytów prywatności przeoczyło tę tabelę, i nazwało przyczynę: nie było
     * jej w `docs/DATABASE.md`, bo nikt jej nie „dodawał", więc nikt nie
     * przeszedł ścieżki „migracja + test + dokument". Wyjątek dla sterownika
     * frameworka kosztował tu dokładnie to, przed czym ta lista miała chronić.
     * Kryterium jest więc „czyje to dane", a nie „kto napisał migrację".
     */
    private const TABELE_FRAMEWORKA = [
        'migrations',           // rejestr wykonanych migracji, prowadzi go Laravel
        'cache',                // sterownik cache'a
        'cache_locks',          // sterownik cache'a
        'jobs',                 // kolejka zadań
        'job_batches',          // kolejka zadań
        'failed_jobs',          // kolejka zadań
        'password_reset_tokens', // wbudowane resetowanie hasła
    ];

    /**
     * PROGI — bez nich ten test jest zielony na zawsze.
     *
     * Test skanujący przechodzi także wtedy, gdy nie znajdzie NICZEGO: zbiór
     * usterek z pustego skanu jest pusty, więc każda asercja przechodzi
     * (`docs/PULAPKI_TESTOW.md`, pułapka 2). Zła nazwa schematu, literówka
     * w nazwie typu albo odczyt z pustej bazy ma tu OBLAĆ — i to
     * z komunikatem mówiącym, że zepsuł się TEST, a nie dokument.
     *
     * Liczby w nawiasach zmierzone 12.09.2026, progi z zapasem:
     *
     *  - MIN_TABEL_W_ZAKRESIE = 30 (dziś 42: 49 tabel minus 7 frameworkowych;
     *    było 41 minus 8, zanim `sessions` zeszła z listy wyłączeń).
     *  - MIN_KOLUMN_W_ZAKRESIE = 100 (dziś 129). To zamek na jedynym wytrychu,
     *    jaki ta konstrukcja ma: wpisaniu naszych tabel do TABELE_FRAMEWORKA
     *    albo zawężeniu TYPY_TEKSTOWE, żeby test zamilkł.
     *  - MIN_DLUGOSC_DOKUMENTU = 50000 znaków (dziś ok. 181000). Pilnuje tego,
     *    że czytamy ten plik, co trzeba: wobec pustego łańcucha KAŻDA kolumna
     *    zgłosiłaby się jako nieopisana, więc bez tego progu awaria odczytu
     *    wyglądałaby jak 129 usterek dokumentu.
     */
    private const MIN_TABEL_W_ZAKRESIE = 30;

    private const MIN_KOLUMN_W_ZAKRESIE = 100;

    private const MIN_DLUGOSC_DOKUMENTU = 50000;

    public function test_kazda_kolumna_tekstowa_schematu_jest_nazwana_w_dokumencie(): void
    {
        $dokument = $this->dokument();
        $kolumny = $this->kolumnyTekstoweWZakresie();

        $this->assertProgiSkanu($kolumny, $dokument);

        $usterki = [];

        foreach ($kolumny as [$tabela, $kolumna, $typ]) {
            if ($this->dokumentNazywa($dokument, $kolumna)) {
                continue;
            }

            $usterki[] = $tabela.'.'.$kolumna.' ('.$typ.')';
        }

        $this->assertSame([], $usterki, $this->wyjasnienie($usterki));
    }

    /**
     * KONTROLA SAMEGO WYKRYWACZA — czy on w ogóle umie powiedzieć „nie ma".
     *
     * Test wyżej jest zielony na dwa sposoby: gdy dokument naprawdę opisuje
     * wszystko ALBO gdy `dokumentNazywa()` odpowiada „tak" na cokolwiek.
     * Drugi przypadek jest cichy i nie do odróżnienia od pierwszego, dopóki
     * ktoś nie zada wykrywaczowi pytania o rzecz, której na pewno nie ma.
     *
     * Kotwicą po stronie „tak" jest `source_person` — kolumna, od której cały
     * ten plik się zaczął (D-156). Gdyby kiedyś zniknęła ze schematu, ta
     * asercja obleje i będzie to sygnał do podmiany kotwicy na inną kolumnę
     * tekstową opisaną w dokumencie, a nie do usunięcia kontroli.
     */
    public function test_wykrywacz_umie_odpowiedziec_takze_przeczaco(): void
    {
        $dokument = $this->dokument();

        $this->assertFalse(
            $this->dokumentNazywa($dokument, 'kolumna_ktorej_w_dokumencie_nie_ma'),
            'Wykrywacz znalazł w '.self::DOKUMENT.' kolumnę, której tam nie ma. '
            .'Dopóki odpowiada „tak" na wszystko, test wyżej jest zielony '
            .'niezależnie od tego, co dokument opisuje — czyli nie pilnuje niczego. '
            .'Sprawdź wzorzec w dokumentNazywa().',
        );

        $this->assertTrue(
            $this->dokumentNazywa($dokument, 'source_person'),
            'Wykrywacz nie znalazł w '.self::DOKUMENT.' kolumny „source_person", '
            .'którą ten dokument opisuje (sekcja „Pochodzenie przepisu"). Wzorzec '
            .'dopasowania przestał działać i test wyżej zgłosiłby teraz wszystkie '
            .'kolumny naraz jako nieopisane.',
        );

        $wZakresie = array_filter(
            $this->kolumnyTekstoweWZakresie(),
            static fn (array $k): bool => $k[0] === 'recipes' && $k[1] === 'source_person',
        );

        $this->assertCount(
            1,
            $wZakresie,
            'Odczyt schematu nie widzi kolumny „recipes.source_person", a ona jest '
            .'w bazie: `varchar(120) NULL`. Skoro nie widzi jej, to nie widzi też '
            .'kolumn, których nikt nie opisał — sprawdź TYPY_TEKSTOWE i zapytanie '
            .'do information_schema.',
        );
    }

    // ---------------------------------------------------------------
    // Schemat
    // ---------------------------------------------------------------

    /**
     * Kolumny tekstowe schematu `public`, bez tabel frameworka.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function kolumnyTekstoweWZakresie(): array
    {
        $wiersze = DB::select(
            'SELECT table_name, column_name, data_type
               FROM information_schema.columns
              WHERE table_schema = current_schema()
                AND data_type = ANY(?)
                AND table_name <> ALL(?)
              ORDER BY table_name, ordinal_position',
            ['{'.implode(',', self::TYPY_TEKSTOWE).'}', '{'.implode(',', self::TABELE_FRAMEWORKA).'}'],
        );

        return array_map(
            static fn (object $w): array => [
                (string) $w->table_name,
                (string) $w->column_name,
                (string) $w->data_type,
            ],
            $wiersze,
        );
    }

    // ---------------------------------------------------------------
    // Dokument
    // ---------------------------------------------------------------

    /**
     * Czy dokument NAZYWA tę kolumnę.
     *
     * Liczy się wzmianka w grawisach ZACZYNAJĄCA SIĘ od nazwy kolumny,
     * ewentualnie poprzedzonej nazwą tabeli: `source_person`,
     * `recipes.source_person`, a także `source_person varchar(120) NULL` —
     * bo dokument pisze tak w połowie miejsc i jest to jego styl, nie
     * niedbałość.
     *
     * Czego ten wzorzec CELOWO nie zalicza: nazwy wplecionej w środek
     * wyrażenia (`coalesce(summary, '')` w tabeli kolumn `*_search`) ani
     * gołego słowa w zdaniu. Jedno i drugie potrafi się trafić przypadkiem,
     * a wzmianka w grawisach zaczynająca się od nazwy kolumny jest świadomym
     * wskazaniem obiektu schematu. Granica „kolumna `status` z jednej tabeli
     * zalicza się za drugą" stoi w docbloku klasy.
     */
    private function dokumentNazywa(string $dokument, string $kolumna): bool
    {
        $wzorzec = '/`(?:[a-z_]+\.)?'.preg_quote($kolumna, '/').'\b[^`]*`/u';

        return preg_match($wzorzec, $dokument) === 1;
    }

    private function dokument(): string
    {
        $sciezka = base_path(self::DOKUMENT);

        $this->assertFileExists(
            $sciezka,
            'Nie ma '.self::DOKUMENT.' — a to jedyny dokument, wobec którego ten '
            .'test sprawdza schemat bazy.',
        );

        return (string) file_get_contents($sciezka);
    }

    // ---------------------------------------------------------------
    // Progi i komunikaty
    // ---------------------------------------------------------------

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $kolumny
     */
    private function assertProgiSkanu(array $kolumny, string $dokument): void
    {
        $this->assertGreaterThanOrEqual(
            self::MIN_DLUGOSC_DOKUMENTU,
            mb_strlen($dokument),
            'W '.self::DOKUMENT.' widać tylko '.mb_strlen($dokument).' znaków, '
            .'a spodziewamy się co najmniej '.self::MIN_DLUGOSC_DOKUMENTU.'. '
            .'Ten dokument nie chudnie o połowę, więc to usterka ODCZYTU, nie treści: '
            .'wobec krótkiego albo pustego łańcucha każda kolumna zgłosi się jako '
            .'nieopisana i komunikat niżej skłamie.',
        );

        $tabele = array_unique(array_map(static fn (array $k): string => $k[0], $kolumny));

        $this->assertGreaterThanOrEqual(
            self::MIN_TABEL_W_ZAKRESIE,
            count($tabele),
            'Odczyt schematu widzi tylko '.count($tabele).' tabel z kolumnami '
            .'tekstowymi, a spodziewamy się co najmniej '.self::MIN_TABEL_W_ZAKRESIE.'. '
            .'Sprawdź, czy migracje na bazie testowej wykonały się w całości — '
            .'skan pustej bazy nie zgłasza usterek, bo nie ma czego zgłaszać.',
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_KOLUMN_W_ZAKRESIE,
            count($kolumny),
            'Odczyt schematu widzi tylko '.count($kolumny).' kolumn tekstowych, '
            .'a spodziewamy się co najmniej '.self::MIN_KOLUMN_W_ZAKRESIE.'. Jeżeli '
            .'liczba spadła, bo nasze tabele trafiły do TABELE_FRAMEWORKA albo '
            .'z TYPY_TEKSTOWE zniknął któryś typ — to nie jest sprzątanie, tylko '
            .'wyłączanie tego testu.',
        );
    }

    /**
     * Komunikat wymienia TABELĘ, KOLUMNĘ I TYP, nie samo „czegoś brakuje".
     *
     * Usterkę naprawia człowiek dopisujący zdanie do `docs/DATABASE.md`,
     * nie ten, kto uruchomił testy. Bez nazwy kolumny pierwszym krokiem po
     * czerwonym wyniku byłoby to samo zapytanie do `information_schema`,
     * które ten test właśnie wykonał.
     *
     * @param  list<string>  $usterki
     */
    private function wyjasnienie(array $usterki): string
    {
        if ($usterki === []) {
            return '';
        }

        $linie = [
            'Te kolumny tekstowe są w bazie, a '.self::DOKUMENT.' nie nazywa ich ani razu:',
            '',
        ];

        foreach ($usterki as $usterka) {
            $linie[] = ' · '.$usterka;
            $linie[] = '';
        }

        $linie[] = 'Co zrobić: dopisz je do sekcji ich tabeli w '.self::DOKUMENT.'.';
        $linie[] = 'Nie „nazwa kolumny plus typ" — to widać w migracji. Napisz, CO';
        $linie[] = 'w tej kolumnie naprawdę leży i czym jest NULL.';
        $linie[] = '';
        $linie[] = 'Powód, dla którego pilnuje tego test: `recipes.source_person`';
        $linie[] = 'nazywa się „person", jest `varchar(120)` i nie ma w sobie człowieka —';
        $linie[] = 'leży tam nazwa grupy na Facebooku albo zdanie „od mamy". Dokument';
        $linie[] = 'milczał, więc ta sama nieprawda została zbudowana dwa razy: raz na';
        $linie[] = 'ekranie (D-153), raz w danych strukturalnych dla Google (D-156).';
        $linie[] = '';
        $linie[] = 'Czego NIE robić: dopisywać wyjątku do tego testu ani wpisywać tabeli';
        $linie[] = 'do TABELE_FRAMEWORKA. Obowiązek opisu stawia AGENTS.md („zmiana';
        $linie[] = 'schematu = migracja + test + docs/DATABASE.md + rollback"), a ten';
        $linie[] = 'test go tylko przypomina.';

        return implode("\n", $linie);
    }
}
