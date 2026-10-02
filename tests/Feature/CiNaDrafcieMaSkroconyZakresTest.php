<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * CI NA DRAFT PR-ZE MA ZAKRES SKRÓCONY — A NA PEŁNYM PRZEBIEGU NIC NIE GINIE.
 *
 * DECYZJA WŁAŚCICIELA Z 2.10.2026 (wiersz w D-333, bez nowego numeru D).
 * Pomiar z 2.10.2026: w 12,5 h 3509 jobów, ok. 28 000 minut runnera, średnio
 * 34 joby naraz, szczyt 157. Prawie całość to `CI` na DRAFT PR-ach agentów do
 * `codex/integracja-*`, których nie scalamy bezpośrednio (scalamy paczki,
 * a paczka ma pełne CI). Bramka `zakres` wystawia więc siódme wyjście,
 * `pelny`: `false` wyłącznie na draft PR-ze.
 *
 * Ten strażnik pilnuje OBU stron tej decyzji, od strony DOWODU:
 *
 *   1. na drafcie z joba z warunkiem na bramce biegną DOKŁADNIE `lint`,
 *      `static-analysis` i `test`, a macierz `test` ma tylko części 1–4;
 *   2. na pełnym przebiegu — PR nie-draft, push (także z `DRAFT=true`
 *      w środowisku), `workflow_dispatch`, brak zdarzenia — ŻADEN job nie jest
 *      pominięty z powodu draftu, a macierz ma trzy części kontroli;
 *   3. joby bez warunku na bramce to tylko `zakres` i dwa agregaty — nowy job
 *      bez warunku biegłby na każdym drafcie;
 *   4. agregaty `testy` i `panel_marki` (wymagane kontrole) na pełnym
 *      przebiegu NIE dają zieleni z pominiętych części, a na drafcie mówią
 *      w podsumowaniu „draft — zakres skrócony".
 *
 * Decyzję bramki bierze z URUCHOMIENIA `scripts/ci/zakres.sh`, a warunki
 * jobów i skrypty agregatów — z `ci.yml`. Kontrola ujemna jest w tym samym
 * pliku: każda z mutacji (warunek joba, skrypt bramki, `include` macierzy,
 * agregat panelu) MUSI dać naruszenie. Te same mutacje stoją też w
 * `scripts/kontrole-negatywne-alfa08.py`.
 */
#[Group('ci')]
class CiNaDrafcieMaSkroconyZakresTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/ci.yml';

    private const SKRYPT = 'scripts/ci/zakres.sh';

    /** Jedyne joby z warunkiem na bramce, które biegną na drafcie. */
    private const JOBY_DRAFTU = ['lint', 'static-analysis', 'test'];

    /** Joby bez warunku na wyjściach bramki: sama bramka i dwa agregaty. */
    private const JOBY_BEZ_WARUNKU = ['zakres', 'testy', 'panel_marki'];

    /**
     * Zmiana, przy której KAŻDE wyjście obszaru jest `true` — różnica między
     * draftem a pełnym przebiegiem wynika wtedy wyłącznie z `pelny`.
     */
    private const ZMIANA_WSZYSTKIEGO = ['.github/workflows/ci.yml', 'docs/PRODUCT.md'];

    public function test_na_drafcie_biegna_dokladnie_lint_larastan_i_testy_1_4_a_na_pelnym_wszystko(): void
    {
        $naruszenia = $this->naruszenia($this->tresc(self::WORKFLOW), $this->tresc(self::SKRYPT));

        $this->assertSame([], $naruszenia, "Zakres draftu albo pełnego przebiegu się rozjechał:\n".implode("\n", $naruszenia));
    }

    public function test_kontrola_ujemna_kazda_mutacja_daje_naruszenie(): void
    {
        $workflow = $this->tresc(self::WORKFLOW);
        $skrypt = $this->tresc(self::SKRYPT);
        $warunekKaskady = "    if: needs.zakres.outputs.kod == 'true' && needs.zakres.outputs.widok == 'true' && needs.zakres.outputs.pelny == 'true'\n";

        $mutacje = [
            'ciężki job bez warunku `pelny` (biegnie na drafcie)' => [
                $this->podmienWJobie($workflow, 'kaskada', $warunekKaskady, "    if: needs.zakres.outputs.kod == 'true' && needs.zakres.outputs.widok == 'true'\n"),
                $skrypt,
            ],
            'ciężki job pominięty na pełnym przebiegu' => [
                $this->podmienWJobie($workflow, 'dostepnosc', "needs.zakres.outputs.pelny == 'true'", "needs.zakres.outputs.pelny == 'false'"),
                $skrypt,
            ],
            'Pint pominięty na drafcie' => [
                $this->podmienWJobie($workflow, 'lint', "    if: needs.zakres.outputs.kod == 'true' || needs.zakres.outputs.dokumenty == 'true'\n", "    if: needs.zakres.outputs.kod == 'true' && needs.zakres.outputs.pelny == 'true'\n"),
                $skrypt,
            ],
            'kontrole negatywne na drafcie' => [
                $this->podmien($workflow, "\"kontrole_czesc\":3}]' || '[]') }}", "\"kontrole_czesc\":3}]' || '[{\"czesc\":\"kontrole\",\"kontrole_czesc\":1}]') }}"),
                $skrypt,
            ],
            'agregat panelu zielony z pominiętych części na pełnym przebiegu' => [
                $this->podmien($workflow, "            && [ \"\${PELNY}\" = \"false\" ]; then\n", "            ; then\n"),
                $skrypt,
            ],
            'agregat testów bez słowa o drafcie' => [
                $this->podmien($workflow, 'echo "### Testy (PostgreSQL 18): draft — zakres skrócony"', 'echo "### Testy (PostgreSQL 18): zielone"'),
                $skrypt,
            ],
            'agregat testów zielony bez wyjścia `pelny`' => [
                $this->podmien($workflow, 'if [ "${PELNY}" != "true" ] && [ "${PELNY}" != "false" ]; then', 'if false; then'),
                $skrypt,
            ],
            'bramka daje `pelny=false` także poza PR-em' => [
                $workflow,
                $this->podmien($skrypt, 'if [ "${ZDARZENIE:-}" = "pull_request" ] && [ "${DRAFT:-}" = "true" ]; then', 'if [ "${DRAFT:-}" = "true" ]; then'),
            ],
            'bramka nie skraca draftu' => [
                $workflow,
                $this->podmien($skrypt, 'echo "pelny=false" >> "$GITHUB_OUTPUT"', 'echo "pelny=true" >> "$GITHUB_OUTPUT"'),
            ],
            'nowy job bez warunku na bramce' => [
                $this->podmien($workflow, "  audit:\n", "  nowy_ciezki:\n    name: Nowy\n    needs: zakres\n    runs-on: ubuntu-latest\n    steps:\n      - run: 'true'\n\n  audit:\n"),
                $skrypt,
            ],
        ];

        foreach ($mutacje as $opis => [$w, $s]) {
            $this->assertNotSame([], $this->naruszenia($w, $s), "Kontrola ujemna nie zapaliła: {$opis}.");
        }
    }

    /**
     * Pełna analiza dla podanej treści `ci.yml` i skryptu bramki.
     *
     * @return list<string>
     */
    private function naruszenia(string $workflow, string $skrypt): array
    {
        $naruszenia = [];
        $bloki = $this->blokiJobow($workflow);

        foreach (array_merge(self::JOBY_DRAFTU, self::JOBY_BEZ_WARUNKU, ['assets', 'kaskada', 'audit', 'docker-build']) as $job) {
            if (! isset($bloki[$job])) {
                return ["Brak joba `{$job}` w ci.yml — strażnik nie ma czego mierzyć."];
            }
        }

        $warunkowe = [];
        $bezWarunku = [];
        foreach ($bloki as $job => $blok) {
            if (preg_match('/^    if: (.*needs\.zakres\.outputs\..*)$/m', $blok, $m) === 1) {
                $warunkowe[$job] = trim($m[1]);
            } else {
                $bezWarunku[] = $job;
            }
        }

        sort($bezWarunku);
        $oczekiwaneBez = self::JOBY_BEZ_WARUNKU;
        sort($oczekiwaneBez);
        if ($bezWarunku !== $oczekiwaneBez) {
            $naruszenia[] = 'Joby bez warunku na bramce `zakres`: '.implode(', ', $bezWarunku)
                .' (dozwolone tylko: '.implode(', ', $oczekiwaneBez).') — taki job biegnie także na każdym drafcie.';
        }

        // Kontrola dodatnia przyrządu: warunków ma być wiele, inaczej nic tu nie mierzymy.
        if (count($warunkowe) < 10) {
            $naruszenia[] = 'Przyrząd znalazł tylko '.count($warunkowe).' jobów z warunkiem na bramce — zły odczyt ci.yml.';
        }

        $scenariusze = [
            'draft PR' => ['pull_request', 'true', false],
            'PR nie-draft' => ['pull_request', 'false', true],
            'PR bez DRAFT' => ['pull_request', null, true],
            'push (z DRAFT=true w środowisku)' => ['push', 'true', true],
            'workflow_dispatch' => ['workflow_dispatch', null, true],
            'brak zdarzenia' => [null, 'true', true],
        ];

        foreach ($scenariusze as $nazwa => [$zdarzenie, $draft, $pelnyOczekiwany]) {
            $wyjscia = $this->wyjsciaBramki($skrypt, $zdarzenie, $draft);
            if (($wyjscia['pelny'] ?? '') !== ($pelnyOczekiwany ? 'true' : 'false')) {
                $naruszenia[] = "{$nazwa}: bramka wystawiła pelny=".($wyjscia['pelny'] ?? 'BRAK');
            }

            $biegna = [];
            foreach ($warunkowe as $job => $warunek) {
                $wynik = $this->spelniony($warunek, $wyjscia);
                if ($wynik === null) {
                    $naruszenia[] = "Job `{$job}`: warunek „{$warunek}” ma kształt, którego przyrząd nie ocenia.";

                    continue;
                }
                if ($wynik) {
                    $biegna[] = $job;
                }
            }
            sort($biegna);

            $oczekiwane = $pelnyOczekiwany ? array_keys($warunkowe) : self::JOBY_DRAFTU;
            sort($oczekiwane);
            if ($biegna !== $oczekiwane) {
                $naruszenia[] = "{$nazwa}: biegną ".implode(', ', $biegna).'; mają biec '.implode(', ', $oczekiwane).'.';
            }

            $kontrole = $this->czesciKontroli($bloki['test'], $wyjscia);
            if ($kontrole === null) {
                $naruszenia[] = 'Macierz `test` nie ma `include` w oczekiwanym kształcie (wyrażenie na `pelny`).';
            } elseif ($kontrole !== ($pelnyOczekiwany ? [1, 2, 3] : [])) {
                $naruszenia[] = "{$nazwa}: części kontroli negatywnych w macierzy: [".implode(', ', $kontrole).'].';
            }
        }

        return array_merge($naruszenia, $this->naruszeniaAgregatow($bloki));
    }

    /**
     * Części kontroli, które utworzy macierz `test` przy danych wyjściach bramki.
     * `null` = `include` w nieznanym kształcie (sam ten fakt jest naruszeniem).
     *
     * @param  array<string, string>  $wyjscia
     * @return list<int>|null
     */
    private function czesciKontroli(string $blokTestu, array $wyjscia): ?array
    {
        if (preg_match(
            "/^        include: \\$\\{\\{ fromJSON\\(needs\\.zakres\\.outputs\\.([a-z]+) == '([a-z]+)' && '(\\[[^']*\\])' \\|\\| '(\\[[^']*\\])'\\) \\}\\}$/m",
            $blokTestu,
            $m,
        ) !== 1) {
            return null;
        }

        $lista = json_decode(($wyjscia[$m[1]] ?? '') === $m[2] ? $m[3] : $m[4], true);
        if (! is_array($lista)) {
            return null;
        }

        $czesci = [];
        foreach ($lista as $wpis) {
            if (! is_array($wpis) || ($wpis['czesc'] ?? null) !== 'kontrole' || ! is_int($wpis['kontrole_czesc'] ?? null)) {
                return null;
            }
            $czesci[] = $wpis['kontrole_czesc'];
        }
        sort($czesci);

        return $czesci;
    }

    /**
     * Skrypty agregatów (wymaganych kontroli) uruchomione na stanach, które
     * daje draft i pełny przebieg.
     *
     * @param  array<string, string>  $bloki
     * @return list<string>
     */
    private function naruszeniaAgregatow(array $bloki): array
    {
        $naruszenia = [];
        $bazowe = ['WYNIK_ZAKRESU' => 'success', 'KOD' => 'true', 'WIDOK' => 'true', 'DOKUMENTY' => 'true'];

        foreach ($this->przypadkiAgregatow() as [$job, $zmienna, $wynik, $pelny, $kod, $fraza]) {
            $skrypt = $this->skryptKroku($bloki[$job]);
            if ($skrypt === null) {
                return ["Agregat `{$job}` nie ma kroku `run: |` — przyrząd nie ma czego uruchomić."];
            }

            $podsumowanie = (string) tempnam(sys_get_temp_dir(), 'agregat');
            $proces = new Process(['bash', '-c', $skrypt], base_path(), $bazowe + [
                $zmienna => $wynik,
                'PELNY' => $pelny,
                'GITHUB_STEP_SUMMARY' => $podsumowanie,
            ]);
            $proces->run();
            $tresc = (string) file_get_contents($podsumowanie);
            @unlink($podsumowanie);

            $opis = "`{$job}` przy części={$wynik}, pelny=".($pelny === '' ? 'BRAK' : $pelny);
            if ($proces->getExitCode() !== $kod) {
                $naruszenia[] = "{$opis}: kod wyjścia ".$proces->getExitCode().", ma być {$kod}"
                    .($kod === 1 ? ' — fałszywa zieleń wymaganej kontroli.' : '.');
            }
            if ($fraza !== null && ! str_contains($tresc, $fraza)) {
                $naruszenia[] = "{$opis}: podsumowanie nie mówi „{$fraza}”.";
            }
        }

        return $naruszenia;
    }

    /**
     * [job, zmienna wyniku części, wynik części, pelny, oczekiwany kod, fraza w podsumowaniu]
     *
     * @return list<array{string, string, string, string, int, string|null}>
     */
    private function przypadkiAgregatow(): array
    {
        return [
            ['testy', 'WYNIK_TESTOW', 'success', 'true', 0, 'wszystkie części zielone'],
            ['testy', 'WYNIK_TESTOW', 'success', 'false', 0, 'draft — zakres skrócony'],
            ['testy', 'WYNIK_TESTOW', 'skipped', 'false', 1, null],
            ['testy', 'WYNIK_TESTOW', 'skipped', 'true', 1, null],
            ['testy', 'WYNIK_TESTOW', 'failure', 'false', 1, null],
            // Bez wyjścia `pelny` macierz nie ma kontroli negatywnych — nie zielone.
            ['testy', 'WYNIK_TESTOW', 'success', '', 1, null],
            ['panel_marki', 'WYNIK_PANELU', 'success', 'true', 0, 'obie części zielone'],
            ['panel_marki', 'WYNIK_PANELU', 'skipped', 'false', 0, 'draft — zakres skrócony'],
            ['panel_marki', 'WYNIK_PANELU', 'skipped', 'true', 1, null],
            ['panel_marki', 'WYNIK_PANELU', 'skipped', '', 1, null],
            ['panel_marki', 'WYNIK_PANELU', 'failure', 'false', 1, null],
        ];
    }

    private function skryptKroku(string $blok): ?string
    {
        if (preg_match('/^        run: \|\n((?:          .*\n|\n)+)/m', $blok."\n", $m) !== 1) {
            return null;
        }

        return implode("\n", array_map(
            static fn (string $w): string => substr($w, 10),
            explode("\n", rtrim($m[1])),
        ))."\n";
    }

    /**
     * @param  array<string, string>  $wyjscia
     */
    private function spelniony(string $warunek, array $wyjscia): ?bool
    {
        if (str_contains($warunek, '(')) {
            return null;
        }

        foreach (explode('||', $warunek) as $alternatywa) {
            $wszystkie = true;
            foreach (explode('&&', $alternatywa) as $skladnik) {
                if (preg_match("/^\\s*needs\\.zakres\\.outputs\\.([a-z]+) == '([a-z]+)'\\s*$/", $skladnik, $m) !== 1) {
                    return null;
                }
                $wszystkie = $wszystkie && (($wyjscia[$m[1]] ?? '') === $m[2]);
            }
            if ($wszystkie) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function wyjsciaBramki(string $skrypt, ?string $zdarzenie, ?string $draft): array
    {
        $plikSkryptu = (string) tempnam(sys_get_temp_dir(), 'zakres-skrypt');
        $lista = (string) tempnam(sys_get_temp_dir(), 'zakres-lista');
        $wyjscie = (string) tempnam(sys_get_temp_dir(), 'zakres-wyj');
        file_put_contents($plikSkryptu, $skrypt);
        file_put_contents($lista, implode("\n", self::ZMIANA_WSZYSTKIEGO)."\n");

        $proces = new Process(['bash', $plikSkryptu], base_path(), [
            'GITHUB_OUTPUT' => $wyjscie,
            'ZAKRES_LISTA_PLIK' => $lista,
            'ZDARZENIE' => $zdarzenie ?? false,
            'DRAFT' => $draft ?? false,
        ]);
        $proces->run();

        $wynik = [];
        foreach (explode("\n", (string) file_get_contents($wyjscie)) as $linia) {
            if (str_contains($linia, '=')) {
                [$k, $v] = explode('=', $linia, 2);
                $wynik[$k] = $v;
            }
        }
        @unlink($plikSkryptu);
        @unlink($lista);
        @unlink($wyjscie);

        $this->assertSame(0, $proces->getExitCode(), 'Skrypt bramki padł: '.$proces->getErrorOutput());

        return $wynik;
    }

    /** @return array<string, string> */
    private function blokiJobow(string $workflow): array
    {
        $poczatek = strpos($workflow, "\njobs:\n");
        if ($poczatek === false) {
            return [];
        }

        $wiersze = explode("\n", substr($workflow, $poczatek + 1));
        $bloki = [];
        $biezacy = null;
        foreach ($wiersze as $w) {
            if (preg_match('/^  ([a-z0-9_-]+):$/', $w, $m) === 1) {
                $biezacy = $m[1];
                $bloki[$biezacy] = '';
            }
            if ($biezacy !== null) {
                $bloki[$biezacy] .= $w."\n";
            }
        }

        return $bloki;
    }

    private function podmienWJobie(string $workflow, string $job, string $stary, string $nowy): string
    {
        $bloki = $this->blokiJobow($workflow);
        $this->assertArrayHasKey($job, $bloki, "Mutacja: brak joba `{$job}`.");
        $this->assertStringContainsString($stary, $bloki[$job], "Mutacja nie trafiła w job `{$job}`.");

        return str_replace($bloki[$job], str_replace($stary, $nowy, $bloki[$job]), $workflow);
    }

    private function podmien(string $tresc, string $stary, string $nowy): string
    {
        $this->assertSame(1, substr_count($tresc, $stary), "Mutacja nie trafiła dokładnie raz: {$stary}");

        return str_replace($stary, $nowy, $tresc);
    }

    private function tresc(string $sciezka): string
    {
        $tresc = file_get_contents(base_path($sciezka));
        $this->assertIsString($tresc, "Nie da się odczytać `{$sciezka}`.");

        return $tresc;
    }
}
