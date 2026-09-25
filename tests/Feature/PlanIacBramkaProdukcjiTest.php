<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audyt B10-01 — job `plan` w `.github/workflows/railway-iac.yml` wykonuje
 * `.railway/railway.ts` Z GAŁĘZI PR-a (importowany przez akcję
 * `railwayapp/config`), z tokenem `secrets.RAILWAY_TOKEN_PRODUCTION`.
 *
 * Warunek `head.repo.full_name == github.repository` blokuje tylko forki.
 * Każdy, kto może wypchnąć gałąź w TYM repozytorium (agent z prawem push,
 * skradziony token), dopisuje do `railway.ts` jedną linię wysyłającą
 * `process.env`/wejścia akcji na zewnątrz i dostaje pełny dostęp do projektu
 * produkcyjnego Railway — bez recenzji, bo `plan` nie miał żadnej bramki
 * zatwierdzenia.
 *
 * Poprawka: job `plan` dostaje `environment: production` — to samo
 * ustawienie (Required reviewers), które od dawna chroni `apply`. Recenzent
 * musi kliknąć zgodę, zanim GitHub w ogóle uruchomi krok z tokenem.
 *
 * Test czyta plik workflow, bo GitHub Actions nie da się uruchomić z testu —
 * nie dowodzi, że w panelu faktycznie skonfigurowano recenzenta, tylko że
 * workflow o to prosi.
 */
class PlanIacBramkaProdukcjiTest extends TestCase
{
    private const SCIEZKA = '.github/workflows/railway-iac.yml';

    private function workflow(): string
    {
        $sciezka = base_path(self::SCIEZKA);

        $this->assertFileExists(
            $sciezka,
            'Nie ma '.self::SCIEZKA.'. Jeśli plik przeniesiono, popraw ścieżkę tutaj.',
        );

        return (string) file_get_contents($sciezka);
    }

    /** Wycina treść joba `plan:` od nagłówka do następnego joba na tym samym wcięciu. */
    private function jobPlan(string $workflow): string
    {
        $this->assertMatchesRegularExpression(
            '/^  plan:\n(?:.*\n)*?(?=^  \S|\z)/m',
            $workflow,
            'Nie znaleziono joba `plan:` w '.self::SCIEZKA.' — zmieniła się struktura pliku, popraw regex testu.',
        );

        preg_match('/^  plan:\n(?:.*\n)*?(?=^  \S|\z)/m', $workflow, $dopasowanie);

        return $dopasowanie[0];
    }

    #[Test]
    public function job_plan_wymaga_srodowiska_production_przed_wykonaniem_kodu_z_pr(): void
    {
        $plan = $this->jobPlan($this->workflow());

        $this->assertMatchesRegularExpression(
            '/^\s*environment:\s*production\s*$/m',
            $plan,
            'Job `plan` w '.self::SCIEZKA.' wykonuje `.railway/railway.ts` z gałęzi PR-a '.
            'z tokenem RAILWAY_TOKEN_PRODUCTION, ale nie ma `environment: production`. '.
            'Bez tego GitHub uruchamia krok z tokenem BEZ zgody recenzenta — dowolna gałąź '.
            'tego repozytorium (nie tylko fork) dostaje pełny dostęp do Railway (audyt B10-01). '.
            'Dodaj `environment: production` do joba `plan`, tak jak ma go job `apply`.',
        );
    }

    #[Test]
    public function warunek_forkow_zostaje_bo_bramka_srodowiska_go_nie_zastepuje(): void
    {
        $plan = $this->jobPlan($this->workflow());

        // Bramka środowiska chroni przed gałęziami TEGO repozytorium.
        // Warunek forka zostaje osobno — bez niego PR z forka w ogóle
        // próbowałby uruchomić joba (i utknąłby czekając na recenzenta,
        // zamiast być pominięty).
        $this->assertStringContainsString(
            'head.repo.full_name == github.repository',
            $plan,
            'Warunek blokujący PR-y z forków zniknął z joba `plan` — to osobna bramka '.
            'od `environment: production` i obie są potrzebne.',
        );
    }

    #[Test]
    public function job_apply_dalej_ma_bramke_produkcji(): void
    {
        $workflow = $this->workflow();

        $this->assertMatchesRegularExpression(
            '/^  apply:\n(?:.*\n)*?^\s*environment:\s*production\s*$/m',
            $workflow,
            'Job `apply` stracił `environment: production` — to nie jest cel tej poprawki, '.
            'sprawdzamy tylko, że nic nie rozluźniliśmy przy okazji.',
        );
    }
}
