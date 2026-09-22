<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Tabela stacku w `AGENTS.md` §3 nie obiecuje rzeczy, której w projekcie nie ma.
 *
 * SKĄD TO SIĘ WZIĘŁO — TRZECI RAZ TEJ SAMEJ KLASY BŁĘDU W JEDEN DZIEŃ
 * 10 września 2026 po raz trzeci okazało się, że **deklaracja wyglądająca na
 * rozstrzygnięcie zatrzymywała szukanie, choć była nieprawdziwa**. Dwa
 * pierwsze razy kod cytował numery decyzji, których nigdy nie zapisano —
 * historia obu i test, który tego pilnuje, stoją w
 * `tests/Feature/NumeryDecyzjiMajaWpisyTest.php`. (Numerów nie powtarzam tutaj
 * z nazwy: tamten test skanuje także `tests/`, więc martwy odnośnik przytoczony
 * w opowieści zgłosiłby się jako prawdziwa sierota.) Za trzecim razem to nie
 * był numer decyzji, tylko wiersz tej tabeli:
 *
 *     | Monitoring | Sentry |
 *
 * Sentry'ego w tym projekcie nie ma i nigdy nie było — zero trafień
 * w `composer.json`, brak `config/sentry.php`, brak integracji. Zmienna
 * `SENTRY_LARAVEL_DSN` jest przewleczona przez `.env.example`,
 * `.railway/railway.ts` i `ci.yml`, ale **nie czyta jej ani jedna linijka PHP**.
 *
 * CO TO KOSZTOWAŁO — ZMIERZONE, NIE HIPOTETYCZNE
 * Autor PR-a #253 zbudował całe zdanie „właściciel ma szansę dowiedzieć się
 * o awarii bez zaglądania" na założeniu, że `Log::error()` dojdzie do Sentry.
 * Nie dochodzi: Sentry'ego nie ma, a kanał `blad_webhook` nie jest częścią
 * domyślnego stosu (`LOG_STACK=single`) i wisi na `$exceptions->report()`
 * w `bootstrap/app.php`, czyli na wyjątkach, nie na dowolnym `Log::error()`.
 * Sprostowanie zajęło trzy pliki i cudzą pracę. Wiersz tabeli był tu dokładnie
 * tym, czym martwy numer decyzji: **wyglądał na odpowiedź, więc nikt nie szukał
 * dalej — a szukając, i tak by nic nie znalazł.**
 *
 * DLACZEGO TO NIE JEST TEST Z LISTĄ WYJĄTKÓW
 * Naturalny odruch — „sprawdź, czy każda nazwa z tabeli jest w `composer.json`"
 * — rozbija się o to, że większość tej tabeli SŁUSZNIE nie jest pakietem
 * Composera: PostgreSQL, Railway, Cloudflare, R2 i PWA to usługi i platformy,
 * a Tailwind jest pakietem npm. Test z listą dozwolonych wyjątków rósłby przy
 * każdej zmianie tabeli i sam stałby się kolejną deklaracją, która się
 * rozjedzie — czyli dokładnie tą chorobą, którą leczy.
 *
 * Zamiast listy wyjątków tabela dostała **trzecią kolumnę „Gdzie to sprawdzić"**
 * (D-104), a ten test ją czyta. Cztery dozwolone kształty wpisu — pełna legenda
 * stoi pod tabelą w `AGENTS.md` §3:
 *
 *     `composer.json`: nazwa pakietu   → klucz w `require` albo `require-dev`
 *     `package.json`: nazwa pakietu    → klucz w `dependencies` / `devDependencies`
 *                                        / `optionalDependencies`
 *     w repozytorium: ścieżka          → plik albo katalog istnieje
 *     usługa zewnętrzna                → w repozytorium nie ma czego sprawdzać
 *
 * Kształt piąty oblewa. Nowy wiersz nie wejdzie więc do tabeli bez odpowiedzi
 * na pytanie „a gdzie to jest" — i to jest cała mechanika: ciężar dowodu wraca
 * do tego, kto dopisuje wiersz, zamiast rosnąć w tym pliku.
 *
 * DRUGA TABELA, TA SAMA NIEPRAWDA
 * `README.md` §Stack nosi KOPIĘ tej tabeli i przez cały ten czas obiecywał
 * Sentry równie zgodnie. Poprawienie jednej kopii bez drugiej zostawiłoby tę
 * nieprawdę na stronie tytułowej repozytorium, więc drugi test w tym pliku
 * wymaga, żeby README powtarzał pierwsze dwie kolumny **co do znaku**.
 * Nie ma tam trzeciej kolumny i nie ma jej po co dublować: lokalizatory żyją
 * w jednym miejscu, a README ma się z nim zgadzać albo oblać.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE PILNUJE — TO GRANICA, NIE PRZEOCZENIE
 *  - **Czy „usługa zewnętrzna" jest prawdą.** Z repozytorium nie da się
 *    sprawdzić, czy ktoś ma konto na Cloudflare. Wpisanie „usługa zewnętrzna"
 *    przy pakiecie PHP (Sentry jest SDK, nie usługą) ten test przepuści.
 *    Różnica wobec stanu sprzed poprawki jest jednak zasadnicza: tabela
 *    kłamałaby wtedy JAWNIE, w jednym widocznym wierszu, zamiast po cichu
 *    przez zwykłą nazwę w kolumnie „Wybór". Żeby nie dało się uciszyć testu
 *    przepisaniem całej tabeli na tę wartość, stoi tu MIN_WIERSZY_SPRAWDZALNYCH.
 *  - **Wersji.** „Laravel 13" wobec `^13.0`, „Tailwind CSS 4" wobec `^4.0.0`.
 *    Rozjazd wersji jest realny, ale to inna usterka i inna naprawa; ten plik
 *    stoi przy pytaniu „czy ta rzecz w ogóle istnieje w projekcie".
 *  - **Rzeczy, których w tabeli NIE MA, a są w `composer.json`.** Tabela jest
 *    wyborem architektonicznym, nie spisem zależności — `mockery/mockery` nie
 *    ma czego w niej szukać.
 *  - **Innych tabel w `AGENTS.md`.** Skan bierze wyłącznie tabelę zaczynającą
 *    się wierszem-kotwicą w sekcji „## 3.", więc legenda kształtów tuż pod nią
 *    (własny nagłówek, własne kolumny) jest poza zakresem.
 */
class TabelaStackuMowiPrawdeTest extends TestCase
{
    /** Sekcja `AGENTS.md`, w której stoi tabela stacku. */
    private const WZORZEC_SEKCJI_AGENTS = '/^## 3\..*$/m';

    /** Sekcja `README.md` z kopią tabeli. */
    private const WZORZEC_SEKCJI_README = '/^## Stack\s*$/m';

    /** Wiersz nagłówka tabeli w `AGENTS.md` — kotwica skanu. */
    private const NAGLOWEK_AGENTS = '| Warstwa | Wybór | Gdzie to sprawdzić |';

    /** Wiersz nagłówka kopii w `README.md`. */
    private const NAGLOWEK_README = '| Warstwa | Wybór |';

    /** Rozdzielacz kilku lokalizatorów w jednej komórce. */
    private const ROZDZIELACZ_LOKALIZATOROW = '·';

    private const LOKALIZATOR_USLUGA = 'usługa zewnętrzna';

    /**
     * PROGI — bez nich ten test jest zielony na zawsze.
     *
     * Test skanujący pliki przechodzi także wtedy, gdy nie znajdzie NICZEGO:
     * zbiór usterek z pustego skanu jest pusty, więc każda asercja przechodzi
     * (`docs/PULAPKI_TESTOW.md`, pułapka 2 — w tym repozytorium jeden taki test
     * był zielony przy pięciu żywych usterkach). Zła ścieżka do `AGENTS.md`,
     * przesunięty numer sekcji albo literówka w wierszu-kotwicy ma tu OBLAĆ,
     * i to z komunikatem mówiącym, że zepsuł się TEST, a nie tabela.
     *
     * Progi są dobrane z zapasem, żeby nie ruszać ich przy zwykłej pracy —
     * a nie „tuż pod stanem na dziś". Liczby w nawiasach zmierzone 10.09.2026:
     *
     *  - MIN_WIERSZY = 10 (dziś 12). Tabela stacku się nie kurczy; ten próg
     *    pilnuje kotwicy i wzorca sekcji, nie tempa zmian.
     *  - MIN_PAKIETOW_COMPOSER = 12 (dziś 16: 8 w `require`, 8 w `require-dev`).
     *    Próg jest wyższy niż zawartość którejkolwiek z tych dwóch sekcji
     *    osobno, więc odczyt, który po zepsuciu czyta tylko jedną, oblewa.
     *  - MIN_PAKIETOW_NPM = 8 (dziś 11: 10 w `devDependencies`, 1
     *    w `optionalDependencies`).
     *  - MIN_LOKALIZATOROW_* (dziś 5 / 2 / 5). Gdyby parser komórki przestał
     *    rozpoznawać kształty, wszystkie wpadłyby do „nieznany kształt" i test
     *    obleje tam — te trzy progi są drugą linią obrony, na wypadek gdyby
     *    zaczął je po cichu POMIJAĆ zamiast zgłaszać.
     *  - MIN_WIERSZY_SPRAWDZALNYCH = 8 (dziś 10). To zamek na jedynym wytrychu,
     *    jaki ta konstrukcja ma: przepisaniu tabeli na „usługa zewnętrzna",
     *    żeby test zamilkł.
     */
    private const MIN_WIERSZY = 10;

    private const MIN_PAKIETOW_COMPOSER = 12;

    private const MIN_PAKIETOW_NPM = 8;

    private const MIN_LOKALIZATOROW_COMPOSER = 4;

    private const MIN_LOKALIZATOROW_NPM = 2;

    private const MIN_LOKALIZATOROW_REPO = 3;

    private const MIN_WIERSZY_SPRAWDZALNYCH = 8;

    /**
     * KSZTAŁT PIĄTY: NAZWA W KOLUMNIE „WYBÓR", KTÓREJ W TYM PROJEKCIE NIE MA.
     *
     * Test wyżej sprawdza TRZECIĄ kolumnę — czy lokalizator wskazuje rzecz,
     * która istnieje. Nie ma jak sprawdzić DRUGIEJ: wiersz
     *
     *     | Wyszukiwarka | PostgreSQL FTS + `pg_trgm` + `unaccent` | w repozytorium: …migracja rozszerzeń |
     *
     * przechodził tamten test bez mrugnięcia, bo plik migracji ISTNIEJE —
     * a `to_tsvector`, `tsvector`, `tsquery` i `ts_rank` nie padają w tym
     * repozytorium ANI RAZ (audyt 15.09.2026, §4.2). Wiersz ogłaszał więc
     * technikę, której tu nie ma, w wierszu BEZPOŚREDNIO POD tym, na którym
     * ta sama pomyłka kosztowała już projekt trzy pliki i wpis w dzienniku
     * decyzji („Monitoring | Sentry", D-104).
     *
     * DLACZEGO LISTA NIEOBECNYCH, A NIE SZUKANIE ŚLADU W KODZIE. Naturalny
     * odruch — „skoro wiersz mówi FTS, niech `grep` znajdzie `tsvector`" —
     * sprawdziłem i ODRZUCIŁEM: `grep -ri sentry` po `app/`, `config/`
     * i `resources/` daje DZIEWIĘĆ plików, wszystkie komentarze tłumaczące,
     * że Sentry'ego tu NIE MA. Test szukający śladu byłby więc zielony
     * dokładnie w tym przypadku, dla którego by powstał, a filtr odsiewający
     * komentarze przecieka na kontynuacjach bloków `/** … *\/`.
     * To jest pułapka 2 z `docs/PULAPKI_TESTOW.md` w czystej postaci.
     *
     * Lista nieobecnych jest dokładna i nie da się jej oszukać: albo słowo
     * stoi w komórce, albo nie stoi.
     *
     * JAK TA LISTA NIE ZGNIJE. Każdy wpis niesie pakiety, po których poznamy,
     * że rzecz JEDNAK weszła do projektu. Gdy któryś z nich pojawi się
     * w `composer.json` albo `package.json`, test oblewa z poleceniem
     * USUNIĘCIA wpisu — więc lista nie może przeżyć własnej nieprawdy
     * i nie zablokuje prawdziwego wdrożenia.
     *
     * Wpis bez pakietów (FTS — to funkcja PostgreSQL, nie zależność) takiego
     * zamka mieć nie może. Kto wdroży FTS, skasuje ten wpis ręcznie i to jest
     * świadomy koszt, nie przeoczenie.
     *
     * @var array<string, array{powod: string, pakiety: list<string>}>
     */
    private const NAZWY_KTORYCH_TU_NIE_MA = [
        'FTS' => [
            'powod' => 'D-004 świadomie odrzuciło stemming i FTS: dla polskiego '
                .'w Postgresie słownika nie ma, a `pg_trgm` + `unaccent` radzą sobie '
                .'z odmianą i brakiem diakrytyków lepiej. Wyszukiwarka pyta '
                .'`word_similarity()` z progiem 0,5 (D-046), nie `@@ to_tsquery()`.',
            'pakiety' => [],
        ],
        'Sentry' => [
            'powod' => 'D-041 i D-104: Sentry jest ZAMIAREM, nie stanem. Nie ma go '
                .'w `composer.json`, nie ma `config/sentry.php`, a `SENTRY_LARAVEL_DSN` '
                .'jest przewleczone przez `.env.example` i nieczytane przez ani jedną '
                .'linijkę PHP. Zamiar mieszka w `docs/ROADMAP.md` §0.',
            'pakiety' => ['sentry/sentry-laravel', 'sentry/sdk'],
        ],
        'Redis' => [
            'powod' => 'AGENTS.md §3 „Zakaz overengineeringu" wymienia Redisa '
                .'„na przyszłość" wprost. Kolejka i cache stoją na PostgreSQL.',
            'pakiety' => ['predis/predis', 'ext-redis'],
        ],
        'Scout' => [
            'powod' => 'D-004: wyszukiwarka stoi na PostgreSQL, bez Scouta '
                .'i bez osobnego silnika.',
            'pakiety' => ['laravel/scout'],
        ],
        'Meilisearch' => [
            'powod' => 'AGENTS.md §3: osobny silnik wyszukiwania bez zmierzonej '
                .'potrzeby jest zakazany (D-004).',
            'pakiety' => ['meilisearch/meilisearch-php'],
        ],
        'Typesense' => [
            'powod' => 'AGENTS.md §3: osobny silnik wyszukiwania bez zmierzonej '
                .'potrzeby jest zakazany (D-004).',
            'pakiety' => ['typesense/typesense-php'],
        ],
    ];

    public function test_kazdy_wiersz_tabeli_stacku_wskazuje_rzecz_ktora_istnieje(): void
    {
        $wiersze = $this->wierszeTabeli(
            $this->trescPliku('AGENTS.md'),
            self::WZORZEC_SEKCJI_AGENTS,
            self::NAGLOWEK_AGENTS,
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_WIERSZY,
            count($wiersze),
            'W tabeli stacku (AGENTS.md §3) widać tylko '.count($wiersze).' wierszy, '
            .'a spodziewamy się co najmniej '.self::MIN_WIERSZY.'. Tabela się nie '
            .'kurczy, więc to usterka TEGO TESTU, nie AGENTS.md: sprawdź wzorzec '
            .'sekcji „## 3." i wiersz-kotwicę „'.self::NAGLOWEK_AGENTS.'".',
        );

        $composer = $this->kluczeManifestu('composer.json', ['require', 'require-dev']);
        $npm = $this->kluczeManifestu(
            'package.json',
            ['dependencies', 'devDependencies', 'optionalDependencies'],
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_PAKIETOW_COMPOSER,
            count($composer),
            'W composer.json widać tylko '.count($composer).' pakietów, a spodziewamy '
            .'się co najmniej '.self::MIN_PAKIETOW_COMPOSER.'. Bez tego progu KAŻDY '
            .'lokalizator „composer.json" zgłosiłby się jako brak — sprawdź ścieżkę '
            .'do composer.json oraz klucze „require" i „require-dev".',
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_PAKIETOW_NPM,
            count($npm),
            'W package.json widać tylko '.count($npm).' pakietów, a spodziewamy się '
            .'co najmniej '.self::MIN_PAKIETOW_NPM.'. Sprawdź ścieżkę do package.json '
            .'oraz klucze „dependencies", „devDependencies" i „optionalDependencies".',
        );

        $usterki = [];
        $policzone = ['composer' => 0, 'npm' => 0, 'repozytorium' => 0];
        $wierszySprawdzalnych = 0;

        foreach ($wiersze as [$warstwa, $wybor, $gdzie]) {
            if ($gdzie === '') {
                $usterki[] = 'wiersz „'.$warstwa.' | '.$wybor.'" nie ma trzeciej '
                    .'kolumny. Każdy wiersz tej tabeli musi powiedzieć, GDZIE tej '
                    .'rzeczy szukać — na tym stoi cały ten test.';

                continue;
            }

            $sprawdzalny = false;

            foreach ($this->lokalizatory($gdzie) as $lokalizator) {
                $rodzaj = $this->rodzajLokalizatora($lokalizator);

                if ($rodzaj === null) {
                    $usterki[] = 'wiersz „'.$warstwa.'" ma lokalizator o nieznanym '
                        .'kształcie: „'.$lokalizator.'". Dozwolone kształty wypisuje '
                        .'AGENTS.md §3 pod tabelą: „composer.json: pakiet", '
                        .'„package.json: pakiet", „w repozytorium: ścieżka" albo '
                        .'„'.self::LOKALIZATOR_USLUGA.'".';

                    continue;
                }

                [$typ, $nazwy] = $rodzaj;

                if ($typ === 'usluga') {
                    continue;
                }

                $sprawdzalny = true;

                foreach ($nazwy as $nazwa) {
                    $policzone[$typ]++;

                    $usterka = match ($typ) {
                        'composer' => isset($composer[$nazwa]) ? null
                            : 'wiersz „'.$warstwa.' | '.$wybor.'" obiecuje pakiet PHP '
                            .'„'.$nazwa.'", a w composer.json go NIE MA (ani w „require", '
                            .'ani w „require-dev"). Albo pakiet trzeba dodać, albo wiersz '
                            .'mówi nieprawdę i to ON wymaga poprawki — tabela opisuje stan '
                            .'dzisiejszy, a zamiary mieszkają w docs/ROADMAP.md '
                            .'i docs/DECISIONS.md.',
                        'npm' => isset($npm[$nazwa]) ? null
                            : 'wiersz „'.$warstwa.' | '.$wybor.'" obiecuje pakiet npm '
                            .'„'.$nazwa.'", a w package.json go NIE MA (ani w „dependencies", '
                            .'ani w „devDependencies", ani w „optionalDependencies").',
                        default => file_exists(base_path($nazwa)) ? null
                            : 'wiersz „'.$warstwa.' | '.$wybor.'" wskazuje na „'.$nazwa.'" '
                            .'w repozytorium, a tej ścieżki nie ma. Najczęstsza przyczyna: '
                            .'plik przeniesiono i nikt nie poprawił tabeli.',
                    };

                    if ($usterka !== null) {
                        $usterki[] = $usterka;
                    }
                }
            }

            if ($sprawdzalny) {
                $wierszySprawdzalnych++;
            }
        }

        $this->assertSame([], $usterki, $this->wyjasnienie($usterki));

        foreach ([
            'composer' => [self::MIN_LOKALIZATOROW_COMPOSER, 'composer.json'],
            'npm' => [self::MIN_LOKALIZATOROW_NPM, 'package.json'],
            'repozytorium' => [self::MIN_LOKALIZATOROW_REPO, 'w repozytorium'],
        ] as $typ => [$prog, $etykieta]) {
            $this->assertGreaterThanOrEqual(
                $prog,
                $policzone[$typ],
                'Skan rozpoznał tylko '.$policzone[$typ].' lokalizatorów „'.$etykieta.'" '
                .'w tabeli, a spodziewamy się co najmniej '.$prog.'. Sprawdź parser '
                .'komórki „Gdzie to sprawdzić" — test, który nic nie rozpoznaje, '
                .'niczego nie pilnuje.',
            );
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_WIERSZY_SPRAWDZALNYCH,
            $wierszySprawdzalnych,
            'Tylko '.$wierszySprawdzalnych.' wierszy tabeli da się dziś sprawdzić '
            .'w repozytorium, a spodziewamy się co najmniej '
            .self::MIN_WIERSZY_SPRAWDZALNYCH.'. Jeżeli wiersze zostały przepisane na '
            .'„'.self::LOKALIZATOR_USLUGA.'", to nie jest sprzątanie — to wyłączanie '
            .'tego testu. Rzecz, która jest pakietem albo naszym kodem, ma mieć '
            .'lokalizator mówiący GDZIE, a nie „gdzieś u kogoś".',
        );
    }

    /**
     * Kopia tabeli w `README.md` powtarza pierwsze dwie kolumny co do znaku.
     *
     * Osobny test, nie druga asercja w teście wyżej — dwie awarie o różnych
     * przyczynach i różnych naprawach mają dawać dwa różne czerwone wyniki.
     * Tamten mówi „tabela obiecuje rzecz, której nie ma", ten „dwie kopie
     * tabeli mówią co innego".
     */
    public function test_kolumna_wybor_nie_oglasza_rzeczy_ktorych_w_projekcie_nie_ma(): void
    {
        $wiersze = $this->wierszeTabeli(
            $this->trescPliku('AGENTS.md'),
            self::WZORZEC_SEKCJI_AGENTS,
            self::NAGLOWEK_AGENTS,
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_WIERSZY,
            count($wiersze),
            'W tabeli stacku (AGENTS.md §3) widać tylko '.count($wiersze).' wierszy, '
            .'a spodziewamy się co najmniej '.self::MIN_WIERSZY.'. Skan pustej tabeli '
            .'nie znajdzie żadnej nazwy i ten test byłby zielony zawsze — to usterka '
            .'TEGO TESTU, nie AGENTS.md.',
        );

        $manifesty = array_merge(
            $this->kluczeManifestu('composer.json', ['require', 'require-dev']),
            $this->kluczeManifestu(
                'package.json',
                ['dependencies', 'devDependencies', 'optionalDependencies'],
            ),
        );

        $oglaszane = [];
        $juzWdrozone = [];

        foreach (self::NAZWY_KTORYCH_TU_NIE_MA as $nazwa => $wpis) {
            foreach ($wpis['pakiety'] as $pakiet) {
                if (isset($manifesty[$pakiet])) {
                    $juzWdrozone[] = '„'.$nazwa.'" jest już w projekcie — pakiet `'
                        .$pakiet.'` stoi w manifeście. USUŃ ten wpis z '
                        .'NAZWY_KTORYCH_TU_NIE_MA (i dopisz wiersz do tabeli stacku, '
                        .'jeśli go tam jeszcze nie ma). Ta lista pilnuje wyłącznie '
                        .'rzeczy NIEOBECNYCH i nie wolno jej blokować prawdziwego '
                        .'wdrożenia.';
                }
            }

            foreach ($wiersze as [$warstwa, $wybor, $gdzie]) {
                if (preg_match('/\b'.preg_quote($nazwa, '/').'\b/i', $wybor) !== 1) {
                    continue;
                }

                $oglaszane[] = 'wiersz „'.$warstwa.'" ogłasza w kolumnie „Wybór" '
                    .'nazwę „'.$nazwa.'", a tej rzeczy w projekcie NIE MA. '
                    .$wpis['powod'];
            }
        }

        $this->assertSame(
            [],
            $juzWdrozone,
            "Lista NAZWY_KTORYCH_TU_NIE_MA zdezaktualizowała się:\n\n · "
            .implode("\n\n · ", $juzWdrozone),
        );

        $this->assertSame(
            [],
            $oglaszane,
            "Tabela stacku w AGENTS.md §3 ogłasza rzecz, której w projekcie nie ma:\n\n · "
            .implode("\n\n · ", $oglaszane)
            ."\n\nCzego NIE robić: usuwać nazwy z listy NAZWY_KTORYCH_TU_NIE_MA, żeby "
            .'test zamilkł. Wpis wychodzi z niej DOPIERO razem z wdrożeniem rzeczy, '
            .'którą opisuje. Tabela nosi nagłówek „Stack” i odpowiada na pytanie „co '
            .'TU JEST”; zamiary mieszkają w docs/ROADMAP.md i docs/DECISIONS.md (D-104).',
        );
    }

    public function test_kopia_tabeli_w_readme_zgadza_sie_z_agents(): void
    {
        $wAgents = array_map(
            static fn (array $wiersz): array => [$wiersz[0], $wiersz[1]],
            $this->wierszeTabeli(
                $this->trescPliku('AGENTS.md'),
                self::WZORZEC_SEKCJI_AGENTS,
                self::NAGLOWEK_AGENTS,
            ),
        );

        $wReadme = array_map(
            static fn (array $wiersz): array => [$wiersz[0], $wiersz[1]],
            $this->wierszeTabeli(
                $this->trescPliku('README.md'),
                self::WZORZEC_SEKCJI_README,
                self::NAGLOWEK_README,
            ),
        );

        foreach ([['AGENTS.md §3', $wAgents], ['README.md §Stack', $wReadme]] as [$gdzie, $wiersze]) {
            $this->assertGreaterThanOrEqual(
                self::MIN_WIERSZY,
                count($wiersze),
                'W tabeli stacku w '.$gdzie.' widać tylko '.count($wiersze).' wierszy, '
                .'a spodziewamy się co najmniej '.self::MIN_WIERSZY.'. Porównanie dwóch '
                .'pustych list przechodzi zawsze, więc to usterka TEGO TESTU: sprawdź '
                .'wzorzec sekcji i wiersz-kotwicę dla tego pliku.',
            );
        }

        $this->assertSame(
            $wAgents,
            $wReadme,
            'Tabela stacku w README.md §Stack rozjechała się z AGENTS.md §3. '
            ."\n".'README nosi KOPIĘ pierwszych dwóch kolumn tamtej tabeli i ma ją '
            .'powtarzać co do znaku — źródłem prawdy jest AGENTS.md (patrz jego '
            .'nagłówek), więc poprawiaj README, a nie odwrotnie. Powód, dla którego '
            ."pilnuje tego test:\n"
            .'obie kopie mówiły przez miesiące to samo nieprawdziwe zdanie '
            .'(„Monitoring | Sentry", przy braku Sentry w projekcie), a poprawienie '
            .'jednej bez drugiej zostawiłoby tę nieprawdę na stronie tytułowej '
            .'repozytorium.',
        );
    }

    // ---------------------------------------------------------------
    // Skan tabeli
    // ---------------------------------------------------------------

    /**
     * Wiersze tabeli jako trójki [warstwa, wybór, gdzie sprawdzić].
     *
     * Trzeci element jest pustym łańcuchem, gdy tabela ma tylko dwie kolumny
     * (README) — brak trzeciej kolumny w tabeli, która ją mieć POWINNA, jest
     * zgłaszany jako usterka przez test wyżej, a nie milcząco pomijany tutaj.
     *
     * Kotwicą jest DOKŁADNY wiersz nagłówka wewnątrz właściwej sekcji, a nie
     * „pierwsza tabela w pliku": pod tabelą stacku stoi druga tabela (legenda
     * kształtów), a oba pliki mają tabele także w innych sekcjach.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function wierszeTabeli(string $tresc, string $wzorzecSekcji, string $naglowek): array
    {
        if (preg_match($wzorzecSekcji, $tresc, $trafienie, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $wiersze = [];
        $wTabeli = false;

        foreach (explode("\n", substr($tresc, (int) $trafienie[0][1])) as $linia) {
            $linia = trim($linia);

            if (! $wTabeli) {
                if ($linia === $naglowek) {
                    $wTabeli = true;
                }

                continue;
            }

            // Koniec tabeli: pierwsza linia, która nie jest jej wierszem.
            if (! str_starts_with($linia, '|')) {
                break;
            }

            $komorki = $this->komorki($linia);

            // Linia rozdzielająca „|---|---|".
            if ($komorki !== [] && trim($komorki[0], '- ') === '') {
                continue;
            }

            if (count($komorki) < 2) {
                continue;
            }

            $wiersze[] = [$komorki[0], $komorki[1], $komorki[2] ?? ''];
        }

        return $wiersze;
    }

    /**
     * Komórki jednego wiersza markdownowej tabeli.
     *
     * @return list<string>
     */
    private function komorki(string $linia): array
    {
        return array_map(trim(...), explode('|', trim(trim($linia), '|')));
    }

    /**
     * Pojedyncze lokalizatory z komórki „Gdzie to sprawdzić".
     *
     * @return list<string>
     */
    private function lokalizatory(string $komorka): array
    {
        $wynik = [];

        foreach (explode(self::ROZDZIELACZ_LOKALIZATOROW, $komorka) as $czesc) {
            $czesc = trim($czesc);

            if ($czesc !== '') {
                $wynik[] = $czesc;
            }
        }

        return $wynik;
    }

    /**
     * Rozpoznanie kształtu jednego lokalizatora.
     *
     * `null` znaczy „kształt spoza czterech dozwolonych" i jest zgłaszane jako
     * usterka — świadomie, bo cicha tolerancja dla nieznanego wpisu zamieniłaby
     * trzecią kolumnę z powrotem w ozdobę.
     *
     * @return array{0: 'composer'|'npm'|'repozytorium'|'usluga', 1: list<string>}|null
     */
    private function rodzajLokalizatora(string $lokalizator): ?array
    {
        $czysty = trim(str_replace('`', '', $lokalizator));

        if (mb_strtolower($czysty) === self::LOKALIZATOR_USLUGA) {
            return ['usluga', []];
        }

        $prefiksy = [
            'composer.json:' => 'composer',
            'package.json:' => 'npm',
            'w repozytorium:' => 'repozytorium',
        ];

        foreach ($prefiksy as $prefiks => $typ) {
            if (! str_starts_with($czysty, $prefiks)) {
                continue;
            }

            $nazwy = [];

            foreach (explode(',', substr($czysty, strlen($prefiks))) as $nazwa) {
                $nazwa = trim($nazwa);

                if ($nazwa !== '') {
                    $nazwy[] = $nazwa;
                }
            }

            return $nazwy === [] ? null : [$typ, $nazwy];
        }

        return null;
    }

    // ---------------------------------------------------------------
    // Manifesty i pliki
    // ---------------------------------------------------------------

    /**
     * Klucze wskazanych sekcji manifestu jako zbiór („laravel/framework" => true).
     *
     * `require-dev` liczy się na równi z `require`: tabela wymienia też
     * narzędzia pracy, a rozróżnianie tego tutaj kazałoby jej mówić, w której
     * sekcji manifestu coś stoi — czyli pilnować rzeczy, która nikogo nie
     * interesuje.
     *
     * @param  list<string>  $sekcje
     * @return array<string, true>
     */
    private function kluczeManifestu(string $plik, array $sekcje): array
    {
        /** @var array<string, mixed> $dane */
        $dane = json_decode($this->trescPliku($plik), true, 512, JSON_THROW_ON_ERROR);

        $klucze = [];

        foreach ($sekcje as $sekcja) {
            $zawartosc = $dane[$sekcja] ?? [];

            if (! is_array($zawartosc)) {
                continue;
            }

            foreach (array_keys($zawartosc) as $nazwa) {
                $klucze[(string) $nazwa] = true;
            }
        }

        return $klucze;
    }

    private function trescPliku(string $plik): string
    {
        $sciezka = base_path($plik);

        $this->assertFileExists(
            $sciezka,
            'Nie ma '.$plik.' — a to jeden z plików, wobec których ten test sprawdza '
            .'obietnice z tabeli stacku.',
        );

        return (string) file_get_contents($sciezka);
    }

    /**
     * Komunikat wymienia WIERSZ I BRAKUJĄCĄ RZECZ, nie samo „coś się nie zgadza".
     *
     * Usterkę naprawia człowiek czytający `AGENTS.md`, nie ten, kto uruchomił
     * testy. Bez nazwy wiersza i nazwy pakietu pierwszym krokiem po czerwonym
     * wyniku byłby ten sam `grep`, który ten test właśnie wykonał.
     *
     * @param  list<string>  $usterki
     */
    private function wyjasnienie(array $usterki): string
    {
        if ($usterki === []) {
            return '';
        }

        $linie = [
            'Tabela stacku w AGENTS.md §3 obiecuje rzeczy, których tam, gdzie wskazuje, nie ma:',
            '',
        ];

        foreach ($usterki as $usterka) {
            $linie[] = ' · '.$usterka;
            $linie[] = '';
        }

        $linie[] = 'Czego NIE robić: dopisywać wyjątku do tego testu ani przepisywać';
        $linie[] = 'wiersza na „'.self::LOKALIZATOR_USLUGA.'", żeby zamilkł. Ta tabela nosi';
        $linie[] = 'nagłówek „Stack" i jest czytana jako opis tego, CO JEST — dokładnie tak';
        $linie[] = 'przeczytano wiersz „Monitoring | Sentry", którego Sentry nigdy nie';
        $linie[] = 'istniał. Zamiary mieszkają w docs/ROADMAP.md i docs/DECISIONS.md.';

        return implode("\n", $linie);
    }
}
