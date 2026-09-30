<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Joby przeglądarkowe budują sam Vite, bo testy JS z `npm run build` biegną
 * w jobie `assets` — #2299.
 *
 * `npm run build` to kontrast marki, ok. 190 testów jednostkowych JS
 * (ok. 78 s na runnerze GitHuba) i dopiero na końcu `vite build` (ok. 1 s).
 * Pięć jobów przeglądarkowych potrzebuje tylko arkusza, a powtarzało całość —
 * ok. 1,3 min na ścieżce krytycznej każdego z nich. Teraz robią
 * `npm run build:assets` (sam Vite). To wolno TYLKO dlatego, że:
 *   1. `build:assets` to dokładnie `vite build`, a `build` kończy się tym
 *      samym `vite build` — arkusz jest ten sam;
 *   2. job `assets` nadal robi pełne `npm run build`, bez `continue-on-error`,
 *      i rusza przy samym `kod == 'true'`;
 *   3. każdy job z `build:assets` też wymaga `kod == 'true'`, więc nie ma
 *      przebiegu, w którym arkusz się buduje, a testy JS nie biegną.
 */
class TestyJsBiegnaWJobieAssetowTest extends TestCase
{
    public function test_build_assets_to_ten_sam_vite_co_koniec_pelnego_buildu(): void
    {
        $skrypty = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR)['scripts'];

        $this->assertSame('vite build', $skrypty['build:assets'] ?? null, '`build:assets` ma być samym `vite build` (#2299).');
        $this->assertStringEndsWith(' && vite build', $skrypty['build'], 'Pełny `build` ma kończyć się tym samym `vite build`.');
        $this->assertTrue(str_contains($skrypty['build'], 'node --test '), 'Pełny `build` przestał uruchamiać testy JS — job `assets` nic by nie sprawdzał.');
    }

    public function test_job_assets_robi_pelny_build_przy_kazdej_zmianie_kodu(): void
    {
        // Od scalenia krótkich jobów (#2299) pełny build robi `kontrole_krotkie`.
        $job = $this->job('kontrole_krotkie');

        $this->assertMatchesRegularExpression('/^        run: npm run build\s*$/m', $job, 'Job `kontrole_krotkie` nie robi pełnego `npm run build` — testy JS nie biegną nigdzie w CI (#2299).');
        $this->assertMatchesRegularExpression("/^    if: needs\\.zakres\\.outputs\\.kod == 'true'\\s*$/m", $job, 'Job `kontrole_krotkie` ma węższy warunek niż joby z samym Vite (#2299).');
        $this->assertMatchesRegularExpression('/^    needs: zakres\s*$/m', $job);
        $this->assertDoesNotMatchRegularExpression('/^    continue-on-error/m', $job);
        $this->assertStringNotContainsString('build:assets', $job);
    }

    public function test_job_z_samym_vite_rusza_tylko_gdy_rusza_job_assets(): void
    {
        $zSamymVite = [];
        foreach ($this->joby() as $nazwa => $job) {
            if (! str_contains($job, 'npm run build:assets')) {
                continue;
            }
            $zSamymVite[] = $nazwa;
            $this->assertMatchesRegularExpression(
                "/^    if: needs\\.zakres\\.outputs\\.kod == 'true'( && .+)?\\s*$/m",
                $job,
                "Job `{$nazwa}` buduje sam Vite, ale nie wymaga `kod == 'true'` — mógłby ruszyć bez joba `assets`, czyli bez testów JS (#2299).",
            );
        }

        $this->assertNotEmpty($zSamymVite, 'Żaden job nie używa `build:assets` — test nie ma czego pilnować.');
    }

    /** @return array<string, string> */
    private function joby(): array
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        preg_match_all('/^  ([a-z_0-9-]+):(?:\r\n|\n|\r)(.*?)(?=^  [a-z_0-9-]+:|\z)/ms', substr($workflow, (int) strpos($workflow, "\njobs:\n")), $m, PREG_SET_ORDER);
        $joby = [];
        foreach ($m as $dopasowanie) {
            $joby[$dopasowanie[1]] = (string) preg_replace('/^\s*#.*$/m', '', $dopasowanie[2]);
        }

        return $joby;
    }

    private function job(string $name): string
    {
        $joby = $this->joby();
        $this->assertArrayHasKey($name, $joby, 'Brak sprawdzanego joba CI: '.$name);

        return $joby[$name];
    }
}
