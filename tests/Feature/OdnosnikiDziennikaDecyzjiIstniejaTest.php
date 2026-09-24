<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Linie referencji `📄` w `docs/DECISIONS.md` wskazują na coś, co istnieje.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Prawie każdy z 181 wpisów dziennika kończy się linią `📄` — ścieżkami do
 * plików, nazwami klas testowych, odsyłaczami `D-NNN` do innych wpisów
 * i symbolami z kodu (klucz configu, klasa, metoda). To jedyna droga od
 * decyzji do kodu, który ją realizuje. Pliki są przenoszone i zmieniają
 * nazwy, klasy testowe też — a dziennik nie miał żadnego automatu, który by
 * to zauważył. Audyt z 12.09.2026 znalazł trzy takie martwe odnośniki wśród
 * kilkuset sprawdzonych (patrz ZNANE_MARTWE niżej).
 *
 * CZEGO TEN TEST PILNUJE
 *  - ścieżek plików i katalogów (z obsługą `*`/`…` jako globu), również
 *    zapisanych względem `docs/` — starsze wpisy pisały je tak, bo
 *    `DECISIONS.md` leży w tym katalogu;
 *  - `D-NNN` — czy taki nagłówek istnieje w tym samym dzienniku. Segmenty
 *    opisane jako „(system v3.1)" cytują OBCY dziennik
 *    (`docs/design/system-v3.1/`) i są z kontroli świadomie wyłączone —
 *    dokładnie tak, jak robi to `NumeryDecyzjiMajaWpisyTest::OBCA_NUMERACJA`
 *    dla odnośników w drugą stronę;
 *  - nazw klas testowych (z `tests/`), samodzielnych albo z `::metoda`;
 *  - najprostszych symboli z kodu: `Klasa::metoda()`, `funkcja()`, klucza
 *    configu w zapisie kropkowym (`kuking.moderation.model`, także z `*` na
 *    końcu), klasy CSS (`.badge-cichy`) i nazwy zmiennej środowiskowej.
 *
 * CZEGO ŚWIADOMIE NIE PILNUJE
 *  - `issue #N` — bez odpytywania GitHuba nie da się tego sprawdzić
 *    niezawodnie, więc numery zgłoszeń są tu tylko ROZPOZNAWANE i pomijane,
 *    nigdy nie liczą się do progu sprawdzonych referencji;
 *  - cytatów prawnych i audytowych bez odnośnika w cudzysłowie wstecznym
 *    („DSA art. 20", „audyt A6, bramka A6-07") — to nie są wskaźniki na
 *    zasób w repozytorium, tylko tekst;
 *  - nazw gałęzi git (`claude/...`) — nie są ścieżką w drzewie roboczym.
 *
 * PUŁAPKA 2 Z `docs/PULAPKI_TESTOW.md`
 * Test skanujący przechodzi też wtedy, gdy nic nie znalazł. Stąd
 * MIN_BLOKOW i MIN_SPRAWDZONYCH — progi dobrane wyraźnie poniżej stanu
 * z dnia napisania testu (112 bloków, ok. 780 sprawdzonych referencji), ale
 * daleko powyżej zera, żeby zła ścieżka do `docs/DECISIONS.md` albo zepsuty
 * wzorzec `📄` obalały test, zamiast cicho nic nie sprawdzać. Kontrola ujemna
 * (sztuczna referencja do nieistniejącego pliku, ręcznie wstawiona i cofnięta
 * przy pisaniu tego testu) opisana w komunikacie commita.
 */
final class OdnosnikiDziennikaDecyzjiIstniejaTest extends TestCase
{
    /**
     * Referencje, o których wiadomo, że są martwe, a których jeszcze nie
     * poprawiono — DZIŚ PUSTA I TAKA MA ZOSTAĆ.
     *
     * Audyt z 12 września 2026 znalazł trzy martwe referencje w 181 wpisach
     * i wszystkie trzy zostały poprawione w dzienniku, zamiast wylądować tutaj:
     *
     *   D-033 — wzorzec `…_recipe_ingredients_*` nie pasował do żadnego pliku,
     *           bo istniejące migracje kończą się NA tym słowie, a wzorzec
     *           wymagał podkreślnika PO nim. Wpisane są teraz obie migracje
     *           z nazwy.
     *   D-149 — `…/WZORCE_SAMOUZASADNIANIA` nigdy nie istniało pod żadną
     *           ścieżką i w tamtym katalogu nie ma pliku o tej treści. Ścieżka
     *           WYPADŁA zamiast zostać zastąpiona zgadywanym celem; katalog
     *           audytu stoi zresztą w nagłówku tego wpisu.
     *   D-169 — zły katalog: plik jest w `docs/design/`, nie `docs/brand/`.
     *
     * DLACZEGO TA LISTA MA ZOSTAĆ PUSTA. Lista wyjątków jest wygodna i dlatego
     * groźna: dopisanie do niej kosztuje jedną linijkę, a poprawienie referencji
     * — chwilę szukania. Po kilku takich wyborach test pilnuje już tylko tego,
     * że lista się zgadza sama ze sobą. Wpis wolno tu dodać wyłącznie wtedy, gdy
     * NIE DA SIĘ ustalić, czym zastąpić martwą referencję — i wtedy musi mieć
     * uzasadnienie w komentarzu obok, nie sam klucz.
     *
     * @var array<string, string>
     */
    private const ZNANE_MARTWE = [
    ];

    /** Poniżej tego bloków `📄` w dzienniku być nie powinno — dziś jest 112. */
    private const MIN_BLOKOW = 90;

    /** Poniżej tego sprawdzonych pojedynczych referencji być nie powinno — dziś ok. 780. */
    private const MIN_SPRAWDZONYCH = 500;

    public function test_skaner_czyta_wszystkie_pliki_testow_z_katalogu_feature(): void
    {
        $katalog = base_path('tests/Feature');
        $nazwy = scandir($katalog);
        $this->assertIsArray($nazwy);

        $oczekiwane = [];
        foreach ($nazwy as $nazwa) {
            $sciezka = $katalog.DIRECTORY_SEPARATOR.$nazwa;
            if (is_file($sciezka)) {
                $oczekiwane[] = $sciezka;
            }
        }

        $this->assertGreaterThan(500, count($oczekiwane), 'Katalog testów nie może być pustą kontrolą skanera.');

        $odczytane = $this->wczytajTresciPlikow($katalog);
        $brakujace = array_values(array_diff($oczekiwane, array_keys($odczytane)));

        $this->assertSame(
            0,
            count($brakujace),
            'Skaner pominął pliki testowe, choć istnieją na dysku. Pierwsze: '
            .implode(', ', array_slice($brakujace, 0, 3)),
        );
    }

    public function test_skaner_odroznia_istniejaca_klase_od_nieistniejacej(): void
    {
        $this->assertTrue($this->klasaTestowaIstnieje('DataWpisuGubiRokTylkoWTymRokuTest', null));
        $this->assertFalse($this->klasaTestowaIstnieje('KlasaKtorejNieMaWRepozytoriumTest', null));
    }

    public function test_referencje_z_dziennika_decyzji_wskazuja_na_istniejace_cele(): void
    {
        $sciezka = base_path('docs/DECISIONS.md');
        $this->assertFileExists($sciezka, 'Nie ma docs/DECISIONS.md.');

        $tresc = (string) file_get_contents($sciezka);
        $linie = explode("\n", $tresc);

        $naglowki = $this->numeryNaglowkow($linie);

        $this->assertGreaterThan(
            100,
            count($naglowki),
            'Nagłówków „## D-NNN" znaleziono podejrzanie mało ('.count($naglowki).'). '
            .'Zmienił się format nagłówka?',
        );

        $bloki = $this->blokiReferencji($linie);

        $this->assertGreaterThanOrEqual(
            self::MIN_BLOKOW,
            count($bloki),
            'Bloków referencji „📄" znaleziono tylko '.count($bloki).', '
            .'oczekiwano co najmniej '.self::MIN_BLOKOW.'. Zmienił się znacznik '
            .'albo sposób, w jaki kończy się blok referencji?',
        );

        $sprawdzone = 0;
        $martwe = [];

        foreach ($bloki as $blok) {
            $wpis = $blok['wpis'];
            $segmenty = $this->podzielNaSegmenty($blok['tekst']);

            foreach ($segmenty as $segment) {
                $tokeny = $this->tokenyZSegmentu($segment);

                foreach ($tokeny as $token) {
                    $wynik = $this->sklasyfikujISprawdz($token, $segment);

                    if ($wynik === null) {
                        // Nie jest to coś, co ten test rozpoznaje jako cel
                        // (issue #N, cytat prawny/audytowy, nazwa gałęzi git).
                        continue;
                    }

                    $sprawdzone++;

                    if (! $wynik) {
                        $klucz = $wpis.'::'.$token;

                        if (array_key_exists($klucz, self::ZNANE_MARTWE)) {
                            continue;
                        }

                        $martwe[] = $klucz.' (segment: "'.trim($segment).'")';
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_SPRAWDZONYCH,
            $sprawdzone,
            'Sprawdzono tylko '.$sprawdzone.' referencji, oczekiwano co najmniej '
            .self::MIN_SPRAWDZONYCH.'. Test, który nic nie sprawdza, niczego nie '
            .'pilnuje (docs/PULAPKI_TESTOW.md #2) — sprawdź parser segmentów.',
        );

        sort($martwe);

        $this->assertSame(
            [],
            $martwe,
            "Nowe martwe referencje w docs/DECISIONS.md (nie ma ich na liście ZNANE_MARTWE):\n"
            .implode("\n", $martwe)
            ."\n\ndocs/DECISIONS.md redaguje wyłącznie właściciel — zgłoś to, nie edytuj pliku.",
        );
    }

    // -----------------------------------------------------------------
    // Wyciąganie bloków „📄 ..." i podział na segmenty/tokeny
    // -----------------------------------------------------------------

    /** @param list<string> $linie
     * @return array<int, true> numer (int) => true */
    private function numeryNaglowkow(array $linie): array
    {
        $naglowki = [];

        foreach ($linie as $linia) {
            if (preg_match('/^## D-(\d+)\b/', $linia, $m) === 1) {
                $naglowki[(int) $m[1]] = true;
            }
        }

        return $naglowki;
    }

    /**
     * @param  list<string>  $linie
     * @return list<array{wpis: string, tekst: string}>
     */
    private function blokiReferencji(array $linie): array
    {
        $bloki = [];
        $n = count($linie);
        // Wartość początkowa dla bloku referencji stojącego przed pierwszym
        // nagłówkiem wpisu. Celowo nie jest to numer decyzji: każdy literał
        // w formacie numeru NumeryDecyzjiMajaWpisyTest policzy jako cytat,
        // a cytat bez wpisu w dzienniku to martwy odnośnik.
        $ostatniWpis = '(przed pierwszym wpisem)';
        $i = 0;

        while ($i < $n) {
            if (preg_match('/^## (D-\d+)\b/', $linie[$i], $m) === 1) {
                $ostatniWpis = $m[1];
            }

            if (str_starts_with($linie[$i], '📄')) {
                $blokLinie = [$linie[$i]];
                $j = $i + 1;

                while (
                    $j < $n
                    && trim($linie[$j]) !== ''
                    && ! str_starts_with($linie[$j], '#')
                    && ! str_starts_with($linie[$j], '📄')
                ) {
                    $blokLinie[] = $linie[$j];
                    $j++;
                }

                $bloki[] = ['wpis' => $ostatniWpis, 'tekst' => implode("\n", $blokLinie)];
                $i = $j;

                continue;
            }

            $i++;
        }

        return $bloki;
    }

    /**
     * Dzieli treść bloku na segmenty top-level po „·", nie licząc separatora
     * wewnątrz cudzysłowu wstecznego ani nawiasu.
     *
     * @return list<string>
     */
    private function podzielNaSegmenty(string $blok): array
    {
        $tresc = ltrim($blok);

        if (str_starts_with($tresc, '📄')) {
            $tresc = trim(mb_substr($tresc, mb_strlen('📄')));
        }

        $segmenty = [];
        $bufor = '';
        $glebokosc = 0;
        $wCudzyslowie = false;
        $len = mb_strlen($tresc);

        for ($k = 0; $k < $len; $k++) {
            $znak = mb_substr($tresc, $k, 1);

            if ($znak === '`') {
                $wCudzyslowie = ! $wCudzyslowie;
                $bufor .= $znak;

                continue;
            }

            if (! $wCudzyslowie) {
                if ($znak === '(' || $znak === '[') {
                    $glebokosc++;
                } elseif ($znak === ')' || $znak === ']') {
                    $glebokosc = max(0, $glebokosc - 1);
                }
            }

            if ($znak === '·' && $glebokosc === 0 && ! $wCudzyslowie) {
                $segmenty[] = $bufor;
                $bufor = '';
            } else {
                $bufor .= $znak;
            }
        }

        if (trim($bufor) !== '') {
            $segmenty[] = $bufor;
        }

        return $segmenty;
    }

    /**
     * Tokeny do sprawdzenia z jednego segmentu: wszystkie cudzysłowy
     * wsteczne, a przy ich braku bare „D-NNN" albo „issue #N" / „#N".
     *
     * @return list<string>
     */
    private function tokenyZSegmentu(string $segment): array
    {
        if (preg_match_all('/`([^`]+)`/', $segment, $m) > 0) {
            return $m[1];
        }

        if (preg_match('/\bD-(\d+)\b/', $segment, $m) === 1) {
            return ['D-'.$m[1]];
        }

        if (preg_match('/\bissues?\s*#\d+/i', $segment) === 1 || preg_match('/^#\d+$/', trim($segment)) === 1) {
            return ['issue'];
        }

        return [];
    }

    // -----------------------------------------------------------------
    // Klasyfikacja i weryfikacja pojedynczego tokenu
    // -----------------------------------------------------------------

    /**
     * @return bool|null true = istnieje, false = martwe, null = poza zakresem testu
     */
    private function sklasyfikujISprawdz(string $token, string $segment): ?bool
    {
        if ($token === 'issue') {
            return null; // rozpoznane, świadomie pomijane — patrz docblock klasy
        }

        // Odsyłacz do gałęzi git — nie jest ścieżką w drzewie roboczym.
        if (str_starts_with($token, 'claude/')) {
            return null;
        }

        // D-NNN — odsyłacz do innego wpisu w TYM dzienniku.
        if (preg_match('/^D-(\d+)$/', $token, $m) === 1) {
            if (str_contains($segment, 'system v3.1') || str_contains($segment, 'system-v3.1')) {
                return null; // cudza numeracja — patrz docblock klasy
            }

            return isset($this->numeryNaglowkow(explode("\n", (string) file_get_contents(base_path('docs/DECISIONS.md'))))[(int) $m[1]]);
        }

        if ($this->wygladaNaSciezke($token)) {
            return $this->sciezkaIstnieje($token);
        }

        if (preg_match('/^[A-Z][A-Za-z0-9_]*Test(?:::([a-zA-Z_][a-zA-Z0-9_]*))?$/', $token, $m) === 1) {
            $klasa = str_contains($token, '::') ? substr($token, 0, (int) strpos($token, '::')) : $token;
            $metoda = $m[1] ?? null;

            return $this->klasaTestowaIstnieje($klasa, $metoda);
        }

        return $this->symbolIstnieje($token);
    }

    private function wygladaNaSciezke(string $token): bool
    {
        if (str_contains($token, '/')) {
            return true;
        }

        if (preg_match('/\.(md|php|mjs|js|css|json|xml|ts|sh|ini|webmanifest)$/', $token) === 1) {
            return true;
        }

        // Dotpliki w korzeniu repo, np. „.env.example" — nie mają rozszerzenia
        // z listy wyżej, ale są ścieżką, nie klasą CSS. Rozróżnik: klasy CSS
        // w tym dzienniku zawsze mają myślnik (`.site-footer`, `.badge-cichy`),
        // dotpliki nigdy go nie mają i mają drugą kropkę (`.env.example`).
        return preg_match('/^\.[a-z]+(\.[a-z]+)+$/i', $token) === 1 && ! str_contains($token, '-');
    }

    private function sciezkaIstnieje(string $token): bool
    {
        if (str_contains($token, '*') || str_contains($token, '…')) {
            $wzorzec = str_replace('…', '*', $token);

            if (glob(base_path($wzorzec)) !== [] && glob(base_path($wzorzec)) !== false) {
                return true;
            }

            return glob(base_path('docs/'.$wzorzec)) !== [] && glob(base_path('docs/'.$wzorzec)) !== false;
        }

        if (str_ends_with($token, '/')) {
            return is_dir(base_path($token)) || is_dir(base_path('docs/'.$token));
        }

        return file_exists(base_path($token)) || file_exists(base_path('docs/'.$token));
    }

    private function klasaTestowaIstnieje(string $klasa, ?string $metoda): bool
    {
        static $tresciTestow = null;

        if ($tresciTestow === null) {
            $tresciTestow = $this->wczytajTresciPlikow(base_path('tests'));
        }

        foreach ($tresciTestow as $tresc) {
            if (preg_match('/\bclass\s+'.preg_quote($klasa, '/').'\b/', $tresc) === 1) {
                if ($metoda === null) {
                    return true;
                }

                return preg_match('/\bfunction\s+'.preg_quote($metoda, '/').'\s*\(/', $tresc) === 1;
            }
        }

        return false;
    }

    /**
     * Symbole z kodu: klasa/metoda, funkcja, klucz configu, klasa CSS,
     * zmienna środowiskowa albo nazwa (tabeli, migracji, usługi) opisana
     * gdzie indziej w repozytorium (np. `docs/DATABASE.md`, `.railway/railway.ts`).
     *
     * Świadomie szeroki, ostatni-deskowy krok: literalne wystąpienie tekstu
     * poza `docs/DECISIONS.md` jest tu traktowane jako dowód istnienia. To
     * jest słabsza gwarancja niż parsowanie AST, ale wystarcza do złapania
     * PRAWDZIWEJ sieroty (nazwa, której nie ma NIGDZIE indziej) i nie
     * wymaga dociągania parsera PHP/CSS do testu.
     */
    private function symbolIstnieje(string $token): bool
    {
        if (str_contains($token, '::')) {
            [$klasa, $reszta] = explode('::', $token, 2);
            $metoda = rtrim(explode('(', $reszta)[0]);

            static $tresciApp = null;

            if ($tresciApp === null) {
                $tresciApp = $this->wczytajTresciPlikow(base_path('app'));
            }

            foreach ($tresciApp as $tresc) {
                if (preg_match('/\bclass\s+'.preg_quote($klasa, '/').'\b/', $tresc) === 1) {
                    return preg_match('/\bfunction\s+'.preg_quote($metoda, '/').'\s*\(/', $tresc) === 1;
                }
            }

            return false;
        }

        if (preg_match('/^[a-zA-Z_][A-Za-z0-9_]*\(\)$/', $token) === 1) {
            $funkcja = rtrim($token, '()');

            return $this->wystepujeWApp('/\bfunction\s+'.preg_quote($funkcja, '/').'\s*\(/');
        }

        // Klucz configu w zapisie kropkowym, np. „kuking.moderation.model"
        // albo „limits.kontakt" (domyślny plik configu to `kuking`, bo
        // w dzienniku te klucze stoją zawsze obok `config/kuking.php`) —
        // ALBO odsyłacz „tabela.kolumna" do `docs/DATABASE.md`
        // (np. „users.weekly_digest_sent_at"). Kształt jest ten sam,
        // więc próbujemy obu, zanim uznamy token za martwy.
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_*]+)+$/', $token) === 1) {
            if ($this->kluczConfiguIstnieje($token)) {
                return true;
            }

            return $this->wystepujeWRepo($token, ['docs/DATABASE.md', 'database/migrations']);
        }

        if (str_starts_with($token, '.')) {
            $bezGwiazdki = rtrim($token, '*');

            return $this->wystepujeWRepo($bezGwiazdki, ['resources/css', 'resources/views']);
        }

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $token) === 1) {
            if ($this->wystepujeWApp('/\bclass\s+'.preg_quote($token, '/').'\b/')) {
                return true;
            }

            // Nazwa mogła zostać zmieniona — dziennik czasem SAM opisuje to
            // wprost („do 9 września 2026: `TurnstileNieJestPodrobiony`").
            // Wystąpienie choćby w komentarzu jest tu dowodem, że to nie
            // jest zmyślona nazwa, tylko udokumentowana historia.
            return $this->wystepujeWRepo($token, ['app', 'resources', 'docs']);
        }

        if (preg_match('/^[A-Z][A-Z0-9_]{3,}$/', $token) === 1) {
            return $this->wystepujeWRepo($token, ['.env.example', '.env', '.railway/railway.ts']);
        }

        // Ostatnia deska ratunku: bare słowo (tabela, migracja, nazwa
        // usługi w railway.ts, komentarz w nawiasie przy pliku) — sprawdź,
        // czy występuje GDZIEKOLWIEK poza samym dziennikiem.
        return $this->wystepujeWRepo($token, [
            'docs/DATABASE.md', 'database/migrations', '.railway/railway.ts',
            'config', 'app', 'resources', 'docker',
        ]);
    }

    private function kluczConfiguIstnieje(string $token): bool
    {
        $segmenty = explode('.', $token);
        $plik = $segmenty[0];

        // Klucze bez jawnego pliku configu (np. „limits.kontakt") są zawsze
        // cytowane obok `config/kuking.php` w dzienniku — to jest jego
        // przestrzeń domyślna, więc $segmenty zostaje bez zmian (cała ścieżka
        // JEST już ścieżką WEWNĄTRZ `config('kuking')`).
        if (in_array($plik, ['kuking', 'logging', 'mail', 'filesystems'], true)) {
            array_shift($segmenty);
        } else {
            $plik = 'kuking';
        }

        if (! file_exists(config_path($plik.'.php'))) {
            return false;
        }

        $wartosc = config($plik);

        foreach ($segmenty as $krok) {
            if ($krok === '*') {
                return is_array($wartosc) && $wartosc !== [];
            }

            $krok = rtrim($krok, '*');

            if ($krok === '') {
                return is_array($wartosc) && $wartosc !== [];
            }

            if (! is_array($wartosc) || ! array_key_exists($krok, $wartosc)) {
                // wildcard-prefiks, np. „limits.google_*" — szukaj klucza
                // zaczynającego się od tego przedrostka.
                if (str_ends_with($segmenty[array_key_last($segmenty)] ?? '', '*') && is_array($wartosc)) {
                    foreach (array_keys($wartosc) as $k) {
                        if (is_string($k) && str_starts_with($k, $krok)) {
                            return true;
                        }
                    }
                }

                return false;
            }

            $wartosc = $wartosc[$krok];
        }

        return true;
    }

    private function wystepujeWApp(string $wzorzecRegex): bool
    {
        static $tresciApp = null;

        if ($tresciApp === null) {
            $tresciApp = $this->wczytajTresciPlikow(base_path('app'));
        }

        foreach ($tresciApp as $tresc) {
            if (preg_match($wzorzecRegex, $tresc) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $miejsca */
    private function wystepujeWRepo(string $token, array $miejsca): bool
    {
        foreach ($miejsca as $miejsce) {
            $pelna = base_path($miejsce);

            if (is_file($pelna)) {
                $tresc = @file_get_contents($pelna);

                if ($tresc !== false && str_contains($tresc, $token)) {
                    return true;
                }

                continue;
            }

            if (is_dir($pelna)) {
                foreach ($this->wczytajTresciPlikow($pelna) as $sciezka => $tresc) {
                    if (str_contains($sciezka, 'DECISIONS.md')) {
                        continue;
                    }

                    if (str_contains($tresc, $token)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return array<string, string> ścieżka => treść */
    private function wczytajTresciPlikow(string $katalog): array
    {
        // Jeden przebieg strażnika sprawdza setki odnośników do tych samych
        // katalogów. Nie czytaj całego drzewa ponownie dla każdego tokenu.
        static $pamiec = [];

        if (array_key_exists($katalog, $pamiec)) {
            return $pamiec[$katalog];
        }

        $wynik = [];

        if (! is_dir($katalog)) {
            return $pamiec[$katalog] = $wynik;
        }

        // Na WSL/Windows DirectoryIterator potrafi oddać tylko fragment
        // dużego katalogu. W pomiarze tests/Feature widział 143 z 696
        // pozycji, a `scandir()` wszystkie 698 (wraz z `.` i `..`).
        $pozycje = scandir($katalog);
        if ($pozycje === false) {
            return $pamiec[$katalog] = $wynik;
        }

        foreach ($pozycje as $nazwa) {
            if ($nazwa === '.' || $nazwa === '..') {
                continue;
            }

            $sciezka = $katalog.DIRECTORY_SEPARATOR.$nazwa;

            if (is_dir($sciezka) && ! is_link($sciezka)) {
                foreach ($this->wczytajTresciPlikow($sciezka) as $plik => $tresc) {
                    $wynik[$plik] = $tresc;
                }

                continue;
            }

            if (is_file($sciezka)) {
                $tresc = @file_get_contents($sciezka);

                if ($tresc !== false) {
                    $wynik[$sciezka] = $tresc;
                }
            }
        }

        return $pamiec[$katalog] = $wynik;
    }
}
