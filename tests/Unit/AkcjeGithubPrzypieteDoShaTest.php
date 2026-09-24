<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #951 — każde zewnętrzne `uses:` jest przypięte do pełnego SHA commita.
 *
 * `uses: actions/checkout@v7` wykonuje to, na co tag `v7` AKURAT wskazuje.
 * Tag można przesunąć bez żadnej zmiany w Kukingu, a część akcji działa
 * w jobach z tokenami Railway i Sentry. Pełny 40-znakowy SHA to jedyny
 * niezmienny sposób wskazania akcji; zmienić go może tylko commit w tym repo.
 *
 * Komentarz `# vX.Y.Z` za SHA jest obowiązkowy i musi być DOKŁADNĄ wersją:
 * bez niego nikt nie wie, co przypięto, a Dependabot (ekosystem
 * `github-actions` w `.github/dependabot.yml`) aktualizuje SHA razem z nim.
 *
 * Lokalne akcje (`./.github/actions/...`) zostają ścieżkami — żyją w tym
 * samym commicie, więc są niezmienne z definicji.
 *
 * Czego ten test NIE sprawdza: czy SHA istnieje u dostawcy i odpowiada
 * wersji z komentarza. To wie tylko GitHub — sprawdza to pierwszy przebieg CI
 * (nieistniejący SHA wywraca job) i przegląd PR-a Dependabota.
 */
final class AkcjeGithubPrzypieteDoShaTest extends TestCase
{
    private const PRZYPIETA = '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@[0-9a-f]{40}$#';

    private const WERSJA = '/^v?\d+\.\d+\.\d+$/';

    public function test_kazde_zewnetrzne_uses_ma_pelny_sha_i_dokladna_wersje(): void
    {
        $bledy = [];
        $zewnetrznych = 0;
        $pliki = [];

        foreach (self::plikiWorkflow() as $plik) {
            foreach (self::usesZPliku((string) file_get_contents($plik)) as [$linia, $wartosc, $komentarz]) {
                $blad = self::blad($wartosc, $komentarz);

                if ($blad === null && ! str_starts_with($wartosc, './')) {
                    $zewnetrznych++;
                    $pliki[self::wzgledna($plik)] = true;
                }

                if ($blad !== null) {
                    $bledy[] = self::wzgledna($plik).":$linia — $wartosc — $blad";
                }
            }
        }

        $this->assertSame([], $bledy, implode("\n", [
            'Zewnętrzna akcja bez pełnego SHA — tag jest ruchomy, więc ten sam commit może wykonać inny kod.',
            'Zapisz ją jako `uses: owner/repo@<40-znakowy SHA> # vX.Y.Z`. SHA tagu:',
            '  git ls-remote https://github.com/<owner>/<repo> "refs/tags/<tag>*"',
            '  (tag adnotowany: bierz wiersz z `^{}` — to commit, nie obiekt tagu)',
            'Do poprawy:',
            ...$bledy,
        ]));

        // Parser, który nic nie znajduje, przepuszcza wszystko. Obecnie jest
        // ponad 50 zewnętrznych odwołań w pięciu workflowach i akcji PHP.
        $this->assertGreaterThanOrEqual(50, $zewnetrznych, 'Test przestał widzieć `uses:` — stracił przedmiot.');

        foreach ([
            '.github/workflows/ci.yml',
            '.github/workflows/deploy.yml',
            '.github/workflows/preview.yml',
            '.github/workflows/railway-iac.yml',
            '.github/actions/php/action.yml',
        ] as $wymagany) {
            $this->assertArrayHasKey($wymagany, $pliki, "Strażnik nie widzi akcji w $wymagany.");
        }
    }

    public function test_dependabot_sledzi_akcje_github(): void
    {
        // Przypięcie bez Dependabota to zamrożenie — poprawki bezpieczeństwa
        // akcji przestają dochodzić.
        $dependabot = (string) file_get_contents(self::korzen().'/.github/dependabot.yml');

        $this->assertMatchesRegularExpression('/^\s*-\s*package-ecosystem:\s*"?github-actions"?\s*$/m', $dependabot);
    }

    /**
     * Kontrola dodatnia samego klasyfikatora na wejściu syntetycznym —
     * żeby zielony wynik głównego testu nie znaczył „parser zawsze mówi tak”.
     */
    public function test_klasyfikator_odroznia_przypiete_od_ruchomych(): void
    {
        $sha = str_repeat('a1', 20);
        $yaml = <<<YAML
            jobs:
              a:
                steps:
                  - name: Zażółć gęślą jaźń — polskie litery niosą bajt 0x85
                    uses: actions/checkout@v7
                  - uses: actions/checkout@{$sha} # v7.0.1
                  - uses: actions/checkout@{$sha}
                  - uses: actions/checkout@{$sha} # v7
                  - name: Krok
                    uses: 'shivammathur/setup-php@{$sha}' # 2.37.2
                  - uses: ./.github/actions/php
                  - uses: actions/cache@main # v6.1.0
                  - uses: actions/cache@{$sha}0 # v6.1.0
                  - uses: docker://alpine:3
                  # - uses: actions/checkout@v1
              b:
                uses: owner/repo/.github/workflows/x.yml@v1
            YAML;

        $uses = self::usesZPliku($yaml);
        $wynik = array_map(fn (array $u): ?string => self::blad($u[1], $u[2]), $uses);

        // `/\R/` bez flagi `u` tnie linię na bajcie 0x85 z „ą” i „ś” — numer
        // linii w komunikacie rozjeżdża się wtedy z plikiem.
        $this->assertSame(5, $uses[0][0], 'Numer linii ma wskazywać plik, nie bajty.');

        $this->assertCount(10, $wynik, 'Parser ma widzieć każde `uses:` poza zakomentowanym.');
        $this->assertNotNull($wynik[0], 'ruchomy tag');
        $this->assertNull($wynik[1], 'SHA + dokładna wersja');
        $this->assertNotNull($wynik[2], 'SHA bez komentarza wersji');
        $this->assertNotNull($wynik[3], 'SHA z ruchomym komentarzem');
        $this->assertNull($wynik[4], 'wartość w cudzysłowie');
        $this->assertNull($wynik[5], 'akcja lokalna');
        $this->assertNotNull($wynik[6], 'gałąź zamiast SHA');
        $this->assertNotNull($wynik[7], 'SHA 41-znakowy');
        $this->assertNotNull($wynik[8], 'obraz docker:// bez przypięcia');
        $this->assertNotNull($wynik[9], 'reusable workflow na tagu');
    }

    private static function blad(string $wartosc, string $komentarz): ?string
    {
        if (str_starts_with($wartosc, './')) {
            return null;
        }

        if (preg_match(self::PRZYPIETA, $wartosc) !== 1) {
            return 'brak pełnego 40-znakowego SHA';
        }

        if (preg_match(self::WERSJA, $komentarz) !== 1) {
            return 'brak komentarza z dokładną wersją (`# vX.Y.Z`)';
        }

        return null;
    }

    /**
     * @return list<array{int, string, string}> [numer linii, wartość, komentarz]
     */
    private static function usesZPliku(string $tresc): array
    {
        $wynik = [];

        foreach (explode("\n", $tresc) as $i => $linia) {
            if (preg_match('/^\s*(?:-\s+)?uses:\s*(?<wartosc>[^\s#]+)\s*(?:#\s*(?<komentarz>.*?))?\s*$/', $linia, $m) === 1) {
                $wynik[] = [$i + 1, trim($m['wartosc'], '\'"'), $m['komentarz'] ?? ''];
            }
        }

        return $wynik;
    }

    /**
     * @return list<string>
     */
    private static function plikiWorkflow(): array
    {
        $korzen = self::korzen();
        $pliki = [];

        foreach (['/.github/workflows', '/.github/actions'] as $katalog) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($korzen.$katalog, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $plik) {
                if (preg_match('/\.ya?ml$/', $plik->getFilename()) === 1) {
                    $pliki[] = $plik->getPathname();
                }
            }
        }

        sort($pliki);

        return $pliki;
    }

    private static function korzen(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function wzgledna(string $plik): string
    {
        return ltrim(substr($plik, strlen(self::korzen())), '/');
    }
}
