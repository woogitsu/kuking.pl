<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class PortMarkiMaWlasnaBramkeCiTest extends TestCase
{
    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    }

    private function job(string $name): string
    {
        $matched = preg_match('/^  '.preg_quote($name, '/').':\R(.*?)(?=^  [a-z_]+:|\z)/ms', $this->workflow(), $matches);
        $this->assertSame(1, $matched, 'Brak sprawdzanego joba CI: '.$name);

        return (string) preg_replace('/^\s*#.*$/m', '', $matches[1]);
    }

    public function test_obie_grupy_sa_obowiazkowe_a_kreator_ma_przygotowana_baze(): void
    {
        $port = 'run: node scripts/port-projektu.mjs';
        $kroki = 'run: node scripts/kroki-kreatora.mjs';
        $this->assertSame(2, substr_count($this->workflow(), $port));
        $this->assertSame(1, substr_count($this->workflow(), $kroki));
        foreach (['port_marki' => 'baza', 'port_funkcje' => 'rozszerzenia'] as $name => $group) {
            $job = $this->job($name);
            $this->assertStringContainsString($port, $job);
            $this->assertStringContainsString('run: node --test scripts/port-grupy.test.mjs', $job);
            $this->assertStringContainsString('PORT_GRUPA: '.$group, $job);
            $this->assertStringContainsString('timeout-minutes: '.($name === 'port_funkcje' ? 35 : 25), $job);
            $this->assertStringContainsString("if: needs.zakres.outputs.kod == 'true'", $job);
            $this->assertStringNotContainsString('continue-on-error:', $job);
            $this->assertStringContainsString('job.services.postgres.ports[5432]', $job);
            $this->assertStringContainsString('storage/port-projektu', $job);
            $this->assertStringContainsString('uses: actions/checkout@v7', $job);
        }
        $job = $this->job('port_funkcje');
        $this->assertStringContainsString($kroki, $job);
        $this->assertLessThan(strpos($job, $kroki), strpos($job, $port), 'Kreator wymaga bazy przygotowanej przez port.');
        $this->assertStringContainsString('storage/kroki-kreatora', $job);
        $this->assertStringNotContainsString($kroki, $this->job('port_marki'));
        $other = $this->job('dostepnosc');
        foreach (['dostepnosc', 'wydajnosc', 'fokus-karty-dania', 'kafel-dodawania', 'service-worker-aktualizacja'] as $script) {
            $this->assertStringContainsString('run: node scripts/'.$script.'.mjs', $other);
        }
    }

    /**
     * STRAŻNIK MARTWYCH REGUŁ CSS MA KTO URUCHOMIĆ (D-223).
     *
     * `scripts/kaskada-martwe-reguly.mjs` powstał 20.09.2026 i przez dobę nie
     * wołał go NIKT — ani `ci.yml`, ani `scripts/check.sh`. Strażnik, którego
     * nic nie uruchamia, jest dokumentacją zamiaru, a nie bramką; ten sam
     * wzór wyszedł tego dnia pięć razy przy skryptach testujących JavaScript.
     *
     * Ten test pilnuje trzech rzeczy naraz, bo każda z osobna daje zieleń bez
     * pomiaru:
     *
     * 1. WYWOŁANIE ISTNIEJE i stoi w jobie `dostepnosc` — jedynym, który ma
     *    równocześnie Chromium, PHP, zbudowany arkusz i ZASIANĄ bazę.
     * 2. STOI PO przeglądarce i PO `migrate:fresh --seed`. Przed nimi padłby
     *    na braku Chromium albo na pustej bazie — czyli na przyrządzie,
     *    a nie na CSS-ie, i pierwsza czerwień nauczyłaby czytelnika, że ta
     *    bramka „zawsze się sypie".
     * 3. IDZIE PRZEZ `scripts/kaskada-kontrola-polecenie.sh`, nie przez gołe
     *    `node …mjs` z własnymi flagami w `ci.yml`. Zawężenie `--tylko` ma
     *    JEDNO miejsce, wspólne z kontrolą ujemną — inaczej bramka i jej
     *    dowód mierzyłyby dwa różne zakresy i dowód przestałby cokolwiek
     *    dowodzić, nie zmieniając ani jednego znaku w skrypcie.
     *
     * Osobno: wywołania NIE MA w `npm run build` ani w `Dockerfile`. Lista
     * `build` biegnie przy budowaniu obrazu, gdzie nie ma ani przeglądarki,
     * ani bazy — strażnik wywróciłby tam wydanie na własnym braku narzędzi.
     */
    public function test_straznik_martwych_regul_css_ma_kto_uruchomic(): void
    {
        $wywolanie = 'run: bash scripts/kaskada-kontrola-polecenie.sh';

        $this->assertSame(1, substr_count($this->workflow(), $wywolanie),
            'Strażnik martwych reguł CSS nie jest wołany dokładnie raz w `ci.yml`.');

        $job = $this->job('dostepnosc');
        $this->assertStringContainsString($wywolanie, $job,
            'Strażnik kaskady stoi poza jobem `dostepnosc` — a tylko ten ma Chromium, PHP i zasianą bazę.');
        $this->assertStringNotContainsString('continue-on-error:', $job);

        $przegladarka = strpos($job, 'npx playwright install chromium');
        $baza = strpos($job, 'migrate:fresh --seed');
        $straznik = strpos($job, $wywolanie);
        $this->assertIsInt($przegladarka);
        $this->assertIsInt($baza);
        $this->assertLessThan($straznik, $przegladarka,
            'Strażnik kaskady stoi PRZED instalacją Chromium — padłby na przyrządzie, nie na CSS-ie.');
        $this->assertLessThan($straznik, $baza,
            'Strażnik kaskady stoi PRZED zasianiem bazy — `/przepisy/rosol-babci-zofii` nie istniałoby.');

        // Kontrola ujemna dla tego testu: samo `node scripts/kaskada-martwe-reguly.mjs`
        // w `ci.yml` obeszłoby wspólne zawężenie i rozjechało bramkę z dowodem.
        $this->assertStringNotContainsString('node scripts/kaskada-martwe-reguly.mjs', $this->workflow(),
            'Bramka woła strażnika z pominięciem `kaskada-kontrola-polecenie.sh` — zawężenie `--tylko` ma jedno miejsce.');

        foreach (['package.json', 'Dockerfile'] as $plik) {
            $this->assertStringNotContainsString('kaskada-martwe-reguly', (string) file_get_contents(base_path($plik)),
                $plik.': strażnik potrzebuje przeglądarki i bazy, których tam nie ma.');
        }
    }

    /**
     * Filtr warstwy widoku stoi w JEDNYM miejscu i obejmuje sam przyrząd.
     *
     * Do 19 września 2026 ten sam filtr był skopiowany trzy razy — osobno
     * w `port_marki`, `port_funkcje` i `dostepnosc`. Trzy kopie 589-znakowego
     * wyrażenia z ręcznie utrzymywaną listą nazw skryptów to trzy miejsca,
     * w których lista mogła się rozjechać, i trzy do zaktualizowania przy
     * każdym nowym skrypcie pomiarowym.
     *
     * Gwarancja się nie zmienia: zmiana SAMEGO PRZYRZĄDU musi uruchomić
     * pomiar. Inaczej dałoby się zepsuć miernik i nie zobaczyć ani jednego
     * czerwonego przebiegu. Zmienia się tylko to, że pilnujemy jej w jednym
     * miejscu zamiast w trzech.
     */
    public function test_zmiana_samego_przyrzadu_nie_pomija_pomiarow(): void
    {
        $zakres = $this->job('zakres');

        // NAJPIERW liczba kopii, dopiero potem treść. Przy dwóch filtrach
        // `preg_match` bierze pierwszy z brzegu i test oblewałby na treści
        // wzorca — czyli z komunikatem o brakującym skrypcie zamiast o tym,
        // co się naprawdę stało. Czerwień ma mówić prawdę o przyczynie.
        $this->assertSame(1, preg_match_all("/grep -qE '/", $this->workflow()),
            'Filtr warstwy widoku jest w więcej niż jednym miejscu — znowu są kopie do utrzymania.');

        $this->assertSame(1, preg_match("/grep -qE '([^']+)'/", $zakres, $matches),
            'W jobie `zakres` nie ma filtra warstwy widoku.');

        $pattern = '~'.str_replace('~', '\\~', $matches[1]).'~';

        foreach (['scripts/port-grupy.mjs', 'scripts/port-grupy.test.mjs', 'scripts/nawigacja-etykiety.mjs', 'scripts/nawigacja-zoom.mjs', 'scripts/nawigacja-negatywy.mjs', 'scripts/szybki-wyglad.mjs', 'scripts/pasek-przewijany.mjs', 'scripts/zwarte-kolumny.mjs', 'scripts/katalog-tagow.mjs', 'scripts/zainteresowania-powiadomienia-marki.mjs', 'scripts/fixtures/kompozycje-513.php', 'resources/css/marka-onboarding.css', 'scripts/kaskada-martwe-reguly.mjs', 'scripts/kaskada-kontrola-polecenie.sh', 'scripts/kaskada-kontrola-ujemna.sh'] as $path) {
            $this->assertSame(1, preg_match($pattern, $path), 'zakres: pominięto '.$path);
        }

        // Zmiana samego `ci.yml` też uruchamia pomiar — inaczej dałoby się
        // przestawić bramkę bez ani jednego przebiegu, który by to pokazał.
        $this->assertSame(1, preg_match($pattern, '.github/workflows/ci.yml'),
            'zakres: zmiana samej bramki CI nie uruchamia pomiaru.');

        // Kontrola ujemna: filtr ma ROZRÓŻNIAĆ, a nie przepuszczać wszystko.
        $this->assertSame(0, preg_match($pattern, 'docs/PRODUCT.md'));
    }

    /**
     * NIE DA SIĘ PRZEJŚĆ JOBA PRZEGLĄDARKOWEGO, NIE URUCHAMIAJĄC POMIARU.
     *
     * To jest najgroźniejsza cecha, jaką ten plik miał. Warunek pomijania
     * stał na KROKACH (`if: steps.zmiany.outputs.warto == '1'`), a nie na
     * jobie. Przy zmianie kodu poza warstwą widoku job się URUCHAMIAŁ,
     * wszystkie kroki pomiarowe były pomijane, a job kończył się na ZIELONO.
     * Na liście kontrolnej PR-a nie dało się tego odróżnić od joba, który
     * naprawdę wszystko zmierzył.
     *
     * Zmierzone na 25 przebiegach CI przed zmianą:
     *   „Port marki (kompozycje…)"      15 sukcesów, 4 trwały 19–22 s
     *                                   (przy pomiarze: 462–506 s)
     *   „Port marki — rodziny ekranów"  13 sukcesów, 4 trwały 19–21 s
     *                                   (przy pomiarze: 1456–1638 s)
     *   „Dostępność (axe-core)…"        13 sukcesów, 4 trwały 18–22 s
     *                                   (przy pomiarze: 745–955 s)
     * Razem dwanaście zielonych wyników bez ani jednego pomiaru.
     *
     * Teraz warunek stoi na JOBIE: przy nietkniętej warstwie widoku cały job
     * jest `skipped`, czyli widać, że nic nie zmierzono. Ten test pilnuje,
     * żeby warunek nie wrócił na kroki.
     */
    public function test_job_przegladarkowy_nie_moze_byc_zielony_bez_pomiaru(): void
    {
        foreach (['port_marki', 'port_funkcje', 'dostepnosc'] as $name) {
            $job = $this->job($name);

            // 1. Warunek warstwy widoku stoi na JOBIE…
            $this->assertMatchesRegularExpression(
                "/^    if: .*needs\.zakres\.outputs\.widok == 'true'/m",
                $job,
                $name.': warunek warstwy widoku nie stoi na jobie.',
            );

            // 2. …i nadal obowiązuje filtr „to nie jest sama dokumentacja".
            $this->assertMatchesRegularExpression(
                "/^    if: .*needs\.zakres\.outputs\.kod == 'true'/m",
                $job,
                $name.': zniknął warunek o zmianie kodu.',
            );

            // 3. Żaden KROK nie może już decydować o pominięciu pomiaru.
            //    Jedyny dopuszczony warunek na kroku to `always()` przy
            //    wysyłce dowodów — ten ma się wykonać także po czerwieni.
            preg_match_all('/^        if: (.+)$/m', $job, $warunki);
            foreach ($warunki[1] as $warunek) {
                $this->assertStringNotContainsString('warto', $warunek,
                    $name.': warunek pomijania wrócił na krok — job znowu może być zielony bez pomiaru.');
                $this->assertStringNotContainsString('steps.zmiany', $warunek,
                    $name.': krok znowu czyta własny filtr zamiast wyjścia joba `zakres`.');
                $this->assertStringContainsString('always()', $warunek,
                    $name.': krok ma warunek inny niż `always()` — pomiar może zostać pominięty przy zielonym jobie.');
            }
        }
    }

    /**
     * `zakres` wystawia `widok` NA KAŻDEJ ścieżce wyjścia.
     *
     * To jest zabezpieczenie przed usterką GROŹNIEJSZĄ niż ta, którą
     * naprawiamy. Trzy joby przeglądarkowe ruszają dziś pod warunkiem
     * `needs.zakres.outputs.widok == 'true'`. Gdyby którakolwiek ścieżka
     * skryptu skończyła się bez zapisania tego wyjścia, porównanie z pustym
     * napisem byłoby FAŁSZEM — i wszystkie trzy joby pomijałyby się
     * ZAWSZE, po cichu, bez ani jednego czerwonego przebiegu.
     *
     * Skrypt ma dwie wczesne ścieżki (`exit 0`) na wypadek braku punktu
     * odniesienia i nieosiągalnej bazy. Obie muszą zapisać oba wyjścia.
     */
    public function test_zakres_wystawia_oba_wyjscia_na_kazdej_sciezce(): void
    {
        $zakres = $this->job('zakres');

        // Wyjścia zadeklarowane na jobie — bez tego `needs…` jest puste.
        $this->assertMatchesRegularExpression(
            '/outputs:\s*\n\s+kod:.*\n\s+widok:/',
            $zakres,
            'Job `zakres` nie wystawia obu wyjść.',
        );

        $linie = preg_split('/\R/', $zakres) ?: [];

        $wczesne = 0;
        foreach ($linie as $i => $linia) {
            if (preg_match('/^\s*exit 0\s*$/', $linia) !== 1) {
                continue;
            }

            $wczesne++;
            $okno = implode("\n", array_slice($linie, max(0, $i - 4), 5));

            $this->assertStringContainsString('kod=', $okno,
                'Ścieżka `exit 0` w linii '.($i + 1).' nie zapisuje `kod`.');
            $this->assertStringContainsString('widok=', $okno,
                'Ścieżka `exit 0` w linii '.($i + 1).' nie zapisuje `widok` — trzy joby '
                .'przeglądarkowe pomijałyby się wtedy ZAWSZE i po cichu.');
        }

        // Kontrola dodatnia: gdyby ktoś usunął wczesne wyjścia, pętla wyżej
        // nie sprawdziłaby niczego, a test byłby zielony (pułapka 2).
        $this->assertSame(2, $wczesne,
            'Zmieniła się liczba wczesnych wyjść ze skryptu `zakres` — przeczytaj je na nowo.');

        // Ścieżka końcowa (bez `exit`) też musi zapisać oba wyjścia.
        $this->assertSame(4, substr_count($zakres, 'echo "kod='));
        $this->assertSame(4, substr_count($zakres, 'echo "widok='));
    }

    /**
     * Kontrola dodatnia do testu wyżej (pułapka 4).
     *
     * Asercje „warunku nie ma" przeszłyby także wtedy, gdyby z jobów zniknęły
     * WSZYSTKIE kroki. Tu mierzymy, że pomiar w nich nadal stoi.
     */
    public function test_joby_przegladarkowe_nadal_uruchamiaja_pomiar(): void
    {
        foreach ([
            'port_marki' => 'run: node scripts/port-projektu.mjs',
            'port_funkcje' => 'run: node scripts/kroki-kreatora.mjs',
            'dostepnosc' => 'run: node scripts/dostepnosc.mjs',
        ] as $name => $pomiar) {
            $this->assertStringContainsString($pomiar, $this->job($name),
                $name.': job nie uruchamia już swojego pomiaru.');
        }
    }
}
