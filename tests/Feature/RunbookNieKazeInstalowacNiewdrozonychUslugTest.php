<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Runbook wdrożeniowy opisuje monitoring i analitykę, które SĄ w kodzie —
 * nie każe instalować Sentry ani zakładać PostHog (issue #1010).
 *
 * CO BYŁO NIE TAK
 * `docs/infra/DEPLOYMENT_RUNBOOK.md` w krokach 4 i 5 kazał założyć Sentry,
 * wykonać `composer require sentry/sentry-laravel` i `php artisan
 * sentry:publish`, a potem założyć PostHog EU Cloud. Tabela zmiennych,
 * próba po wdrożeniu, rutyna i zestawienie od właściciela zakładały oba
 * konta. Tymczasem pakietu Sentry nie ma w `composer.json`, `config/sentry.php`
 * nie istnieje (D-041, #599), a PostHog nie jest wdrożony (D-063) — analityka
 * to własne zdarzenia i Cloudflare Web Analytics (D-092).
 *
 * DLACZEGO TO NIE JEST DROBIAZG
 * Operator idący po runbooku zmieniałby `composer.json` i `composer.lock`
 * w trakcie deployu, poza przejrzanym PR-em, i uruchamiał nowych odbiorców
 * danych przed decyzją właściciela i poprawą dokumentów prawnych.
 *
 * CO TEN TEST SPRAWDZA
 * Warunkowo, zgodnie z tym, co stoi w kodzie: dopóki pakietu/konfiguracji
 * Sentry nie ma, część runbooka do wykonania (poza `<details>`) nie może
 * instalować Sentry ani wymagać jego sekretów; dopóki kod nie wysyła niczego
 * do PostHog, runbook nie może kazać zakładać konta ani ustawiać klucza.
 * Gdy któraś usługa naprawdę wejdzie, odpowiednia asercja przestaje
 * obowiązywać sama. Kontrolę dodatnią prowadzi
 * `scripts/kontrole-negatywne-alfa08.py`.
 */
class RunbookNieKazeInstalowacNiewdrozonychUslugTest extends TestCase
{
    private function runbook(): string
    {
        return (string) file_get_contents(base_path('docs/infra/DEPLOYMENT_RUNBOOK.md'));
    }

    /** Dokument bez bloków `<details>`, czyli to, co operator wykonuje. */
    private function czescDoWykonania(): string
    {
        return (string) preg_replace('/<details>.*?<\/details>/su', '', $this->runbook());
    }

    private function sentryJestWdrozone(): bool
    {
        $composer = (string) file_get_contents(base_path('composer.json'));

        return str_contains($composer, 'sentry/sentry-laravel')
            || is_file(config_path('sentry.php'));
    }

    private function posthogJestWdrozony(): bool
    {
        $composer = (string) file_get_contents(base_path('composer.json'));
        if (stripos($composer, 'posthog') !== false) {
            return true;
        }

        foreach (['app', 'config', 'resources/views'] as $katalog) {
            $pliki = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($katalog), \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($pliki as $plik) {
                if ($plik->isFile() && stripos((string) file_get_contents($plik->getPathname()), 'posthog') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public function test_bez_pakietu_sentry_runbook_nie_kaze_go_instalowac(): void
    {
        $doWykonania = $this->czescDoWykonania();

        // Kontrola dodatnia: to jest runbook i ma część do wykonania.
        $this->assertStringContainsString('## KROK 4.', $doWykonania);

        if ($this->sentryJestWdrozone()) {
            $this->markTestSkipped('Sentry jest w composer.json/config — runbook może je opisywać.');
        }

        $zakazane = [
            'composer require sentry/sentry-laravel' => 'instalację pakietu Sentry w trakcie wdrożenia',
            'sentry:publish' => 'publikację konfiguracji Sentry',
            '| `SENTRY_LARAVEL_DSN` |' => 'DSN Sentry w tabeli zmiennych do ustawienia',
            'gh secret set SENTRY_AUTH_TOKEN' => 'sekret Sentry w GitHubie',
            'Konto Sentry' => 'konto Sentry w zestawieniu od właściciela',
            '| Sentry |' => 'Sentry jako punkt próby po wdrożeniu',
            '- [ ] Sentry' => 'Sentry w rutynie utrzymaniowej',
            '→ Sentry:' => 'panel Sentry w procedurze awarii',
        ];

        foreach ($zakazane as $fragment => $co) {
            $this->assertStringNotContainsString(
                $fragment,
                $doWykonania,
                "Runbook poza blokiem `<details>` zawiera {$co} (`{$fragment}`), a pakietu "
                .'Sentry nie ma w `composer.json` ani `config/sentry.php` (D-041, #599). '
                .'Wejście Sentry to przejrzany PR, nie krok deployu (issue #1010).',
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/^## KROK \d+[A-Z]?\. Sentry\b/m',
            $doWykonania,
            'Runbook ma osobny krok wdrożenia „Sentry", choć Sentry nie jest wdrożone (D-041).',
        );
    }

    public function test_bez_posthoga_w_kodzie_runbook_nie_kaze_zakladac_konta(): void
    {
        $doWykonania = $this->czescDoWykonania();

        $this->assertStringContainsString('## KROK 5.', $doWykonania);

        if ($this->posthogJestWdrozony()) {
            $this->markTestSkipped('PostHog pojawił się w kodzie — runbook może go opisywać.');
        }

        $zakazane = [
            '| `POSTHOG_KEY` |' => 'klucz PostHog w tabeli zmiennych lub sekretów',
            'Konto PostHog' => 'konto PostHog w zestawieniu od właściciela',
            'posthog.com →' => 'zakładanie projektu PostHog',
            '| PostHog |' => 'PostHog jako punkt próby po wdrożeniu',
            'limitów Sentry / PostHog' => 'panel PostHog w rutynie',
        ];

        foreach ($zakazane as $fragment => $co) {
            $this->assertStringNotContainsString(
                $fragment,
                $doWykonania,
                "Runbook zawiera {$co} (`{$fragment}`), a PostHog nie jest wdrożony (D-063); "
                .'analityka to własne zdarzenia i Cloudflare Web Analytics (D-092, issue #1010).',
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/^## KROK \d+[A-Z]?\. PostHog\b/m',
            $doWykonania,
            'Runbook ma osobny krok wdrożenia „PostHog", choć PostHog nie jest wdrożony (D-063).',
        );
    }

    public function test_runbook_opisuje_monitoring_i_analityke_ktore_dzialaja(): void
    {
        $doWykonania = $this->czescDoWykonania();

        // Zmienna i komenda muszą istnieć w kodzie — inaczej runbook uczyłby
        // kolejnej nieprawdy, tylko w drugą stronę.
        $this->assertStringContainsString(
            "env('LOG_BLAD_WEBHOOK_URL')",
            (string) file_get_contents(config_path('logging.php')),
        );
        $this->assertFileExists(app_path('Console/Commands/SprawdzAlarm.php'));

        foreach (['LOG_BLAD_WEBHOOK_URL', 'kuking:sprawdz-alarm', 'blad_webhook', 'CLOUDFLARE_ANALYTICS_TOKEN'] as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $doWykonania,
                "Runbook nie opisuje `{$fragment}` — monitoringu lub analityki, które naprawdę działają.",
            );
        }

        foreach (['#599', 'D-041', 'D-063', 'D-092', 'D-104'] as $zrodlo) {
            $this->assertStringContainsString($zrodlo, $this->runbook(), "Runbook nie wskazuje źródła decyzji {$zrodlo}.");
        }
    }

    public function test_numeracja_krokow_i_zestawienia_jest_ciagla(): void
    {
        $runbook = $this->runbook();

        preg_match_all('/^## KROK (\d+)\. /m', $runbook, $kroki);
        $numery = array_map('intval', $kroki[1]);
        $this->assertNotEmpty($numery);
        $this->assertSame(range(0, max($numery)), $numery, 'Kroki runbooka mają lukę albo powtórzenie.');

        $start = strpos($runbook, '## KROK 16.');
        $koniec = strpos($runbook, '## Szybka pomoc');
        $this->assertNotFalse($start);
        $this->assertNotFalse($koniec);

        preg_match_all('/^\| (\d+) \|/m', substr($runbook, $start, $koniec - $start), $wiersze);
        $lp = array_map('intval', $wiersze[1]);
        $this->assertNotEmpty($lp);
        $this->assertSame(range(1, count($lp)), $lp, 'Numeracja zestawienia w KROKU 16 ma lukę albo powtórzenie.');
    }
}
