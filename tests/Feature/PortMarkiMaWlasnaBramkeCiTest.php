<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PortMarkiMaWlasnaBramkeCiTest extends TestCase
{
    public function test_pomiary_nie_pobieraja_historii_ktora_czyta_tylko_zakres(): void
    {
        foreach (['port_marki', 'port_funkcje', 'dostepnosc'] as $name) {
            $job = $this->job($name);
            // Kontrola dodatnia: brak całego checkoutu nie jest oszczędnością.
            $this->assertSame(1, preg_match('/uses: actions\/checkout@[^\s]+(?<opcje>.*?)(?=^      - |\z)/ms', $job, $checkout), $name.': brak checkoutu.');
            $this->assertDoesNotMatchRegularExpression('/fetch-depth:\s*0\b/', $checkout['opcje'],
                $name.': pełną historię pobiera już zakres; pomiar potrzebuje drzewa i indeksu.');
            $this->assertStringContainsString('needs: zakres', $job);
            $this->assertStringContainsString('needs.zakres.outputs.widok', $job);
        }
    }

    public function test_zakres_zachowuje_historie_do_porownania_z_baza(): void
    {
        $job = $this->job('zakres');
        $this->assertStringContainsString('fetch-depth: 0', $job);
        $this->assertStringContainsString('git diff --name-only "${BAZA}" HEAD', $job);
    }

    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    }

    private function job(string $name): string
    {
        $matched = preg_match('/^  '.preg_quote($name, '/').':(?:\r\n|\n|\r)(.*?)(?=^  [a-z_]+:|\z)/ms', $this->workflow(), $matches);
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
            $this->assertStringContainsString('uses: actions/checkout@', $job);
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

        foreach (['scripts/referrer-sekret-browser.mjs', 'app/Http/Middleware/ApplySecurityHeaders.php', 'app/Support/AnalitykaCloudflare.php'] as $path) {
            $this->assertSame(1, preg_match($pattern, $path), 'Pomiar referrera pominięty: '.$path);
        }
        $referrerJob = $this->job('dostepnosc');
        $this->assertStringContainsString('node scripts/referrer-sekret-browser.mjs', $referrerJob);
        $this->assertStringContainsString('DB_DATABASE: kuking_port_referrer', $referrerJob);
        $this->assertStringNotContainsString('continue-on-error:', $referrerJob);

        foreach (['scripts/port-grupy.mjs', 'scripts/port-grupy.test.mjs', 'scripts/nawigacja-etykiety.mjs', 'scripts/nawigacja-zoom.mjs', 'scripts/nawigacja-negatywy.mjs', 'scripts/szybki-wyglad.mjs', 'scripts/pasek-przewijany.mjs', 'scripts/zwarte-kolumny.mjs', 'scripts/katalog-tagow.mjs', 'scripts/zainteresowania-powiadomienia-marki.mjs', 'scripts/fixtures/kompozycje-513.php', 'resources/css/marka-onboarding.css'] as $path) {
            $this->assertSame(1, preg_match($pattern, $path), 'zakres: pominięto '.$path);
        }

        // Zmiana samego `ci.yml` też uruchamia pomiar — inaczej dałoby się
        // przestawić bramkę bez ani jednego przebiegu, który by to pokazał.
        $this->assertSame(1, preg_match($pattern, '.github/workflows/ci.yml'),
            'zakres: zmiana samej bramki CI nie uruchamia pomiaru.');

        // Kontrola ujemna: filtr ma ROZRÓŻNIAĆ, a nie przepuszczać wszystko.
        $this->assertSame(0, preg_match($pattern, 'docs/PRODUCT.md'));
    }

    public static function screenPaths(): array
    {
        return [
            'kreator' => ['app/Livewire/RecipeWizard.php', true],
            'komponent' => ['app/View/Components/Layout.php', true],
            'kontroler' => ['app/Http/Controllers/RecipeController.php', true],
            'kontroler techniczny bez wyjątków' => ['app/Http/Controllers/HealthController.php', true],
            'middleware' => ['app/Http/Middleware/EnsureAccountIsActive.php', true],
            'model' => ['app/Models/Recipe.php', true],
            'polityka' => ['app/Policies/RecipePolicy.php', true],
            'domena' => ['app/Domain/Search/SearchQuery.php', true],
            'trasy' => ['routes/web.php', true],
            'konfiguracja' => ['config/kuking.php', true],
            'start aplikacji' => ['bootstrap/app.php', true],
            'tłumaczenia' => ['lang/pl/validation.php', true],
            'dane ekranów' => ['database/seeders/DemoSeeder.php', true],
            'zależności' => ['composer.json', true],
            'wersje zależności' => ['composer.lock', true],
            'dokumentacja' => ['docs/PRODUCT.md', false],
            'instrukcja główna' => ['README.md', false],
            'ścieżka podobna do zależności' => ['composer.json.md', false],
            'dokumentacja i PHP' => ["docs/PRODUCT.md\napp/Livewire/RecipeWizard.php", true],
            'duży diff bez SIGPIPE' => ["app/Livewire/RecipeWizard.php\n".str_repeat("docs/dlugi-niezmieniajacy-interfejsu-opis.md\n", 10000), true],
        ];
    }

    /** Uruchamia rzeczywisty warunek w Bashu, nie tłumaczenie regexu na PCRE. */
    #[DataProvider('screenPaths')]
    public function test_php_sterujace_ekranem_uruchamia_pomiar(string $paths, bool $expected): void
    {
        // Filtr zawęża WYŁĄCZNIE na PR-ach — tu sprawdzamy właśnie tę gałąź.
        $this->assertSame('widok='.($expected ? 'true' : 'false')."\n", $this->filtrWidoku($paths, 'pull_request'),
            'zakres: błędna decyzja dla '.strtok($paths, "\n"));
    }

    /**
     * POZA PR-EM FILTR WIDOKU NIE ZAWĘŻA (decyzja właściciela 24.09.2026).
     *
     * Push na `main`/`staging` i uruchomienie ręczne mierzą pełny zestaw.
     * Brak `ZDARZENIE` (np. ktoś usunie zmienną z kroku) też ma dać pełny
     * zestaw — pomyłka w konfiguracji ma kosztować minuty, nie pomiar.
     * Kontrola ujemna to `docs/PRODUCT.md` na PR-ze w teście wyżej (`false`).
     */
    public function test_poza_pull_requestem_filtr_widoku_nie_zaweza(): void
    {
        foreach (['push', 'workflow_dispatch', null] as $zdarzenie) {
            $this->assertSame("widok=true\n", $this->filtrWidoku('docs/PRODUCT.md', $zdarzenie),
                'zakres: filtr widoku zawęża poza PR-em (zdarzenie: '.($zdarzenie ?? 'brak').').');
        }
    }

    /** Uruchamia rzeczywisty blok filtra widoku z `ci.yml` w Bashu. */
    private function filtrWidoku(string $paths, ?string $zdarzenie): string
    {
        $this->assertSame(1, preg_match('/^\s*(if [^\n]*grep -qE .*?^\s*fi)/ms', $this->job('zakres'), $matches));
        $output = tempnam(sys_get_temp_dir(), 'kuking-zakres-');
        $this->assertNotFalse($output);

        try {
            $process = new Process(['bash', '-c', "set -euo pipefail\nZMIENIONE=\"$(cat)\"\n".$matches[1]], base_path(), [
                'GITHUB_OUTPUT' => $output,
                // `false` usuwa zmienną odziedziczoną ze środowiska.
                'ZDARZENIE' => $zdarzenie ?? false,
            ]);
            $process->setInput($paths);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            return (string) file_get_contents($output);
        } finally {
            unlink($output);
        }
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
            //    Jedyny dopuszczony warunek na kroku stoi przy wysyłce
            //    dowodów: `always()` albo `failure()` — oba wykonują krok po
            //    czerwieni. `failure()` od 23.09: przy wyczerpanym limicie
            //    miejsca na artefakty wysyłka po zielonym jobie czerwieniła
            //    CI, a dowody zielonego przebiegu nikomu nie są potrzebne.
            preg_match_all('/^        if: (.+)$/m', $job, $warunki);
            foreach ($warunki[1] as $warunek) {
                $this->assertStringNotContainsString('warto', $warunek,
                    $name.': warunek pomijania wrócił na krok — job znowu może być zielony bez pomiaru.');
                $this->assertStringNotContainsString('steps.zmiany', $warunek,
                    $name.': krok znowu czyta własny filtr zamiast wyjścia joba `zakres`.');
                $this->assertMatchesRegularExpression('/^(always|failure)\(\)$/', trim($warunek),
                    $name.': krok ma warunek inny niż `always()` albo `failure()` — pomiar może zostać pominięty przy zielonym jobie.');
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

        $linie = preg_split('/\r\n|\n|\r/', $zakres) ?: [];

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
