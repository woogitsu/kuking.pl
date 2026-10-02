<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * POMINIĘCIE TESTU DYMNEGO PREVIEW MA ZOSTAWIAĆ POWÓD (#611, etap 7).
 *
 * Do etapu 7 warunek `vars.KUKING_DEPLOY_ENABLED == 'true'` stał na jobie
 * `smoke`, więc przy nieustawionej (albo źle wpisanej) zmiennej GitHub pomijał
 * job bez śladu. Decyzję podejmuje teraz `scripts/ci/preview-bramka.sh`, a job
 * `preview_bramka` zapisuje powód w podsumowaniu. Tabelę przypadków i kontrolę
 * ujemną trzyma `tests/skrypty/preview-bramka.sh`; ten plik pilnuje połączenia:
 * workflow parsowany prawdziwym YAML-em (PyYAML) i składnia skryptów.
 *
 * @bez-kontroli-dodatniej Struktura workflow jest asertowana na sparsowanym YAML-u (liczby kroków, klucze needs/if/permissions), a kontrole ujemne skryptu robi tests/skrypty/preview-bramka.sh.
 */
#[Group('ci')]
class PreviewPominiecieMaUzasadnienieTest extends TestCase
{
    private const SKRYPT = 'scripts/ci/preview-bramka.sh';

    /** @return array<string, mixed> */
    private function joby(): array
    {
        $proces = new Process([
            'python3', '-c',
            'import sys, json, yaml; print(json.dumps(yaml.safe_load(open(sys.argv[1], encoding="utf-8"))["jobs"]))',
            base_path('.github/workflows/preview.yml'),
        ]);
        $proces->run();
        $this->assertSame(0, $proces->getExitCode(), 'yaml.safe_load nie przeczytał preview.yml: '.$proces->getErrorOutput());

        $joby = json_decode($proces->getOutput(), true);
        $this->assertIsArray($joby);

        return $joby;
    }

    public function test_tabela_przypadkow_i_kontrola_ujemna_przechodza(): void
    {
        $proces = new Process(['bash', 'tests/skrypty/preview-bramka.sh'], base_path());
        $proces->setTimeout(120);
        $proces->run();

        $this->assertSame(0, $proces->getExitCode(), $proces->getOutput().$proces->getErrorOutput());
        $this->assertStringContainsString('Bramka preview: OK', $proces->getOutput());
        // Kontrola dodatnia przyrządu: kontrola ujemna naprawdę poszła po regułach.
        // Osiem reguł: sześć z #611 etap 7 i dwie reguły draftu (2.10.2026, D-333).
        $this->assertSame(8, substr_count($proces->getOutput(), ': mutacja zapaliła tabelę'));
    }

    public function test_smoke_czeka_na_bramke_i_nie_ma_juz_zmiennej_w_warunku_joba(): void
    {
        $smoke = $this->joby()['smoke'];

        $this->assertSame('preview_bramka', $smoke['needs']);
        $this->assertStringContainsString("needs.preview_bramka.outputs.uruchom == 'true'", (string) $smoke['if']);
        // Warunek na zmiennej wróciłby do cichego pominięcia bez powodu.
        $this->assertStringNotContainsString('KUKING_DEPLOY_ENABLED', (string) $smoke['if']);
    }

    public function test_bramka_jest_lekka_bez_praw_zapisu_i_woła_skrypt_z_danymi_przez_env(): void
    {
        $bramka = $this->joby()['preview_bramka'];

        $this->assertSame(['contents' => 'read'], $bramka['permissions']);
        $this->assertArrayNotHasKey('needs', $bramka);
        $this->assertSame('scripts/ci', $bramka['steps'][0]['with']['sparse-checkout']);
        $this->assertCount(2, $bramka['steps']);

        $decyzja = $bramka['steps'][1];
        $this->assertSame('decyzja', $decyzja['id']);
        $this->assertSame('bash '.self::SKRYPT, trim((string) $decyzja['run']));
        $this->assertSame(['ZDARZENIE', 'FLAGA', 'DRAFT'], array_keys($decyzja['env']));
        $this->assertStringContainsString('vars.KUKING_DEPLOY_ENABLED', (string) $decyzja['env']['FLAGA']);
        // Draft PR -> test dymny pominięty z powodem (2.10.2026, D-333).
        $this->assertSame('${{ github.event.pull_request.draft }}', (string) $decyzja['env']['DRAFT']);
        $this->assertSame(
            ['uruchom', 'powod'],
            array_keys($bramka['outputs']),
        );
    }

    public function test_komentarz_w_pr_nadal_zalezy_tylko_od_smoke(): void
    {
        $komentarz = $this->joby()['smoke-komentarz'];

        $this->assertSame('smoke', $komentarz['needs']);
        $this->assertSame(['pull-requests' => 'write'], $komentarz['permissions']);
    }

    public function test_skrypty_maja_poprawna_skladnie_i_sa_w_kontrolach_powloki(): void
    {
        foreach ([self::SKRYPT, 'tests/skrypty/preview-bramka.sh'] as $plik) {
            $proces = new Process(['bash', '-n', $plik], base_path());
            $proces->run();
            $this->assertSame(0, $proces->getExitCode(), "bash -n {$plik}: ".$proces->getErrorOutput());
        }

        $powloka = (string) file_get_contents(base_path('scripts/kontrole-powloki.sh'));
        $this->assertMatchesRegularExpression('/^tests\/skrypty\/preview-bramka\.sh\|/m', $powloka, 'Test bramki preview wypadł z listy testów powłoki.');
    }

    public function test_pominiecie_na_prawdziwym_skrypcie_zostawia_powod_w_podsumowaniu(): void
    {
        $plik = tempnam(sys_get_temp_dir(), 'preview-sum');
        $this->assertIsString($plik);

        try {
            $proces = new Process(['bash', self::SKRYPT], base_path(), [
                'ZDARZENIE' => 'pull_request',
                'FLAGA' => '',
                'DRAFT' => false,
                'GITHUB_STEP_SUMMARY' => $plik,
            ]);
            $proces->run();

            $this->assertSame(0, $proces->getExitCode(), $proces->getOutput());
            $this->assertStringContainsString('pominięty', (string) file_get_contents($plik));
            $this->assertStringContainsString('KUKING_DEPLOY_ENABLED', (string) file_get_contents($plik));
        } finally {
            @unlink($plik);
        }
    }
}
