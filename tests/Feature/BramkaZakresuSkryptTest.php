<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * SKRYPT BRAMKI `zakres` (scripts/ci/zakres.sh) — WYNIESIONY Z `ci.yml` (#611, etap 5).
 *
 * Do etapu 5 bramka była ~11 tys. znaków powłoki w `run: |` i strażnicy
 * wyjmowali ją z YAML-a tekstem. Teraz to osobny plik:
 *
 *   - tabelę przypadków (lista plików -> siedem wyjść) i kontrolę ujemną na
 *     każdym wyjściu trzyma `tests/skrypty/zakres.sh` — tu jest ona uruchamiana
 *     w zestawie PHPUnit, obok `scripts/kontrole-powloki.sh` (check.sh i CI);
 *   - ścieżkę z PRAWDZIWĄ bazą porównania (diff między commitami) sprawdzamy
 *     tu, na tymczasowym repozytorium, bo test powłoki nie wywołuje gita;
 *   - krok w `ci.yml` ma wołać ten plik i dawać mu wejście (BAZA, ZDARZENIE,
 *     od 2.10.2026 także DRAFT — wyjście `pelny`, D-333).
 *
 * @bez-kontroli-dodatniej Uruchamia skrypt bramki i asertuje na jego wyjściu i kodzie wyjścia; kontrolę ujemną (mutacja każdego z siedmiu wyjść musi zapalić tabelę) robi sam przyrząd `tests/skrypty/zakres.sh`, a nie treść źródła aplikacji.
 */
#[Group('ci')]
class BramkaZakresuSkryptTest extends TestCase
{
    private const SKRYPT = 'scripts/ci/zakres.sh';

    public function test_tabela_przypadkow_i_kontrola_ujemna_na_kazdym_wyjsciu_przechodza(): void
    {
        $proces = new Process(['bash', 'tests/skrypty/zakres.sh'], base_path());
        // 240 s, nie 120: od 2.10.2026 tabela ma wiersze draftu i kontrolę
        // ujemną wyjścia `pelny` (ok. 1,7 raza więcej uruchomień skryptu);
        // na obciążonej maszynie lokalnej 120 s bywało za mało.
        $proces->setTimeout(240);
        $proces->run();

        $this->assertSame(0, $proces->getExitCode(), $proces->getOutput().$proces->getErrorOutput());
        $this->assertStringContainsString('Bramka zakres: OK', $proces->getOutput());

        // Kontrola dodatnia przyrządu: test powłoki naprawdę zrobił kontrolę
        // ujemną na każdym z siedmiu wyjść, a nie zakończył się po cichu.
        foreach (['kod', 'widok', 'dokumenty', 'obraz', 'obciazenie', 'wyscigi', 'pelny'] as $wyjscie) {
            $this->assertStringContainsString("wyjście {$wyjscie}: mutacja zapaliła tabelę", $proces->getOutput());
        }
    }

    public function test_z_prawdziwej_bazy_liczy_diff_miedzy_commitami(): void
    {
        $repo = sys_get_temp_dir().'/kuking-zakres-'.bin2hex(random_bytes(4));
        mkdir($repo.'/docs', 0777, true);
        mkdir($repo.'/app', 0777, true);

        try {
            file_put_contents($repo.'/docs/A.md', "a\n");
            $this->uruchom($repo, ['git', 'init', '-q']);
            $this->uruchom($repo, ['git', 'add', '-A']);
            $this->uruchom($repo, ['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'a']);
            $baza = trim($this->uruchom($repo, ['git', 'rev-parse', 'HEAD']));

            // Sama dokumentacja: kod=false, dokumenty=true.
            file_put_contents($repo.'/docs/A.md', "b\n");
            $this->uruchom($repo, ['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-am', 'b']);
            $this->assertEquals(
                ['kod' => 'false', 'widok' => 'false', 'dokumenty' => 'true', 'obraz' => 'false', 'obciazenie' => 'false', 'wyscigi' => 'false', 'pelny' => 'true'],
                $this->wyjscia($repo, $baza, 'pull_request'),
            );

            // Pusty diff (baza = HEAD): same false.
            $this->assertEquals(
                ['kod' => 'false', 'widok' => 'false', 'dokumenty' => 'false', 'obraz' => 'false', 'obciazenie' => 'false', 'wyscigi' => 'false', 'pelny' => 'true'],
                $this->wyjscia($repo, trim($this->uruchom($repo, ['git', 'rev-parse', 'HEAD'])), 'pull_request'),
            );

            // Dokładamy plik aplikacji: kod=true, widok=true, wyscigi=true.
            file_put_contents($repo.'/app/X.php', "<?php\n");
            $this->uruchom($repo, ['git', 'add', '-A']);
            $this->uruchom($repo, ['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'c']);
            $this->assertEquals(
                ['kod' => 'true', 'widok' => 'true', 'dokumenty' => 'true', 'obraz' => 'false', 'obciazenie' => 'false', 'wyscigi' => 'true', 'pelny' => 'true'],
                $this->wyjscia($repo, $baza, 'pull_request'),
            );

            // Ten sam diff na DRAFT PR-ze (2.10.2026, D-333): sześć wyjść
            // obszarów bez zmian, `pelny=false`. Na pushu DRAFT nie działa.
            $this->assertEquals(
                ['kod' => 'true', 'widok' => 'true', 'dokumenty' => 'true', 'obraz' => 'false', 'obciazenie' => 'false', 'wyscigi' => 'true', 'pelny' => 'false'],
                $this->wyjscia($repo, $baza, 'pull_request', 'true'),
            );
            $this->assertSame('true', $this->wyjscia($repo, $baza, 'push', 'true')['pelny']);
            $this->assertSame('true', $this->wyjscia($repo, $baza, 'pull_request', 'false')['pelny']);
        } finally {
            (new Process(['rm', '-rf', $repo]))->run();
        }
    }

    public function test_tymczasowa_baza_nie_zmienia_worktree_wolajacego_przy_odziedziczonym_git_dir(): void
    {
        $tmp = sys_get_temp_dir().'/kuking-zakres-caller-'.bin2hex(random_bytes(4));
        $caller = $tmp.'/caller';
        $linked = $tmp.'/linked';
        $fixture = $tmp.'/fixture';
        $bareFixture = $tmp.'/bare-fixture';
        mkdir($caller, 0777, true);
        mkdir($fixture, 0777, true);
        mkdir($bareFixture, 0777, true);

        try {
            $this->uruchom($caller, ['git', 'init', '-q']);
            file_put_contents($caller.'/README', "caller\n");
            $this->uruchom($caller, ['git', 'add', 'README']);
            $this->uruchom($caller, ['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'caller']);
            $this->uruchom($caller, ['git', 'config', 'extensions.worktreeConfig', 'true']);
            $this->uruchom($caller, ['git', 'worktree', 'add', '-qb', 'test', $linked]);
            $this->uruchom($linked, ['git', 'config', '--worktree', 'test.canary', 'zostaje']);
            $gitDir = trim($this->uruchom($linked, ['git', 'rev-parse', '--absolute-git-dir']));
            $hook = $gitDir.'/hooks/pre-push';
            if (! is_dir(dirname($hook))) {
                mkdir(dirname($hook), 0777, true);
            }
            file_put_contents($hook, "obcy hook\n");
            $konfiguracja = $caller.'/.git/config';
            $konfiguracjaWorktree = $gitDir.'/config.worktree';
            $przed = [
                'config' => file_get_contents($konfiguracja),
                'worktree' => file_get_contents($konfiguracjaWorktree),
                'hook' => file_get_contents($hook),
                'head' => trim($this->uruchom($linked, ['git', 'rev-parse', 'HEAD'])),
                'status' => $this->uruchom($linked, ['git', 'status', '--porcelain', '--untracked-files=all']),
            ];

            $odziedziczone = [
                'GIT_DIR' => $gitDir,
                'GIT_CONFIG_PARAMETERS' => "'test.fixture=true'",
            ];
            $this->uruchom($bareFixture, ['git', 'init', '-q', '--bare'], $odziedziczone);
            $this->assertSame($przed['config'], file_get_contents($konfiguracja), 'ZAKRES_2871_WOLAJACY_CONFIG_ZMIENIONY');
            $odziedziczone['GIT_WORK_TREE'] = $linked;
            $this->uruchom($fixture, ['git', 'init', '-q'], $odziedziczone);
            $this->assertSame('true', trim($this->uruchom($fixture, ['git', 'rev-parse', '--is-inside-work-tree'], $odziedziczone)));
            $this->assertSame('false', trim($this->uruchom($fixture, ['git', 'rev-parse', '--is-bare-repository'], $odziedziczone)));

            $this->assertSame($przed['config'], file_get_contents($konfiguracja), 'ZAKRES_2871_WOLAJACY_CONFIG_ZMIENIONY');
            $this->assertSame($przed['worktree'], file_get_contents($konfiguracjaWorktree), 'ZAKRES_2871_WOLAJACY_WORKTREE_ZMIENIONY');
            $this->assertSame($przed['hook'], file_get_contents($hook), 'ZAKRES_2871_WOLAJACY_HOOK_ZMIENIONY');
            $this->assertSame($przed['head'], trim($this->uruchom($linked, ['git', 'rev-parse', 'HEAD'])), 'ZAKRES_2871_WOLAJACY_HEAD_ZMIENIONY');
            $this->assertSame($przed['status'], $this->uruchom($linked, ['git', 'status', '--porcelain', '--untracked-files=all']), 'ZAKRES_2871_WOLAJACY_STATUS_ZMIENIONY');
        } finally {
            (new Process(['rm', '-rf', $tmp]))->run();
        }
    }

    public function test_krok_w_ci_woła_skrypt_i_daje_mu_baze_oraz_zdarzenie(): void
    {
        $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertSame(1, preg_match('/^      - name: Czy zmiana dotyka czegoś poza dokumentacją\n(.*?)(?=^      - |^  [a-z_]+:)/ms', $ci, $krok));
        $this->assertStringContainsString('id: sprawdz', $krok[1]);
        $this->assertStringContainsString('run: bash '.self::SKRYPT, $krok[1]);
        $this->assertMatchesRegularExpression('/^          BAZA: /m', $krok[1]);
        $this->assertMatchesRegularExpression('/^          ZDARZENIE: \$\{\{ github\.event_name \}\}$/m', $krok[1]);
        $this->assertMatchesRegularExpression('/^          DRAFT: \$\{\{ github\.event\.pull_request\.draft \}\}$/m', $krok[1]);
        $this->assertStringContainsString('pelny: ${{ steps.sprawdz.outputs.pelny }}', $ci, 'Job `zakres` nie wystawia wyjścia `pelny`.');
        // Logika nie wraca do YAML-a: krok nie liczy niczego samodzielnie.
        $this->assertStringNotContainsString('GITHUB_OUTPUT', $krok[1]);
        $this->assertFileExists(base_path(self::SKRYPT));
    }

    public function test_skrypt_jest_pod_kontrola_skladni_i_zmiana_go_mierzy_wszystko(): void
    {
        $powloka = (string) file_get_contents(base_path('scripts/kontrole-powloki.sh'));
        $this->assertStringContainsString('scripts/ci/*.sh', $powloka, 'Składnia scripts/ci/*.sh nie jest sprawdzana.');
        $this->assertMatchesRegularExpression('/^tests\/skrypty\/zakres\.sh\|/m', $powloka, 'Test bramki wypadł z listy testów powłoki.');
    }

    /**
     * @param  list<string>  $polecenie
     * @param  array<string, string>  $odziedziczone
     */
    private function uruchom(string $katalog, array $polecenie, array $odziedziczone = []): string
    {
        $proces = new Process($polecenie, $katalog, $this->bezOdziedziczonegoGita($odziedziczone));
        $proces->run();
        $this->assertSame(0, $proces->getExitCode(), implode(' ', $polecenie).': '.$proces->getErrorOutput());

        return $proces->getOutput();
    }

    /** @return array<string, string> */
    private function wyjscia(string $repo, string $baza, string $zdarzenie, ?string $draft = null): array
    {
        $wyjscie = tempnam(sys_get_temp_dir(), 'zakres-wyj');
        $this->assertIsString($wyjscie);

        $proces = new Process(['bash', base_path(self::SKRYPT)], $repo, $this->bezOdziedziczonegoGita() + [
            'BAZA' => $baza,
            'ZDARZENIE' => $zdarzenie,
            'DRAFT' => $draft ?? false,
            'GITHUB_OUTPUT' => $wyjscie,
            // Odziedziczony tryb testowy zasłoniłby prawdziwy diff.
            'ZAKRES_LISTA_PLIK' => false,
        ]);
        $proces->run();
        $this->assertSame(0, $proces->getExitCode(), $proces->getErrorOutput());

        $wynik = [];
        foreach (explode("\n", (string) file_get_contents($wyjscie)) as $linia) {
            if (str_contains($linia, '=')) {
                [$k, $v] = explode('=', $linia, 2);
                $wynik[$k] = $v;
            }
        }
        @unlink($wyjscie);

        $this->assertCount(7, $wynik, 'Skrypt ma wystawić dokładnie siedem wyjść.');

        return $wynik;
    }

    /**
     * @param  array<string, string>  $dodatkowe
     * @return array<string, string|false>
     */
    private function bezOdziedziczonegoGita(array $dodatkowe = []): array
    {
        // Pre-push ustawia GIT_DIR/GIT_WORK_TREE. Bez wyczyszczenia potomne
        // `git init` może zmienić core.bare wywołującego worktree zamiast fixture.
        $srodowisko = $dodatkowe;
        foreach (getenv() as $nazwa => $_) {
            if (str_starts_with($nazwa, 'GIT_')) {
                $srodowisko[$nazwa] = false;
            }
        }
        foreach ($dodatkowe as $nazwa => $_) {
            if (str_starts_with($nazwa, 'GIT_')) {
                $srodowisko[$nazwa] = false;
            }
        }

        return $srodowisko;
    }
}
