<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PODZIAŁ TESTÓW NA CZĘŚCI NIE GUBI ANI NIE DUBLUJE ŻADNEGO PLIKU.
 *
 * Od 24.09.2026 job testów w `.github/workflows/ci.yml` jest macierzą:
 * każda część uruchamia listę plików, którą wypisuje
 * `scripts/podzial-testow.php`. Plik, który nie trafi do żadnej części,
 * NIE URUCHAMIA SIĘ NIGDZIE — a przebieg jest zielony. To jest dokładnie ta
 * klasa usterki, której ten strażnik ma nie przepuścić.
 *
 * DOWÓD OD STRONY PHPUNITA, NIE SKRYPTU. Suma części jest porównywana z listą,
 * którą zwraca sam PHPUnit (`--list-test-files`) z tym samym `phpunit.xml`:
 *   - bez żadnego wykluczenia grup — suma części ma być jej RÓWNA (części
 *     dzielą zestaw, niczego nie dokładają i niczego nie gubią);
 *   - z wykluczeniami z `phpunit.xml` — to jest to, co naprawdę uruchamiało
 *     zwykłe `php artisan test`; każdy taki plik ma leżeć w którejś części.
 * Własny sprawdzian skryptu tego nie zastępuje: skrypt porównuje części ze
 * SWOJĄ listą, więc błąd w samym odkrywaniu plików byłby dla niego niewidoczny.
 *
 * DRUGA POŁOWA: `ci.yml`. Liczba części stoi w macierzy i w wywołaniu skryptu;
 * rozjazd (macierz 1–3, skrypt „z 4") zgubiłby czwartą część po cichu.
 * Wymagany check „Testy (PostgreSQL 18)" musi zostać — nosi go job zbiorczy.
 *
 * Kontrola dodatnia: funkcje orzekające dostają zepsute wejście w tym pliku,
 * a mutacje prawdziwych źródeł stoją w `scripts/kontrole-negatywne-alfa08.py`.
 */
#[Group('ci')]
class PodzialTestowJestKompletnyTest extends TestCase
{
    private const SKRYPT = 'scripts/podzial-testow.php';

    private const WORKFLOW = '.github/workflows/ci.yml';

    private const WYMAGANY_CHECK = 'Testy (PostgreSQL 18)';

    public function test_suma_czesci_to_pelna_lista_phpunita_bez_dubli(): void
    {
        $liczba = $this->liczbaCzesciZWorkflow($this->workflow());
        $czesci = [];

        for ($numer = 1; $numer <= $liczba; $numer++) {
            $czesci[$numer] = $this->czesc($numer, $liczba);
        }

        $wszystkie = $this->listaPhpunita(['--exclude-group', 'kuking-brak-takiej-grupy']);
        $uruchamiane = $this->listaPhpunita([]);

        // Kotwice: naprawdę mamy w ręku zestaw, a nie pustkę.
        $this->assertGreaterThan(500, count($wszystkie), 'PHPUnit wypisał podejrzanie mało plików testów.');
        $this->assertGreaterThan(500, count($uruchamiane), 'Domyślny przebieg ma podejrzanie mało plików.');

        $this->assertSame([], $this->naruszeniaPodzialu($czesci, $wszystkie, $uruchamiane));
    }

    public function test_podzial_jest_deterministyczny(): void
    {
        $this->assertSame(
            $this->czesc(1, 4),
            $this->czesc(1, 4),
            'Ten sam commit dał dwa różne podziały — części na różnych runnerach mogłyby się rozjechać.',
        );
    }

    public function test_ci_uruchamia_wszystkie_czesci_i_zachowuje_wymagany_check(): void
    {
        $this->assertSame([], $this->naruszeniaWorkflow($this->workflow()));
    }

    public function test_skrypt_odmawia_czesci_spoza_podzialu(): void
    {
        foreach ([['5', '4'], ['0', '4'], ['1', '0'], ['x', '4']] as $argumenty) {
            $proces = new Process(['php', self::SKRYPT, ...$argumenty], base_path());
            $proces->run();

            $this->assertNotSame(0, $proces->getExitCode(),
                'Skrypt przyjął część '.implode(' z ', $argumenty).' — job wypisałby pustą listę.');
            $this->assertSame('', trim($proces->getOutput()));
        }
    }

    /**
     * Warstwa 3: funkcje orzekające umieją powiedzieć „nie".
     */
    public function test_straznik_oblewa_na_podstawionym_wejsciu(): void
    {
        $pelna = ['tests/Feature/ATest.php', 'tests/Feature/BTest.php', 'tests/Unit/CTest.php'];
        $dobre = [1 => ['tests/Feature/ATest.php', 'tests/Unit/CTest.php'], 2 => ['tests/Feature/BTest.php']];

        $this->assertSame([], $this->naruszeniaPodzialu($dobre, $pelna, $pelna), 'Przyrząd odrzuca poprawny podział.');

        $this->assertNotSame([], $this->naruszeniaPodzialu(
            [1 => ['tests/Feature/ATest.php'], 2 => ['tests/Feature/BTest.php']], $pelna, $pelna,
        ), 'Przyrząd przepuścił plik, który nie trafił do żadnej części.');

        $this->assertNotSame([], $this->naruszeniaPodzialu(
            [1 => ['tests/Feature/ATest.php', 'tests/Unit/CTest.php'], 2 => ['tests/Feature/BTest.php', 'tests/Unit/CTest.php']],
            $pelna, $pelna,
        ), 'Przyrząd przepuścił plik w dwóch częściach.');

        $this->assertNotSame([], $this->naruszeniaPodzialu(
            [1 => $pelna, 2 => []], $pelna, $pelna,
        ), 'Przyrząd przepuścił pustą część.');

        $workflow = $this->workflow();
        $this->assertNotSame([], $this->naruszeniaWorkflow(
            str_replace('czesc: [1, 2, 3, 4, kontrole]', 'czesc: [1, 2, 3, kontrole]', $workflow),
        ), 'Przyrząd przepuścił macierz krótszą niż podział w skrypcie.');

        $this->assertNotSame([], $this->naruszeniaWorkflow(
            str_replace('    name: '.self::WYMAGANY_CHECK."\n", "    name: Testy zbiorcze\n", $workflow),
        ), 'Przyrząd przepuścił zniknięcie wymaganego checku.');

        $this->assertNotSame([], $this->naruszeniaWorkflow(
            str_replace('php artisan test "${pliki[@]}"', 'php artisan test', $workflow),
        ), 'Przyrząd przepuścił część uruchamiającą pełny zestaw zamiast swojej listy.');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FUNKCJE ORZEKAJĄCE — zwracają listę naruszeń; pusta znaczy „czysto".
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @param  array<int, list<string>>  $czesci
     * @param  list<string>  $wszystkie  lista PHPUnita bez wykluczeń grup
     * @param  list<string>  $uruchamiane  lista PHPUnita z wykluczeniami z phpunit.xml
     * @return list<string>
     */
    private function naruszeniaPodzialu(array $czesci, array $wszystkie, array $uruchamiane): array
    {
        $naruszenia = [];
        $suma = [];

        foreach ($czesci as $numer => $pliki) {
            if ($pliki === []) {
                $naruszenia[] = "Część {$numer} jest pusta — jej job byłby zielony bez uruchomienia testu.";
            }

            array_push($suma, ...$pliki);
        }

        foreach (array_count_values($suma) as $plik => $ile) {
            if ($ile > 1) {
                $naruszenia[] = "Plik {$plik} jest w {$ile} częściach — uruchomi się kilka razy.";
            }
        }

        foreach (array_diff($wszystkie, $suma) as $plik) {
            $naruszenia[] = "Plik {$plik} nie trafił do żadnej części — nie uruchamia się NIGDZIE.";
        }

        foreach (array_diff($suma, $wszystkie) as $plik) {
            $naruszenia[] = "Plik {$plik} jest w części, a PHPUnit nie uznaje go za test z phpunit.xml.";
        }

        foreach (array_diff($uruchamiane, $suma) as $plik) {
            $naruszenia[] = "Plik {$plik} szedł w zwykłym `php artisan test`, a żadna część go nie ma.";
        }

        return array_values(array_unique($naruszenia));
    }

    /** @return list<string> */
    private function naruszeniaWorkflow(string $workflow): array
    {
        $naruszenia = [];
        $test = $this->job($workflow, 'test');
        $zbiorczy = $this->job($workflow, 'testy');

        if ($test === null) {
            return ['W ci.yml nie ma joba `test` — części testów zniknęły z CI.'];
        }

        if ($zbiorczy === null) {
            return ['W ci.yml nie ma joba zbiorczego `testy`.'];
        }

        if (preg_match('/^      matrix:\n        czesc: \[([^\]]*)\]$/m', $test, $m) !== 1) {
            return ['Job `test` nie ma macierzy `czesc: [...]` — przyrząd nie wie, ile części rusza.'];
        }

        $wpisy = array_map('trim', explode(',', $m[1]));
        $numery = array_values(array_filter($wpisy, fn (string $wpis): bool => ctype_digit($wpis)));
        $liczba = count($numery);

        if ($numery !== array_map('strval', range(1, max(1, $liczba))) || $liczba < 2) {
            $naruszenia[] = 'Numery części w macierzy nie są kolejnymi 1..N: '.$m[1];
        }

        if (! in_array('kontrole', $wpisy, true)) {
            $naruszenia[] = 'Macierz nie ma wpisu `kontrole` — odwracalność migracji i kontrole negatywne nie ruszą.';
        }

        if (preg_match('/php scripts\/podzial-testow\.php "\$\{\{ matrix\.czesc \}\}" (\d+) /', $test, $wywolanie) !== 1) {
            $naruszenia[] = 'Krok „Testy" nie woła scripts/podzial-testow.php z numerem części z macierzy.';
        } elseif ((int) $wywolanie[1] !== $liczba) {
            $naruszenia[] = "Skrypt dzieli na {$wywolanie[1]} części, a macierz uruchamia {$liczba} — "
                .'reszta plików nie uruchomi się nigdzie.';
        }

        if (! str_contains($test, "format('część {0}/{$liczba}', matrix.czesc)")) {
            $naruszenia[] = "Nazwa joba części nie mówi „część N/{$liczba}\".";
        }

        if (! str_contains($test, 'php artisan test "${pliki[@]}"')) {
            $naruszenia[] = 'Część nie uruchamia `php artisan test` ze swoją listą plików.';
        }

        if (! str_contains($test, 'fail-fast: false')) {
            $naruszenia[] = 'Macierz bez `fail-fast: false` — pierwsza czerwona część anuluje resztę.';
        }

        if (preg_match('/^    name: (.+)$/m', $zbiorczy, $nazwa) !== 1 || trim($nazwa[1]) !== self::WYMAGANY_CHECK) {
            $naruszenia[] = 'Job zbiorczy nie nazywa się „'.self::WYMAGANY_CHECK.'" — ochrona gałęzi czeka na tę nazwę.';
        }

        if (preg_match_all('/^    name: '.preg_quote(self::WYMAGANY_CHECK, '/').'$/m', $workflow) !== 1) {
            $naruszenia[] = 'Nazwa „'.self::WYMAGANY_CHECK.'" ma stać w ci.yml przy dokładnie jednym jobie.';
        }

        if (preg_match('/^    needs: \[[^\]]*\btest\b[^\]]*\]$/m', $zbiorczy) !== 1) {
            $naruszenia[] = 'Job zbiorczy nie czeka na job `test`.';
        }

        if (preg_match('/^    if: \$\{\{ !cancelled\(\) \}\}$|^    if: always\(\)$/m', $zbiorczy) !== 1) {
            $naruszenia[] = 'Job zbiorczy nie rusza po czerwonej części — wymagany check byłby `skipped`, czyli zielony.';
        }

        if (! str_contains($zbiorczy, '${{ needs.test.result }}') || ! str_contains($zbiorczy, '= "success"')) {
            $naruszenia[] = 'Job zbiorczy nie rozstrzyga na podstawie wyniku części (`needs.test.result`).';
        }

        return $naruszenia;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ODCZYT
    // ═══════════════════════════════════════════════════════════════════

    /** @return list<string> */
    private function czesc(int $numer, int $liczba): array
    {
        $proces = new Process(['php', self::SKRYPT, (string) $numer, (string) $liczba], base_path());
        $proces->run();

        $this->assertSame(0, $proces->getExitCode(),
            "Skrypt podziału padł dla części {$numer}/{$liczba}: ".$proces->getErrorOutput());

        return array_values(array_filter(array_map('trim', explode("\n", $proces->getOutput()))));
    }

    /**
     * @param  list<string>  $opcje
     * @return list<string> ścieżki względem repozytorium, posortowane
     */
    private function listaPhpunita(array $opcje): array
    {
        $proces = new Process([
            'php', base_path('vendor/bin/phpunit'),
            '--configuration', base_path('phpunit.xml'),
            '--list-test-files', '--no-progress',
            ...$opcje,
        ], base_path(), timeout: 300);
        $proces->run();

        $this->assertSame(0, $proces->getExitCode(), 'PHPUnit nie wypisał listy plików: '
            .$proces->getErrorOutput().$proces->getOutput());

        $prefiks = rtrim(base_path(), '/').'/';
        $pliki = [];

        foreach (explode("\n", $proces->getOutput()) as $linia) {
            if (str_starts_with($linia, ' - ')) {
                $sciezka = substr($linia, 3);
                $prawdziwa = realpath($sciezka) ?: $sciezka;
                $pliki[] = str_starts_with($prawdziwa, $prefiks) ? substr($prawdziwa, strlen($prefiks)) : $prawdziwa;
            }
        }

        sort($pliki, SORT_STRING);

        return $pliki;
    }

    private function liczbaCzesciZWorkflow(string $workflow): int
    {
        $test = $this->job($workflow, 'test');

        $this->assertNotNull($test, 'W ci.yml nie ma joba `test`.');
        $this->assertSame(1, preg_match('/php scripts\/podzial-testow\.php "\$\{\{ matrix\.czesc \}\}" (\d+) /', (string) $test, $m),
            'Nie widzę w ci.yml wywołania scripts/podzial-testow.php — nie wiem, na ile części dzielić.');

        return (int) $m[1];
    }

    private function job(string $workflow, string $nazwa): ?string
    {
        $bezKomentarzy = (string) preg_replace('/^\s*#.*$/m', '', $workflow);

        if (preg_match('/^  '.preg_quote($nazwa, '/').':\n(.*?)(?=^  [a-z0-9_-]+:\n|\z)/ms', $bezKomentarzy, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function workflow(): string
    {
        $tresc = (string) file_get_contents(base_path(self::WORKFLOW));

        $this->assertGreaterThan(20000, strlen($tresc), 'ci.yml wygląda na pusty albo nie ten.');

        return $tresc;
    }
}
