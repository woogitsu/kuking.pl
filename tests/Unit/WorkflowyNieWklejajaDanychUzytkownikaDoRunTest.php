<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #1859 — dane od użytkownika nigdy nie są wklejane w treść `run:`.
 *
 * GitHub rozwija wyrażenie `${{ … }}` w bloku `run:` PRZED uruchomieniem
 * powłoki: wynik staje się częścią skryptu. W `preview.yml` ręczne pole
 * `pr_number` stało w `env_name="pr-${{ github.event.inputs.pr_number }}"`,
 * więc wpisanie `1"; echo X; #` wykonywało kod w jobie z tokenem Railway.
 * To samo dotyczy tytułów, opisów i gałęzi z `github.event.*` oraz
 * `github.head_ref`.
 *
 * Reguła (GitHub, „Using an intermediate environment variable”): wartość idzie
 * do kroku przez `env:`, a skrypt czyta ją jako `"$ZMIENNA"`. Wyrażenie
 * w `env:`, `with:`, `if:` czy `concurrency:` jest bezpieczne — nie trafia
 * do interpretera. Strażnik patrzy więc WYŁĄCZNIE na treść `run:`, także na
 * komentarze w niej: GitHub rozwija wyrażenia również w komentarzach powłoki.
 *
 * Za dane od użytkownika uznajemy: `github.event.*` (w tym
 * `github.event.inputs.*`), `inputs.*` i `github.head_ref`. Nie zgadujemy typu
 * pola — `inputs.*` typu boolean też musi iść przez `env:`, bo typ jutro może
 * zmienić się na string i nikt nie wróci do skryptu.
 *
 * Czego ten test NIE sprawdza: `steps.*.outputs.*`, `matrix.*`, `env.*`,
 * `github.sha` i podobnych wartości ustalanych przez nas albo przez GitHub.
 * Wyjście kroku, które przenosi dane od użytkownika, jest pośrednim kanałem
 * — tego tekstowy strażnik nie wyśledzi.
 */
final class WorkflowyNieWklejajaDanychUzytkownikaDoRunTest extends TestCase
{
    /**
     * Pliki z ZNANYM, osobno zgłoszonym naruszeniem, którego naprawa idzie
     * inną gałęzią. Wyjątek nie zapala testu, ale też nie jest wymagany:
     * gdy plik będzie czysty, test dalej przechodzi — wtedy usuń wpis.
     *
     * @var array<string, string>
     */
    private const ZNANE_DO_NAPRAWY = [
        // `deploy.yml` wkleja `github.event.deployment.*` i `github.event.inputs.*`
        // w `run:` — naprawa w #1851 (osobna gałąź). Po jej scaleniu usuń ten wpis.
        '.github/workflows/deploy.yml' => '#1851',
    ];

    private const DANE_UZYTKOWNIKA = '/(?<![\w.])(?:github\.event\.|inputs\.|github\.head_ref\b)/';

    public function test_zaden_run_nie_wkleja_danych_uzytkownika(): void
    {
        $bledy = [];
        $blokowRun = 0;
        $pliki = [];

        foreach (self::plikiWorkflow() as $plik) {
            $wzgledna = self::wzgledna($plik);
            $tresc = (string) file_get_contents($plik);
            $blokowRun += count(self::blokiRun($tresc));
            $pliki[$wzgledna] = true;

            if (isset(self::ZNANE_DO_NAPRAWY[$wzgledna])) {
                continue;
            }

            foreach (self::naruszenia($tresc) as [$linia, $wyrazenie]) {
                $bledy[] = "$wzgledna:$linia — $wyrazenie";
            }
        }

        $this->assertSame([], $bledy, implode("\n", [
            'Dane od użytkownika wklejone w treść `run:` — GitHub podstawia je PRZED startem powłoki, więc stają się kodem (#1859).',
            'Przenieś wartość do `env:` kroku i czytaj ją w skrypcie jako "$ZMIENNA";',
            'jeśli to identyfikator (numer PR-a, nazwa środowiska), zwaliduj go wzorcem przed użyciem.',
            'Do poprawy:',
            ...$bledy,
        ]));

        // Parser, który nie widzi żadnego `run:`, przepuszcza wszystko.
        $this->assertGreaterThanOrEqual(50, $blokowRun, 'Strażnik przestał widzieć bloki `run:` — stracił przedmiot.');

        foreach (['.github/workflows/ci.yml', '.github/workflows/preview.yml', '.github/workflows/railway-iac.yml'] as $wymagany) {
            $this->assertArrayHasKey($wymagany, $pliki, "Strażnik nie czyta $wymagany.");
        }
    }

    /**
     * Kontrola ujemna i dodatnia klasyfikatora na wejściu syntetycznym —
     * żeby zielony wynik głównego testu nie znaczył „parser nic nie widzi”.
     */
    public function test_klasyfikator_odroznia_wklejenie_od_env(): void
    {
        $yaml = <<<'YAML'
            on:
              workflow_dispatch:
                inputs:
                  pr_number: { type: string }
            concurrency:
              group: preview-${{ github.event.inputs.pr_number }}
            jobs:
              a:
                if: github.event.inputs.action == 'create'
                steps:
                  - name: Zażółć gęślą jaźń — wklejone w blok
                    run: |
                      set -euo pipefail
                      env_name="pr-${{ github.event.inputs.pr_number }}"
                  - name: Przez env — dobrze
                    env:
                      PR_NUMBER: ${{ github.event.inputs.pr_number }}
                      TYTUL: ${{ github.event.pull_request.title }}
                    run: |
                      echo "pr-$PR_NUMBER $TYTUL"
                  - run: echo "${{ inputs.zmiany }}"
                  - name: Komentarz też jest rozwijany
                    run: |
                      # stare: ${{ github.head_ref }}
                      echo ok
                  - name: Wartości nie od użytkownika
                    run: |
                      echo "${{ github.sha }} ${{ matrix.czesc }} ${{ steps.a.outputs.url }} ${{ env.X }}"
                      echo "${{ job.services.postgres.ports[5432] }}"
                  - name: Z dodatkowymi spacjami i złożeniem
                    run: >-
                      echo ${{github.event.issue.title}}
                      && echo ${{ format('{0}', inputs.x) }}
                  - uses: actions/github-script@v7
                    with:
                      script: console.log('${{ github.event.inputs.pr_number }}')
                  - name: Po bloku
                    shell: bash
            YAML;

        $naruszenia = self::naruszenia($yaml);
        $linie = array_column($naruszenia, 0);

        $this->assertSame([14, 21, 24, 32, 33], $linie, implode("\n", array_map(
            static fn (array $n): string => $n[0].': '.$n[1],
            $naruszenia,
        )));
        $this->assertSame('${{ github.event.inputs.pr_number }}', $naruszenia[0][1]);
    }

    /**
     * @return list<array{int, string}> [numer linii, wyrażenie]
     */
    private static function naruszenia(string $yaml): array
    {
        $wynik = [];

        foreach (self::blokiRun($yaml) as [$linia, $tresc]) {
            if (preg_match_all('/\$\{\{(.*?)\}\}/', $tresc, $m) === false) {
                continue;
            }

            foreach ($m[1] as $i => $wnetrze) {
                if (preg_match(self::DANE_UZYTKOWNIKA, $wnetrze) === 1) {
                    $wynik[] = [$linia, $m[0][$i]];
                }
            }
        }

        return $wynik;
    }

    /**
     * Każda linia treści `run:` — zarówno `run: polecenie`, jak i blok
     * `run: |` / `run: >-` z liniami wciętymi głębiej niż klucz.
     *
     * @return list<array{int, string}> [numer linii w pliku, treść linii]
     */
    private static function blokiRun(string $yaml): array
    {
        $wynik = [];
        $wciecieKlucza = null;

        foreach (preg_split('/\r\n|\n|\r/', $yaml) ?: [] as $i => $linia) {
            $wciecie = strlen($linia) - strlen(ltrim($linia, ' '));

            if ($wciecieKlucza !== null) {
                if (trim($linia) === '' || $wciecie > $wciecieKlucza) {
                    $wynik[] = [$i + 1, $linia];

                    continue;
                }

                $wciecieKlucza = null;
            }

            if (preg_match('/^(?<wciecie>\s*)(?:-\s+)?run:\s*(?<reszta>.*)$/', $linia, $m) !== 1) {
                continue;
            }

            $reszta = trim($m['reszta']);

            if ($reszta === '' || preg_match('/^[|>][+-]?\d*$/', $reszta) === 1) {
                // Treść bloku jest wcięta głębiej niż klucz `run` (także gdy
                // stoi za `- ` na liście kroków).
                $wciecieKlucza = strlen($m['wciecie']) + (str_contains($linia, '- run:') ? 2 : 0);

                continue;
            }

            $wynik[] = [$i + 1, $reszta];
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
