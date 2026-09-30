<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PR z forka nie trafia na własne runnery właściciela (#2298, IN-06).
 *
 * Repozytorium jest PUBLICZNE, celowo (decyzja właściciela z 30.09.2026).
 * `runs-on:` jobów czyta zmienne repozytorium (`CI_RUNS_ON`, D-121), więc
 * jedno ustawienie w panelu wysłałoby joby KAŻDEGO Pull Requesta — także
 * z cudzego forka — na maszyny `woogitsu-linux-*`. To powłoka dla obcej osoby
 * na tych samych maszynach, na których chodzą joby z dostępem do produkcji.
 *
 * Reguły:
 *  1. Workflow uruchamiany przez `pull_request` ma w KAŻDYM jobie `runs-on:`,
 *     które zaczyna się od warunku na forka (poniżej, `STRAZ_FORKA`) albo jest
 *     dosłownym runnerem GitHuba (`ubuntu-…`). Zmienna bez straży = porażka.
 *  2. `pull_request_target` jest zabroniony: uruchamia kod w kontekście bazy
 *     z sekretami, a warunek z reguły 1 go nie obejmuje.
 *  3. Workflow uruchamiany przez `workflow_run` (bramka deployu) ma w jobie
 *     czytającym zmienną warunek `head_repository.full_name == github.repository`.
 *  4. Komentarze workflowów nie twierdzą już, że repozytorium jest prywatne
 *     (#2302, IN-10) — z takiego zdania wynikały decyzje kosztowe
 *     i instrukcja „ustaw CI_RUNS_ON", bezpieczna tylko dla repo prywatnego.
 *
 * Skan liczy joby i porównuje liczbę odczytanych `runs-on:` z niezależnym
 * zliczeniem linii (docs/PULAPKI_TESTOW.md §2 i §2b) — zły wzorzec nie może
 * dać zieleni na pustym zbiorze.
 */
final class WorkflowyNieWpuszczajaForkowNaWlasneRunneryTest extends TestCase
{
    public const STRAZ_FORKA = "\${{ fromJSON((github.event_name == 'pull_request' && github.event.pull_request.head.repo.full_name != github.repository && '\"ubuntu-latest\"') || ";

    private const MIN_WORKFLOWOW_PR = 3;

    private const MIN_JOBOW_PR = 20;

    /** @return array<string, list<string>> ścieżka => linie */
    private function workflowy(): array
    {
        $pliki = glob(base_path('.github/workflows/*.yml')) ?: [];
        $this->assertGreaterThanOrEqual(5, count($pliki), 'Skan nie widzi plików .github/workflows/*.yml — zła ścieżka?');

        $wynik = [];
        foreach ($pliki as $plik) {
            $wynik[str_replace(base_path().'/', '', $plik)] = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($plik)));
        }

        return $wynik;
    }

    /** @param  list<string>  $linie
     * @return list<string> */
    private function wyzwalacze(array $linie): array
    {
        $wBloku = false;
        $wyzwalacze = [];

        foreach ($linie as $linia) {
            if (! $wBloku) {
                $wBloku = preg_match('/^on:\s*$/', $linia) === 1;

                continue;
            }

            if ($linia !== '' && preg_match('/^\s/', $linia) !== 1) {
                break;
            }

            if (preg_match('/^  ([a-z_]+):/', $linia, $m) === 1) {
                $wyzwalacze[] = $m[1];
            }
        }

        return $wyzwalacze;
    }

    /**
     * @param  list<string>  $linie
     * @return array<string, array{runs_on: ?string, blok: string}>
     */
    private function joby(array $linie): array
    {
        $joby = [];
        $wJobach = false;
        $job = null;

        foreach ($linie as $linia) {
            if (preg_match('/^\s*#/', $linia) === 1) {
                continue;
            }

            if (! $wJobach) {
                $wJobach = preg_match('/^jobs:\s*$/', $linia) === 1;

                continue;
            }

            if ($linia !== '' && preg_match('/^\S/', $linia) === 1) {
                break;
            }

            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $linia, $m) === 1) {
                $job = $m[1];
                $joby[$job] = ['runs_on' => null, 'blok' => ''];

                continue;
            }

            if ($job === null) {
                continue;
            }

            $joby[$job]['blok'] .= $linia."\n";

            if (preg_match('/^    runs-on:\s*(\S.*?)\s*$/', $linia, $m) === 1) {
                $joby[$job]['runs_on'] = $m[1];
            }
        }

        return $joby;
    }

    /** @param  list<string>  $linie */
    private function liczbaLiniiRunsOn(array $linie): int
    {
        return count(array_filter($linie, fn (string $l): bool => preg_match('/^\s+runs-on:/', $l) === 1));
    }

    private static function czytaZmiennaAlboWlasnyRunner(string $runsOn): bool
    {
        return str_contains($runsOn, 'vars.') || str_contains($runsOn, 'self-hosted');
    }

    public function test_joby_z_pull_request_nie_ida_z_forka_na_wlasne_runnery(): void
    {
        $workflowyPr = 0;
        $jobyPr = 0;
        $naruszenia = [];

        foreach ($this->workflowy() as $plik => $linie) {
            if (! in_array('pull_request', $this->wyzwalacze($linie), true)) {
                continue;
            }

            $workflowyPr++;
            $joby = $this->joby($linie);

            $this->assertSame(
                $this->liczbaLiniiRunsOn($linie),
                count(array_filter($joby, fn (array $j): bool => $j['runs_on'] !== null)),
                $plik.': liczba odczytanych runs-on różni się od liczby linii runs-on — odczyt jobów w teście jest zepsuty.',
            );

            foreach ($joby as $nazwa => $job) {
                $runsOn = $job['runs_on'];

                if ($runsOn === null) {
                    $naruszenia[] = $plik.' → '.$nazwa.': job bez runs-on (reusable workflow?) — dopisz ten przypadek do strażnika świadomie.';

                    continue;
                }

                $jobyPr++;

                if (preg_match('/^ubuntu-[a-z0-9.-]+$/', $runsOn) === 1) {
                    continue;
                }

                if (! str_starts_with($runsOn, self::STRAZ_FORKA) && self::czytaZmiennaAlboWlasnyRunner($runsOn)) {
                    $naruszenia[] = $plik.' → '.$nazwa.': `runs-on: '.$runsOn.'`';
                }
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_WORKFLOWOW_PR, $workflowyPr, 'Skan widzi za mało workflowów z pull_request — zepsuty odczyt bloku on:?');
        $this->assertGreaterThanOrEqual(self::MIN_JOBOW_PR, $jobyPr, 'Skan widzi za mało jobów w workflowach z pull_request — zepsuty odczyt jobów?');

        $this->assertSame(
            [],
            $naruszenia,
            "Job uruchamiany przez pull_request może trafić z forka na własny runner (#2298). Każde runs-on czytające zmienną ma zaczynać się od:\n  "
            .self::STRAZ_FORKA."…\nNaruszenia:\n  ".implode("\n  ", $naruszenia),
        );
    }

    public function test_zaden_workflow_nie_uzywa_pull_request_target(): void
    {
        $zPrTarget = [];

        foreach ($this->workflowy() as $plik => $linie) {
            if (in_array('pull_request_target', $this->wyzwalacze($linie), true)) {
                $zPrTarget[] = $plik;
            }
        }

        $this->assertSame([], $zPrTarget, 'pull_request_target uruchamia joby z sekretami dla PR-ów z forków, a straż forka w runs-on go nie obejmuje (#2298).');
    }

    public function test_workflow_run_sprawdza_repozytorium_zrodlowe_przed_wlasnym_runnerem(): void
    {
        $sprawdzone = 0;
        $naruszenia = [];

        foreach ($this->workflowy() as $plik => $linie) {
            if (! in_array('workflow_run', $this->wyzwalacze($linie), true)) {
                continue;
            }

            foreach ($this->joby($linie) as $nazwa => $job) {
                if ($job['runs_on'] === null || ! self::czytaZmiennaAlboWlasnyRunner($job['runs_on'])) {
                    continue;
                }

                $sprawdzone++;

                if (! str_contains($job['blok'], 'github.event.workflow_run.head_repository.full_name == github.repository')) {
                    $naruszenia[] = $plik.' → '.$nazwa;
                }
            }
        }

        $this->assertGreaterThanOrEqual(1, $sprawdzone, 'Skan nie widzi joba bramki deployu (workflow_run) — zepsuty odczyt?');
        $this->assertSame([], $naruszenia, 'Job z workflow_run na własnym runnerze bez warunku head_repository.full_name == github.repository (#2298): '.implode(', ', $naruszenia));
    }

    public function test_komentarze_workflowow_nie_mowia_ze_repozytorium_jest_prywatne(): void
    {
        $znalezione = [];

        foreach ($this->workflowy() as $plik => $linie) {
            $komentarze = array_map(
                fn (string $l): string => (string) preg_replace('/^\s*#\s?/', '', $l),
                array_filter($linie, fn (string $l): bool => preg_match('/^\s*#/', $l) === 1),
            );
            $tekst = mb_strtolower((string) preg_replace('/\s+/u', ' ', implode(' ', $komentarze)));

            foreach (['repozytorium jest prywatne', 'prywatne repozytorium', 'pulę 2 000 minut', 'pulę 2000 minut'] as $zdanie) {
                if (str_contains($tekst, mb_strtolower($zdanie))) {
                    $znalezione[] = $plik.': „'.$zdanie.'”';
                }
            }
        }

        $this->assertSame(
            [],
            $znalezione,
            'Komentarz workflowu mówi, że repozytorium jest prywatne albo ma pulę 2 000 minut — od 30.09.2026 jest publiczne (#2302, IN-10): '.implode('; ', $znalezione),
        );
    }
}
