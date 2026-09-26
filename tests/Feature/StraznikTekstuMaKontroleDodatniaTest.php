<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * NOWY STRAŻNIK CZYTAJĄCY ŹRÓDŁA MUSI MIEĆ DOWÓD, ŻE POTRAFI ZAPALIĆ.
 *
 * PO CO TO JEST
 * Test, który czyta plik źródłowy (CSS, Blade, workflow, skrypt) i asertuje na
 * jego TREŚCI, przestaje cokolwiek pilnować w chwili, gdy pilnowana rzecz zmieni
 * nazwę albo zostanie przykryta inną regułą. Wtedy nie świeci na czerwono —
 * świeci na zielono i nie sprawdza niczego. To ta sama klasa usterki co
 * „skan, który nie znajduje żadnego pliku, PRZECHODZI”, tylko przesunięta
 * o poziom wyżej: skan działa, ale mierzy pustkę.
 *
 * CO ZMIERZONO, ZANIM POWSTAŁ TEN TEST (audyt przed kolejką, 20.09.2026, 4c811cc7)
 *   • 599 plików testowych w `tests/`
 *   • 170 z nich czyta źródła (`file_get_contents` / `resource_path` / `base_path`)
 *   • 159 czyta źródła I asertuje na treści — to są strażnicy tekstu
 *   • objętych wpisem w `scripts/kontrole-negatywne-alfa08.py`: TRZY
 *     (od rozbicia skryptu mechanizm to katalog `scripts/kontrole_negatywne/`,
 *     po jednym pliku na kontrolę — patrz tamtejszy README.md)
 * Przyczyną nie było niedbalstwo stanowisk, tylko blokada `CI != "true"` w tym
 * skrypcie: nie dało się sprawdzić własnego wpisu przed commitem. Blokada została
 * zdjęta w tym samym commicie co ten test.
 *
 * DLACZEGO LISTA ZASTANYCH, A NIE `git diff origin/main...HEAD`
 * Zlecenie mówiło o plikach dodanych w diffie. Odstąpiłem świadomie, bo git nie
 * jest dostępny tam, gdzie ten test ma chodzić:
 *   • runtime floty powstaje przez `rsync --exclude '.git'`, więc w
 *     `/home/mateusz/flota/<stanowisko>-run` NIE MA repozytorium;
 *   • z jobów CI usunięto `fetch-depth: 0` (revert `abef3c94`), więc `origin/main`
 *     bywa niedostępny także tam.
 * Test oparty na `git diff` pomijałby się w obu tych miejscach — czyli byłby
 * dokładnie tym, czego ma pilnować: zielenią bez pomiaru. Lista
 * `tests/straznicy-tekstu-zastane.txt` jest migawką tego samego zbioru z chwili
 * 4c811cc7 i działa bez repozytorium.
 *
 * CZEGO TEN STRAŻNIK NIE ZROBI — CZYTAJ PRZED DOPISANIEM MU ZADAŃ
 *   1. NIE OCENI JAKOŚCI kontroli dodatniej. Sprawdza, że nazwa testu stoi
 *      w pliku kontroli, nie że mutacja jest sensowna. Słaba mutacja przejdzie.
 *   2. NIE OBEJMIE 159 zastanych strażników. Świadomie: inaczej pierwszy przebieg
 *      jest czerwony na całym repozytorium i zostanie wyłączony w tydzień.
 *   3. NIE WYKRYJE strażnika, który zmienia kształt razem z kodem — jak
 *      `scripts/minutnik-regresja.mjs`, wycinający `app.js` przez `indexOf`
 *      i tracący importy po refaktorze (znalezisko Z5 z 20.09.2026). To inna
 *      klasa usterki i inny mechanizm.
 *   4. NIE PILNUJE strażników spoza `tests/` — skanów w `scripts/*.mjs`,
 *      sond i mierników portu marki. Tam kontrola dodatnia jest równie potrzebna,
 *      ale mechanizm kontroli uruchamia `php artisan test`, a nie te skrypty.
 *   5. NIE ZAMKNIE furtki `@bez-kontroli-dodatniej`. Wymaga powodu słownie,
 *      i tyle. Powody czyta człowiek w audycie przed kolejką.
 *   6. NIE ZAUWAŻY dopisania pliku do listy zastanych. To widać w diffie
 *      i jest zadaniem audytu, nie testu.
 */
class StraznikTekstuMaKontroleDodatniaTest extends TestCase
{
    /** Ślady czytania źródeł aplikacji. */
    private const CZYTA_ZRODLA = [
        'file_get_contents(',
        'resource_path(',
        'base_path(',
    ];

    /** Ślady asercji na TREŚCI odczytanego źródła, a nie na zachowaniu aplikacji. */
    private const ASERTUJE_TEKST = [
        'preg_match',
        'assertStringContainsString',
        'assertMatchesRegularExpression',
        'assertDoesNotMatchRegularExpression',
        'assertStringNotContainsString',
    ];

    private const LISTA_ZASTANYCH = 'tests/straznicy-tekstu-zastane.txt';

    /**
     * Katalog kontroli: po jednym pliku `*.py` na kontrolę. Pliki z `_` na
     * początku nazwy (narzędzia, `__init__.py`) nie są kontrolami — tak samo
     * pomija je loader w Pythonie, więc nazwa testu wpisana tam nie liczy się
     * jako pokrycie, bo nic by jej nie uruchomiło.
     */
    private const MECHANIZM = 'scripts/kontrole_negatywne';

    /** Punkt wejścia — ta sama komenda w CI i w podpowiedzi niżej. */
    private const URUCHOMIENIE = 'scripts/kontrole-negatywne-alfa08.py';

    /**
     * Powód przy odstępstwie ma być zdaniem, nie znakiem. Dziesięć znaków to
     * próg odróżniający „bo tak" od informacji, którą da się później ocenić.
     */
    private const MINIMALNY_POWOD = 10;

    public function test_nowy_straznik_tekstu_ma_kontrole_dodatnia_albo_uzasadnione_odstepstwo(): void
    {
        $mechanizm = $this->trescMechanizmu();
        $zastani = $this->zastani();

        $braki = [];

        foreach ($this->plikiTestowe() as $sciezka) {
            if (isset($zastani[$sciezka])) {
                continue;
            }

            $tresc = $this->plik($sciezka);

            if (! $this->jestStraznikiemTekstu($tresc)) {
                continue;
            }

            if ($this->maWpisWMechanizmie($sciezka, $tresc, $mechanizm)) {
                continue;
            }

            $powod = $this->powodOdstepstwa($tresc);

            if ($powod === null) {
                $braki[] = $sciezka.' — brak kontroli w '.self::MECHANIZM.'/'
                    .' i brak znacznika @bez-kontroli-dodatniej';

                continue;
            }

            if (mb_strlen($powod) < self::MINIMALNY_POWOD) {
                $braki[] = $sciezka.' — znacznik @bez-kontroli-dodatniej bez powodu '
                    .'(albo powód krótszy niż '.self::MINIMALNY_POWOD.' znaków): '.var_export($powod, true);
            }
        }

        sort($braki);

        $this->assertSame([], $braki, implode("\n", [
            'Nowy test czyta źródła i asertuje na ich treści, więc może kiedyś zzielenieć bez pomiaru.',
            'Zrób jedno z dwóch:',
            '  1. Dodaj plik '.self::MECHANIZM.'/<nazwa>.py według wzoru z README.md w tym katalogu —',
            '     mutacja psująca to, czego pilnuje, plus nazwa testu, który ma wtedy oblać.',
            '     Wspólnych plików nie ruszasz. Sprawdź to u siebie, zanim zacommitujesz:',
            '     python3 '.self::URUCHOMIENIE.' --lista',
            '     DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_<stanowisko> \\',
            '     KUKING_KONTROLE_LOKALNIE=1 python3 '.self::URUCHOMIENIE,
            '  2. Albo napisz w docbloku klasy: @bez-kontroli-dodatniej <powód w jednym zdaniu>.',
            'Nie dopisuj pliku do '.self::LISTA_ZASTANYCH.' — ta lista jest zamknięta na 4c811cc7.',
            'Bez pokrycia:',
        ]));
    }

    /**
     * KONTROLA Z DRUGIEJ STRONY. Klasyfikator ma nie łapać wszystkiego jak leci —
     * inaczej strażnik byłby zawsze czerwony i zostałby wyłączony.
     * Plik testowy, który nie czyta źródeł, nie jest strażnikiem tekstu.
     */
    public function test_plik_bez_czytania_zrodel_nie_jest_straznikiem_tekstu(): void
    {
        $kontrolny = 'tests/Feature/PlikKontrolnyBezCzytaniaZrodelTest.php';

        $this->assertFileExists(base_path($kontrolny), 'Zniknął plik kontrolny dla klasyfikatora.');

        $tresc = $this->plik($kontrolny);

        $this->assertFalse(
            $this->jestStraznikiemTekstu($tresc),
            'Klasyfikator uznał za strażnika tekstu plik, który nie czyta żadnego źródła. '
            .'Tak szeroki klasyfikator zapala się na wszystkim i kończy wyłączeniem strażnika.',
        );

        // I z drugiej strony: plik, który ŹRÓDŁA czyta, ma zostać rozpoznany.
        $this->assertTrue(
            $this->jestStraznikiemTekstu($this->plik('tests/Feature/PlikKontrolnyZOdstepstwemTest.php')),
            'Klasyfikator nie rozpoznał pliku, który czyta źródło i asertuje na jego treści.',
        );
    }

    /** Sam znacznik bez uzasadnienia nie jest odstępstwem — ma nie wystarczać. */
    public function test_znacznik_bez_powodu_nie_jest_odstepstwem(): void
    {
        $this->assertNull($this->powodOdstepstwa(" * @bez-kontroli-dodatniej\n"));
        $this->assertNull($this->powodOdstepstwa(" * brak znacznika\n"));
        $this->assertSame('bo tak', $this->powodOdstepstwa(" * @bez-kontroli-dodatniej bo tak\n"));
        $this->assertLessThan(
            self::MINIMALNY_POWOD,
            mb_strlen((string) $this->powodOdstepstwa(" * @bez-kontroli-dodatniej bo tak\n")),
            'Powód „bo tak" ma być za krótki, żeby przejść — inaczej furtka nie ma progu.',
        );

        // Wzmianka o znaczniku w zdaniu NIE jest odstępstwem. To jest ta pułapka,
        // która przepuściła strażnika bez pokrycia przy pierwszym uruchomieniu.
        $this->assertNull(
            $this->powodOdstepstwa(" * Ten strażnik nie zamknie furtki `@bez-kontroli-dodatniej` i nigdy nie zamierza.\n"),
            'Wzmianka o znaczniku w prozie zwolniła plik z pokrycia — kotwica początku wiersza nie działa.',
        );
    }

    /** Lista zastanych ma opisywać stan, a nie rosnąć niepostrzeżenie. */
    public function test_lista_zastanych_wskazuje_istniejace_pliki(): void
    {
        $znikniete = [];

        foreach (array_keys($this->zastani()) as $sciezka) {
            if (! is_file(base_path($sciezka))) {
                $znikniete[] = $sciezka;
            }
        }

        $this->assertSame([], $znikniete, implode("\n", [
            'W '.self::LISTA_ZASTANYCH.' są ścieżki, których już nie ma.',
            'Usuń te wiersze — lista zastanych ma się tylko skracać.',
            'Nieistniejące:',
        ]));
    }

    /**
     * Dwie kontrole o tej samej nazwie dają raport CI, z którego nie da się
     * odczytać, która padła — a po rozbiciu na pliki dwa równoległe PR-y
     * mogą dodać taką parę bez konfliktu w gicie. Loader w Pythonie też
     * odmawia, ale dopiero w kroku CI po migracjach; tu wychodzi to w testach.
     */
    public function test_w_katalogu_kontroli_nie_ma_dwoch_kontroli_o_tej_samej_nazwie(): void
    {
        $gdzie = [];
        $wszystkie = 0;

        foreach ($this->plikiKontroli() as $sciezka) {
            // Docstring może opisywać `Kontrola(...)` słowami — to nie jest wpis.
            $tresc = $this->bezDocstringow($this->plik($sciezka));
            $nazwy = $this->nazwyKontroli($tresc);

            // Kontrola z nazwą niebędącą napisem umknęłaby tej regule w ciszy.
            $this->assertSame(
                substr_count($tresc, 'Kontrola('),
                count($nazwy),
                $sciezka.': każda Kontrola(...) ma mieć nazwę wpisaną jako napis w pierwszym argumencie.',
            );

            foreach ($nazwy as $nazwa) {
                $gdzie[$nazwa][] = $sciezka;
                $wszystkie++;
            }
        }

        // Kontrola dodatnia: parser, który nie znajduje żadnej nazwy, nie znajdzie też dubla.
        $this->assertGreaterThan(0, $wszystkie, 'Nie odczytano żadnej nazwy kontroli — reguła mierzyłaby pustkę.');

        $duble = array_filter($gdzie, fn (array $pliki): bool => count($pliki) > 1);

        $this->assertSame([], $duble, 'Dwie kontrole o tej samej nazwie. Zmień nazwę jednej z nich.');
    }

    /** KONTROLA Z DRUGIEJ STRONY dla reguły wyżej: dubel ma zostać odczytany. */
    public function test_parser_nazw_kontroli_widzi_obie_formy_napisu(): void
    {
        $tresc = implode("\n", [
            'KONTROLE = [',
            '    Kontrola("Pierwsza", PLIK, TEST, mutacja),',
            "    Kontrola(\n        'Druga \\'z\\' cudzysłowem', PLIK, TEST, mutacja),",
            '    Kontrola("Pierwsza", PLIK, TEST, inna),',
            ']',
        ]);

        $this->assertSame(['Pierwsza', "Druga \\'z\\' cudzysłowem", 'Pierwsza'], $this->nazwyKontroli($tresc));
    }

    /**
     * Punkt wejścia jest cienki (podział z 25.09.2026, PR #1478). Gałąź sprzed
     * podziału, scalona z „weź moje" w konflikcie, przywróciłaby tu stary
     * monolit: CI dalej by świecił na zielono, ale puszczał tylko wpisy
     * z monolitu, a cały katalog — w ciszy nie. Samokontrola w samym punkcie
     * wejścia zniknęłaby razem z nim, więc ta reguła stoi w PHPUnit.
     */
    public function test_punkt_wejscia_kontroli_nie_ma_wpisow_w_starym_ukladzie(): void
    {
        $tresc = $this->plik(self::URUCHOMIENIE);

        preg_match_all('/^(checks\s*=|run_test\(|def |[A-Z][A-Z0-9_]*\s*=)/m', $tresc, $trafienia);

        $this->assertSame([], $trafienia[0], implode("\n", [
            self::URUCHOMIENIE.' ma wpisy kontroli w starym układzie (stałe, `checks`, `run_test`, funkcje mutacji).',
            'Przenieś każdy wpis do nowego pliku '.self::MECHANIZM.'/kNN_<obszar>.py według README.md,',
            'a punkt wejścia weź z main: git checkout origin/main -- '.self::URUCHOMIENIE,
            'Kroki: docs/flota/PRZENIESIENIE_PO_PODZIALE.md',
        ]));

        $this->assertStringContainsString('_narzedzia.uruchom()', $tresc, 'Punkt wejścia nie woła katalogu kontroli.');
    }

    // ---------------------------------------------------------------- pomocnicze

    /** @return list<string> pliki kontroli w kolejności, w jakiej uruchamia je Python */
    private function plikiKontroli(): array
    {
        $pliki = [];

        foreach (glob(base_path(self::MECHANIZM.'/*.py')) ?: [] as $pelna) {
            if (str_starts_with(basename($pelna), '_')) {
                continue;
            }

            $pliki[] = self::MECHANIZM.'/'.basename($pelna);
        }

        sort($pliki);

        $this->assertNotEmpty($pliki, 'Brak plików kontroli w '.self::MECHANIZM.' — strażnik mierzyłby pustkę.');

        return $pliki;
    }

    private function trescMechanizmu(): string
    {
        return implode("\n", array_map(fn (string $sciezka): string => $this->plik($sciezka), $this->plikiKontroli()));
    }

    /**
     * Nazwa kontroli to pierwszy argument `Kontrola(...)`, zapisany jako
     * napis w cudzysłowie pojedynczym albo podwójnym.
     *
     * @return list<string>
     */
    private function nazwyKontroli(string $tresc): array
    {
        preg_match_all('/Kontrola\(\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')/', $tresc, $trafienia, PREG_SET_ORDER);

        return array_map(fn (array $t): string => ($t[1] ?? '') !== '' ? $t[1] : ($t[2] ?? ''), $trafienia);
    }

    /** Zdejmuje docstringi Pythona (napisy w `"""`), zostawia kod. */
    private function bezDocstringow(string $tresc): string
    {
        return preg_replace('/"""[\s\S]*?"""/', '', $tresc) ?? $tresc;
    }

    private function jestStraznikiemTekstu(string $tresc): bool
    {
        $czyta = false;

        foreach (self::CZYTA_ZRODLA as $slad) {
            if (str_contains($tresc, $slad)) {
                $czyta = true;

                break;
            }
        }

        if (! $czyta) {
            return false;
        }

        foreach (self::ASERTUJE_TEKST as $slad) {
            if (str_contains($tresc, $slad)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wpis w mechanizmie może wskazywać klasę albo pojedynczą metodę testową —
     * `Kontrola(...)` dopuszcza oba (patrz „Licznik w widocznym menu konta").
     */
    private function maWpisWMechanizmie(string $sciezka, string $tresc, string $mechanizm): bool
    {
        if ($this->nazwaStoiJakoTest(basename($sciezka, '.php'), $mechanizm)) {
            return true;
        }

        preg_match_all('/function\s+(test_\w+)\s*\(/', $tresc, $metody);

        foreach ($metody[1] ?? [] as $metoda) {
            if ($this->nazwaStoiJakoTest($metoda, $mechanizm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nazwa ma stać jako SAMODZIELNY napis w cudzysłowie — czyli jako nazwa testu
     * do uruchomienia. Zwykłe `str_contains` uznawało za pokrycie także sytuację,
     * w której plik jest w mechanizmie tylko CELEM MUTACJI, bo jego ścieżka
     * (`"tests/Feature/CosTam.php"`) zawiera nazwę klasy jako podciąg. Wtedy plik
     * kontrolny furtki „miał pokrycie", którego nie miał, i kontrola dodatnia 2
     * nie zapalała. Zmierzone przy drugim uruchomieniu kontroli, 20.09.2026.
     */
    private function nazwaStoiJakoTest(string $nazwa, string $mechanizm): bool
    {
        return str_contains($mechanizm, '"'.$nazwa.'"')
            || str_contains($mechanizm, "'".$nazwa."'");
    }

    private function powodOdstepstwa(string $tresc): ?string
    {
        // ZNACZNIK MUSI STAĆ NA POCZĄTKU WIERSZA DOCBLOKA, po samej gwiazdce.
        // Bez tej kotwicy liczyła się każda WZMIANKA o znaczniku w prozie —
        // a ten plik opisuje furtkę w swoim własnym docbloku, więc zwalniał
        // sam siebie i przechodził bez pokrycia. Złapane przez kontrolę
        // dodatnią 1 przy pierwszym uruchomieniu, 20.09.2026: mutacja zabrała
        // wpis z `checks`, a strażnik mimo to świecił na zielono.
        if (preg_match('/^[ \t]*\*?[ \t]*@bez-kontroli-dodatniej[ \t]*(.*)$/m', $tresc, $trafienie) !== 1) {
            return null;
        }

        // Ucinamy ogon komentarza, żeby „*/" nie liczyło się jako uzasadnienie.
        $powod = trim(preg_replace('#\*/.*$#', '', $trafienie[1]) ?? '');
        $powod = trim($powod, " \t*");

        return $powod === '' ? null : $powod;
    }

    /** @return array<string, true> */
    private function zastani(): array
    {
        $lista = [];

        foreach (explode("\n", $this->plik(self::LISTA_ZASTANYCH)) as $wiersz) {
            $wiersz = trim($wiersz);

            if ($wiersz === '' || str_starts_with($wiersz, '#')) {
                continue;
            }

            $lista[$wiersz] = true;
        }

        return $lista;
    }

    /** @return list<string> */
    private function plikiTestowe(): array
    {
        $znalezione = [];
        $katalog = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($katalog as $plik) {
            /** @var \SplFileInfo $plik */
            if (! $plik->isFile() || ! str_ends_with($plik->getFilename(), 'Test.php')) {
                continue;
            }

            $znalezione[] = str_replace('\\', '/', substr($plik->getPathname(), strlen(base_path()) + 1));
        }

        sort($znalezione);

        return $znalezione;
    }

    private function plik(string $sciezka): string
    {
        $pelna = base_path($sciezka);

        $this->assertFileExists($pelna, "Brakuje pliku wymaganego przez strażnika: {$sciezka}");

        return (string) file_get_contents($pelna);
    }
}
