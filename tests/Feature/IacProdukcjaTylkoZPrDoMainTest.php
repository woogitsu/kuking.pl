<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1313 — `railway-iac.yml` liczy plan z tokenem PRODUKCYJNYM wyłącznie dla
 * PR-a kierowanego do `main`.
 *
 * Przed poprawką `pull_request` nie miał filtra gałęzi docelowej, a job plan
 * nie sprawdzał `base.ref`. Po włączeniu `KUKING_DEPLOY_ENABLED` PR
 * z `.railway/**` do `staging` liczył plan z `RAILWAY_TOKEN_PRODUCTION`.
 *
 * Apply NIE jest tu pilnowany: od #595 idzie wyłącznie ręcznie
 * (`workflow_dispatch` z `main` plus wpisywane potwierdzenie) i to sprawdza
 * `scripts/railway/iac.test.mjs`. Ten test pilnuje tylko, żeby apply nie
 * wrócił na ścieżkę PR-ową, której filtr gałęzi nie obejmuje.
 *
 * Dwa niezależne zamki planu, każdy sprawdzany osobno:
 *   1. `on.pull_request.branches: [main]` — workflow w ogóle nie rusza,
 *   2. `base.ref == 'main'` w warunku joba plan.
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
    public function pull_request_rusza_tylko_dla_pr_do_main(): void
    {
        $on = $this->blokOn();

        $this->assertSame(1, preg_match('/^  pull_request:\n((?:    .*\n)+)/m', $on, $m), 'Blok `on:` nie ma wyzwalacza `pull_request`.');
        $this->assertMatchesRegularExpression('/^    branches: \[main\]\s*$/m', $m[1], '`pull_request` musi mieć `branches: [main]` — filtr gałęzi DOCELOWEJ (#1313).');
        $this->assertStringNotContainsString('branches-ignore', $on);
        $this->assertStringNotContainsString('pull_request_target', $on, '`pull_request_target` dałby token produkcyjny kodowi z forka.');
    }

    #[Test]
    public function plan_wymaga_pr_do_main(): void
    {
        $warunek = $this->warunek($this->jobyProdukcyjne()['plan']);

        $this->assertStringContainsString(self::GALAZ_W_WARUNKU, $warunek, 'Job `plan` musi wymagać PR-a do main (#1313).');
        $this->assertStringContainsString("github.event_name == 'pull_request'", $warunek, 'Job `plan` bez sprawdzenia zdarzenia.');
        $this->assertStringNotContainsString('||', $warunek, 'Warunek joba `plan` ma być koniunkcją — `||` otwiera obejście.');

        // Obecne bramki zostają (kryterium akceptacji #1313).
        $this->assertStringContainsString("vars.KUKING_DEPLOY_ENABLED == 'true'", $warunek, 'Job `plan` zgubił bramkę wdrożeniową.');
        $this->assertStringContainsString('github.event.pull_request.head.repo.full_name == github.repository', $warunek, 'Job `plan` zgubił bramkę same-repo.');
    }

    #[Test]
    public function apply_nie_wraca_na_sciezke_pull_request(): void
    {
        // Filtr `branches` chroni tylko zdarzenia `pull_request`. Apply
        // uruchamiany przez PR (np. po scaleniu) ominąłby ręczne potwierdzenie
        // z #595 — dlatego apply ma zostać przy `workflow_dispatch`.
        $warunek = $this->warunek($this->jobyProdukcyjne()['apply']);

        $this->assertStringContainsString("github.event_name == 'workflow_dispatch'", $warunek);
        $this->assertStringNotContainsString('pull_request', $warunek, 'Apply produkcji nie może ruszać ze zdarzenia PR-a.');
    }

    #[Test]
    public function zaden_job_nie_miesza_srodowisk(): void
    {
        $this->assertStringNotContainsString('RAILWAY_TOKEN_STAGING', $this->workflow(), 'Staging dostaje osobny workflow, nie joby w pliku produkcji (#1313).');
        $this->assertStringNotContainsString('environment: staging', $this->workflow());
    }
}
