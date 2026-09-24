<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Bramka wpisu w CHANGELOG — czy stoi w CI i czy naprawdę odróżnia zieleń od czerwieni.
 *
 * DWA STRAŻNIKI TEJ SAMEJ REGUŁY („Wersja i CHANGELOG" w AGENTS.md):
 *
 *   (a) `PodbicieWersjiWymagaWpisuWChangelogTest` — niezmiennik stanu drzewa,
 *       bez gita: CHANGELOG zaczyna się sekcją `## Nieopublikowane`, a pierwszy
 *       nagłówek wersji pod nią to aktualna `wersja.etykieta`.
 *
 *   (b) `scripts/bramka-wersji.sh` — pytanie o RÓŻNICĘ: zakres, który rusza
 *       warstwę widoczną dla człowieka, dopisuje linię do „Nieopublikowane"
 *       (albo jest wydaniem, albo niesie furtkę `Bez-podbicia-wersji:`).
 *
 * Żaden nie łapie tego, co drugi: (a) jest zielony przy zmianie widoku bez
 * wpisu, (b) przy CHANGELOG-u, któremu ktoś skasował sekcję poza zakresem.
 *
 * JAK TEN TEST SPRAWDZA SKRYPT. Nie czyta jego treści, tylko go URUCHAMIA:
 * kopiuje `scripts/bramka-wersji.sh` do świeżego repozytorium w katalogu
 * tymczasowym, robi tam commity i patrzy na kod wyjścia. Każdy przypadek
 * czerwony ma bliźniaka zielonego, różniącego się jedną rzeczą — inaczej
 * czerwień mogłaby brać się z czegokolwiek innego niż reguła.
 */
class BramkaPodbiciaWersjiTest extends TestCase
{
    private string $repo;

    private const CHANGELOG = "# Co się zmieniło w Kuking\n\n"
        ."## Nieopublikowane\n\n> Tu trafia wpis.\n\n"
        ."## Alfa 0.68 — minutnik\n\n- Stary wpis.\n";

    private const CONFIG = "<?php\n\nreturn [\n    'wersja' => [\n        'etykieta' => 'Alfa 0.68',\n    ],\n];\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/kuking-bramka-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->repo.'/scripts');
        File::copy(base_path('scripts/bramka-wersji.sh'), $this->repo.'/scripts/bramka-wersji.sh');

        $this->git('init', '-q', '-b', 'main');
        $this->zapisz('CHANGELOG.md', self::CHANGELOG);
        $this->zapisz('config/kuking.php', self::CONFIG);
        $this->zapisz('resources/views/strona.blade.php', "<p>Stara</p>\n");
        $this->zapisz('lang/pl.json', "{}\n");
        $this->commit('baza');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    public function test_zmiana_widoku_bez_wpisu_oblewa(): void
    {
        $this->zapisz('resources/views/strona.blade.php', "<p>Nowa</p>\n");
        $this->commit('zmiana widoku');

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(1, $kod, $wyjscie);
        $this->assertStringContainsString('resources/views/strona.blade.php', $wyjscie);
    }

    public function test_zmiana_widoku_z_wpisem_w_nieopublikowanych_przechodzi(): void
    {
        $this->zapisz('resources/views/strona.blade.php', "<p>Nowa</p>\n");
        $this->zapisz('CHANGELOG.md', str_replace(
            "> Tu trafia wpis.\n",
            "> Tu trafia wpis.\n\n- Strona mówi „Nowa”.\n",
            self::CHANGELOG,
        ));
        $this->commit('zmiana widoku z wpisem');

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(0, $kod, $wyjscie);
        $this->assertStringContainsString('Strona mówi „Nowa”', $wyjscie);
    }

    public function test_wpis_dopisany_pod_stara_wersja_nie_jest_wpisem_do_nieopublikowanych(): void
    {
        $this->zapisz('resources/views/strona.blade.php', "<p>Nowa</p>\n");
        $this->zapisz('CHANGELOG.md', self::CHANGELOG."- Doklejone do wydanej wersji.\n");
        $this->commit('wpis w złym miejscu');

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(1, $kod, $wyjscie);
    }

    public function test_tekst_interfejsu_w_lang_to_zmiana_widoczna(): void
    {
        $this->zapisz('lang/pl.json', "{\"Zapisz\": \"Zachowaj\"}\n");
        $this->commit('zmiana tekstu');

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(1, $kod, $wyjscie);
        $this->assertStringContainsString('lang/pl.json', $wyjscie);
    }

    public function test_zmiana_poza_warstwa_widoczna_przechodzi_bez_wpisu(): void
    {
        $this->zapisz('app/Cos.php', "<?php\n");
        $this->zapisz('resources/js/kalkulator.test.mjs', "// test\n");
        $this->commit('zmiana techniczna');

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(0, $kod, $wyjscie);
    }

    public function test_furtka_w_opisie_pr_przepuszcza_tylko_z_powodem(): void
    {
        $this->zapisz('resources/views/strona.blade.php', "<p>Stara</p>\n<!-- komentarz -->\n");
        $this->commit('komentarz w widoku');

        [$bezPowodu] = $this->bramka("Opis.\n\nBez-podbicia-wersji:\n");
        [$zPowodem, $wyjscie] = $this->bramka("Opis.\r\n\r\nBez-podbicia-wersji: sam komentarz w Blade\r\n");

        $this->assertSame(1, $bezPowodu, 'Furtka bez powodu nie może przepuszczać.');
        $this->assertSame(0, $zPowodem, $wyjscie);
    }

    public function test_furtka_w_commicie_przepuszcza(): void
    {
        $this->zapisz('resources/views/strona.blade.php', "<p>Stara</p>\n<!-- komentarz -->\n");
        $this->commit("komentarz w widoku\n\nBez-podbicia-wersji: sam komentarz w Blade");

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(0, $kod, $wyjscie);
    }

    /**
     * Cytat reguły to nie decyzja. Opis PR-a, który przytacza AGENTS.md
     * (`> Bez-podbicia-wersji: …`), pokazuje przykład we wcięciu albo w bloku
     * kodu, nie może otwierać furtki. Bliźniak zielony — ta sama linia od
     * pierwszej kolumny — dowodzi, że czerwień bierze się z miejsca linii.
     */
    public function test_furtka_w_cytacie_ani_w_bloku_kodu_nie_przepuszcza(): void
    {
        $this->zapisz('resources/views/strona.blade.php', "<p>Stara</p>\n<!-- komentarz -->\n");
        $this->commit('komentarz w widoku');

        $linia = 'Bez-podbicia-wersji: sam komentarz w Blade';

        foreach ([
            'cytat' => "Reguła mówi:\n\n> {$linia}\n",
            'wcięcie' => "Przykład:\n\n    {$linia}\n",
            'blok kodu' => "Przykład:\n\n```\n{$linia}\n```\n",
            'blok kodu ~~~' => "Przykład:\n\n~~~text\n{$linia}\n~~~\n",
        ] as $gdzie => $opis) {
            [$kod, $wyjscie] = $this->bramka($opis);
            $this->assertSame(1, $kod, "Furtka w miejscu „{$gdzie}” przepuściła:\n{$wyjscie}");
        }

        [$kod, $wyjscie] = $this->bramka("Przykład:\n\n```\nkod\n```\n\n{$linia}\n");
        $this->assertSame(0, $kod, 'Furtka od pierwszej kolumny, za zamkniętym blokiem, ma przepuszczać: '.$wyjscie);
    }

    public function test_wydanie_z_nowym_naglowkiem_przechodzi(): void
    {
        $this->zapisz('resources/views/strona.blade.php', "<p>Nowa</p>\n");
        $this->zapisz('config/kuking.php', str_replace('Alfa 0.68', 'Alfa 0.69', self::CONFIG));
        $this->zapisz('CHANGELOG.md', str_replace(
            '## Alfa 0.68',
            "## Alfa 0.69 — wydanie\n\n- Strona mówi „Nowa”.\n\n## Alfa 0.68",
            self::CHANGELOG,
        ));
        $this->commit('wydanie 0.69');

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(0, $kod, $wyjscie);
    }

    public function test_bramka_jest_podpieta_w_ci_i_naprawde_blokuje(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $matched = preg_match('/^  bramka_wersji:\R(.*?)(?=^  [a-z_]+:|\z)/ms', $workflow, $matches);
        $this->assertSame(1, $matched,
            'Zniknął job CI `bramka_wersji` — reguła CHANGELOG-u znowu jest samym komentarzem.');

        $job = (string) $matches[1];

        $this->assertStringContainsString('bash scripts/bramka-wersji.sh', $job,
            'Job istnieje, ale nie woła bramki.');
        $this->assertStringContainsString('KUKING_OPIS_PR:', $job,
            'Bramka nie dostaje opisu PR-a, więc furtka w opisie przestała działać.');

        // `continue-on-error` zrobiłoby z bramki ozdobę: czerwień widoczna,
        // a nic nie blokuje — czyli stan sprzed niej.
        $this->assertStringNotContainsString('continue-on-error:', $job);

        $this->assertStringContainsString('fetch-depth: 0', $job,
            'Bez pełnej historii bramka nie zobaczy ani zakresu, ani furtki w commitach.');
    }

    /**
     * Bramka NIE chodzi przy pushu do main. Przy pushu nie ma opisu PR-a,
     * a merge commit niesie sam tytuł — PR zielony dzięki furtce w opisie
     * oblewałby po scaleniu, a Railway („Wait for CI") wstrzymywałby
     * produkcję. Warunek `if:` joba ma więc wymagać zdarzenia `pull_request`
     * i nie mieć alternatywy (`||`), która wpuściłaby push tylnymi drzwiami.
     */
    public function test_bramka_nie_chodzi_przy_pushu_do_main(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertSame(1, preg_match('/^  bramka_wersji:\R(.*?)(?=^  [a-z_]+:|\z)/ms', $workflow, $matches));
        $this->assertSame(1, preg_match('/^    if:[ \t]*(.+)$/m', (string) $matches[1], $warunek),
            'Job `bramka_wersji` nie ma warunku `if:` — chodzi przy każdym zdarzeniu, także przy pushu do main.');

        $warunek = trim($warunek[1]);

        $this->assertStringStartsWith("github.event_name == 'pull_request' && ", $warunek,
            "Job `bramka_wersji` ma chodzić tylko na PR-ze, a warunek brzmi: {$warunek}");
        $this->assertStringNotContainsString('||', $warunek,
            "Alternatywa w warunku może wpuścić push do main: {$warunek}");
    }

    /** @return array{0: int, 1: string} */
    private function bramka(string $opisPr = ''): array
    {
        $proces = new Process(
            ['bash', 'scripts/bramka-wersji.sh', 'HEAD~1', 'HEAD'],
            $this->repo,
            ['KUKING_OPIS_PR' => $opisPr],
        );
        $proces->run();

        return [(int) $proces->getExitCode(), $proces->getOutput().$proces->getErrorOutput()];
    }

    private function zapisz(string $sciezka, string $tresc): void
    {
        File::ensureDirectoryExists(dirname($this->repo.'/'.$sciezka));
        File::put($this->repo.'/'.$sciezka, $tresc);
    }

    private function commit(string $komunikat): void
    {
        $this->git('add', '-A');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@kuking.invalid',
            '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '-q', '-m', $komunikat);
    }

    private function git(string ...$argumenty): void
    {
        $proces = new Process(['git', ...$argumenty], $this->repo);
        $proces->mustRun();
    }
}
