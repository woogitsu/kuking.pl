<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1313 — `railway-iac.yml` liczy plan i stosuje apply na PRODUKCJI
 * wyłącznie dla PR-a kierowanego do `main`.
 *
 * Przed poprawką `pull_request` nie miał filtra gałęzi docelowej, a joby nie
 * sprawdzały `base.ref`. Po włączeniu `KUKING_DEPLOY_ENABLED` scalenie PR-a
 * z `.railway/**` do `staging` odpalało `Apply (production)` z tokenem
 * produkcyjnym i `confirm-destructive: true`.
 *
 * Trzy niezależne zamki, każdy sprawdzany osobno:
 *   1. `on.pull_request.branches: [main]` — workflow w ogóle nie rusza,
 *   2. `base.ref == 'main'` w warunku KAŻDEGO joba z tokenem produkcyjnym,
 *   3. krok „Bramka gałęzi docelowej” — jawna odmowa, zanim ruszy token.
 *
 * GitHub Actions nie da się uruchomić z testu, więc czytamy plik. Kontrole
 * dodatnie (mutacje, które mają zapalić ten test) stoją w
 * `scripts/kontrole-negatywne-alfa08.py`.
 */
class IacProdukcjaTylkoZPrDoMainTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/railway-iac.yml';

    private const TOKEN = 'secrets.RAILWAY_TOKEN_PRODUCTION';

    private const GALAZ_W_WARUNKU = "github.event.pull_request.base.ref == 'main'";

    /** Workflow bez linii komentarzy — komentarze cytują warunki słowami. */
    private function workflow(): string
    {
        $sciezka = base_path(self::WORKFLOW);

        $this->assertFileExists($sciezka, 'Nie ma '.self::WORKFLOW.'.');

        return (string) preg_replace('/^\s*#.*\n/m', '', (string) file_get_contents($sciezka));
    }

    /** Blok `on:` — od `on:` do następnego klucza najwyższego poziomu. */
    private function blokOn(): string
    {
        $this->assertSame(1, preg_match('/^on:\n(.*?)^(?=\S)/ms', $this->workflow(), $m), 'Brak bloku `on:` w '.self::WORKFLOW.'.');

        return $m[1];
    }

    /** @return array<string, string> nazwa joba => treść */
    private function joby(): array
    {
        $workflow = $this->workflow();
        $this->assertSame(1, preg_match('/^jobs:\n(.*?)(?:^(?=\S)|\z)/ms', $workflow, $m), 'Brak bloku `jobs:`.');

        $czesci = preg_split('/^  ([A-Za-z0-9_-]+):\n/m', $m[1], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $joby = [];
        for ($i = 0; $i + 1 < count($czesci); $i += 2) {
            $joby[$czesci[$i]] = $czesci[$i + 1];
        }

        return $joby;
    }

    /** Warunek `if: >-` joba złożony w jedną linię. */
    private function warunek(string $job): string
    {
        $this->assertSame(1, preg_match('/^    if: >-\n((?:      .*\n)+)/m', $job, $m), 'Job z tokenem produkcyjnym nie ma warunku `if: >-`.');

        return (string) preg_replace('/\s+/', ' ', trim($m[1]));
    }

    /** @return array<string, string> joby, które dotykają tokenu produkcyjnego */
    private function jobyProdukcyjne(): array
    {
        $joby = array_filter($this->joby(), fn (string $job) => str_contains($job, self::TOKEN));

        // Próg: bez niego pusta lista (zła ścieżka, zmiana wcięcia) dałaby zieleń.
        $this->assertSame(['plan', 'apply'], array_keys($joby), 'Oczekiwano dokładnie jobów plan i apply z tokenem produkcyjnym.');

        return $joby;
    }

    #[Test]
    public function workflow_rusza_tylko_na_pull_request_do_main(): void
    {
        $on = $this->blokOn();

        $this->assertSame(1, preg_match_all('/^  (\S+):/m', $on, $wyzwalacze), 'Blok `on:` jest pusty.');
        $this->assertSame(['pull_request'], $wyzwalacze[1], 'Jedynym wyzwalaczem ma być `pull_request` (bez push, dispatch i pull_request_target).');
        $this->assertMatchesRegularExpression('/^    branches: \[main\]\s*$/m', $on, '`pull_request` musi mieć `branches: [main]` — filtr gałęzi DOCELOWEJ (#1313).');
        $this->assertStringNotContainsString('branches-ignore', $on);
    }

    #[Test]
    public function kazdy_job_z_tokenem_produkcyjnym_wymaga_pr_do_main(): void
    {
        foreach ($this->jobyProdukcyjne() as $nazwa => $job) {
            $warunek = $this->warunek($job);

            $this->assertStringContainsString(self::GALAZ_W_WARUNKU, $warunek, "Job `{$nazwa}` musi wymagać PR-a do main (#1313).");
            $this->assertStringContainsString("github.event_name == 'pull_request'", $warunek, "Job `{$nazwa}` bez sprawdzenia zdarzenia.");
            $this->assertStringNotContainsString('||', $warunek, "Warunek joba `{$nazwa}` ma być koniunkcją — `||` otwiera obejście.");

            // Obecne bramki zostają (kryterium akceptacji #1313).
            $this->assertStringContainsString("vars.KUKING_DEPLOY_ENABLED == 'true'", $warunek, "Job `{$nazwa}` zgubił bramkę wdrożeniową.");
            $this->assertStringContainsString('github.event.pull_request.head.repo.full_name == github.repository', $warunek, "Job `{$nazwa}` zgubił bramkę same-repo.");
        }
    }

    #[Test]
    public function plan_przed_scaleniem_apply_po_scaleniu_z_reviewerem(): void
    {
        $joby = $this->jobyProdukcyjne();

        $this->assertStringContainsString("github.event.action != 'closed'", $this->warunek($joby['plan']));

        $apply = $this->warunek($joby['apply']);
        $this->assertStringContainsString("github.event.action == 'closed'", $apply);
        $this->assertStringContainsString('github.event.pull_request.merged', $apply);
        $this->assertMatchesRegularExpression('/^    environment: production$/m', $joby['apply'], 'Apply bez środowiska production traci wymaganego reviewera.');
        $this->assertStringContainsString('ref: ${{ github.event.pull_request.merge_commit_sha }}', $joby['apply']);
    }

    #[Test]
    public function kazdy_job_produkcyjny_odmawia_jawnie_przed_tokenem(): void
    {
        foreach ($this->jobyProdukcyjne() as $nazwa => $job) {
            $bramka = strpos($job, '- name: Bramka gałęzi docelowej');
            $this->assertNotFalse($bramka, "Job `{$nazwa}` nie ma kroku „Bramka gałęzi docelowej”.");
            $this->assertLessThan(strpos($job, '- uses: actions/checkout@'), $bramka, "W `{$nazwa}` bramka gałęzi ma stać przed checkoutem.");
            $this->assertLessThan(strpos($job, self::TOKEN), $bramka);

            $koniec = strpos($job, '- uses:', $bramka);
            $krok = substr($job, $bramka, $koniec === false ? null : $koniec - $bramka);

            $this->assertStringContainsString('GALAZ_DOCELOWA: ${{ github.event.pull_request.base.ref }}', $krok);
            $this->assertStringContainsString('ZDARZENIE: ${{ github.event_name }}', $krok);
            $this->assertStringContainsString('set -euo pipefail', $krok);
            $this->assertStringContainsString('[ "$ZDARZENIE" != "pull_request" ] || [ "$GALAZ_DOCELOWA" != "main" ]', $krok, "Bramka `{$nazwa}` nie odmawia gałęzi innej niż main.");
            $this->assertStringContainsString('exit 1', $krok);
        }

        $apply = $this->jobyProdukcyjne()['apply'];
        $this->assertStringContainsString('SCALONY: ${{ github.event.pull_request.merged }}', $apply);
        $this->assertStringContainsString('[ "$SCALONY" != "true" ]', $apply, 'Bramka apply nie odmawia PR-a niescalonego.');
    }

    #[Test]
    public function zaden_job_nie_miesza_srodowisk(): void
    {
        $this->assertStringNotContainsString('RAILWAY_TOKEN_STAGING', $this->workflow(), 'Staging dostaje osobny workflow, nie joby w pliku produkcji (#1313).');
        $this->assertStringNotContainsString('environment: staging', $this->workflow());
    }
}
