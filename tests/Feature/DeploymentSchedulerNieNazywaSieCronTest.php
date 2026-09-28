<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `docs/DEPLOYMENT.md` nazywa czwarty proces `scheduler`, nie `cron` (#1740).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Dokument opisywał docelową topologię jako `web + worker + postgres + cron`,
 * mimo że `.railway/railway.ts` od dawna zna tylko trzy role: `web`,
 * `worker` i `scheduler` (`PRODUCTION_SPLIT_SERVICES`), a
 * `docs/infra/INFRA_DECISION.md` wprost tłumaczy, dlaczego to
 * DŁUGO DZIAŁAJĄCY proces `schedule:work`, a nie Railway Cron: Railway Cron
 * ma granulację 5 minut, a harmonogram Laravela trzeba odpytywać co minutę.
 * Runbook prowadził więc osobę wdrażającą do nieistniejącej nazwy usługi
 * i mógł skłonić ją do skonfigurowania Railway Cron zamiast schedulera —
 * co po cichu wyłączyłoby zadania cykliczne wymagające odpytania co minutę.
 *
 * CO PILNUJE TEN TEST
 *  1. Dokument nie nazywa czwartego procesu `cron` w opisie topologii.
 *  2. Dokument nazywa go `scheduler`, tak jak `.railway/railway.ts`.
 *  3. Dokument mówi wprost, że to proces `schedule:work` i że działa stale.
 *  4. Dokument nie zachęca do tworzenia Railway Cron dla tej topologii.
 *
 * CZEGO NIE PILNUJE
 * Treści `docs/infra/INFRA_DECISION.md` zdanie po zdaniu — to osobny
 * dokument z własnym uzasadnieniem architektonicznym. Tu pytamy tylko
 * o to, czy `docs/DEPLOYMENT.md` mówi to samo, co IaC.
 */
class DeploymentSchedulerNieNazywaSieCronTest extends TestCase
{
    private const DOKUMENT = 'docs/DEPLOYMENT.md';

    private function tresc(): string
    {
        return (string) file_get_contents(base_path(self::DOKUMENT));
    }

    public function test_topologia_po_wzroscie_nie_nazywa_procesu_cron(): void
    {
        $tresc = $this->tresc();

        // Wąska kotwica: blok „Po wzroście" opisujący czwarty proces jako
        // element drzewa katalogów (`├──`/`└──`). Nie zakazujemy słowa
        // „cron" w całym pliku — mogłoby ono zasadnie pojawić się przy
        // wyjaśnieniu, dlaczego Railway Cron NIE jest tu użyty.
        $poWzroscie = preg_match(
            '/Po wzroście.*?```text(.*?)```/su',
            $tresc,
            $dopasowania,
        );

        $this->assertSame(
            1,
            $poWzroscie,
            'Sekcja „Po wzroście" z blokiem topologii zniknęła z '.self::DOKUMENT.
                ' — dopasuj ten test do nowego kształtu dokumentu.',
        );

        $blokTopologii = $dopasowania[1];

        $this->assertDoesNotMatchRegularExpression(
            '/[├└]──\s*cron\b/',
            $blokTopologii,
            'Blok topologii w '.self::DOKUMENT.' znowu nazywa czwarty proces `cron`. '.
                'IaC (`.railway/railway.ts`) i `docs/infra/INFRA_DECISION.md` znają '.
                'tylko `web`, `worker` i `scheduler` — Railway Cron nie jest tu używany '.
                '(granulacja 5 minut nie wystarcza harmonogramowi Laravela).',
        );

        $this->assertMatchesRegularExpression(
            '/[├└]──\s*scheduler\b/',
            $blokTopologii,
            'Blok topologii w '.self::DOKUMENT.' nie wymienia `scheduler` — nazwa ma '.
                'być zgodna z rolą w `.railway/railway.ts`.',
        );
    }

    public function test_dokument_opisuje_scheduler_jako_dlugo_dzialajacy_schedule_work(): void
    {
        $tresc = $this->tresc();

        $this->assertStringContainsString(
            'schedule:work',
            $tresc,
            self::DOKUMENT.' nie mówi, jaką komendę wykonuje `scheduler` — bez tego '.
                'opis roli jest samą nazwą, nie kontraktem.',
        );

        $this->assertMatchesRegularExpression(
            '/długo działając/u',
            $tresc,
            self::DOKUMENT.' nie mówi, że `scheduler` działa STALE — to jest właśnie '.
                'różnica wobec Railway Cron, dla której cały wpis powstał.',
        );
    }

    public function test_dokument_nie_namawia_do_railway_cron(): void
    {
        $tresc = $this->tresc();

        $this->assertMatchesRegularExpression(
            '/Railway Cron/',
            $tresc,
            self::DOKUMENT.' nie wspomina Railway Cron wcale — kryterium akceptacji #1740 '.
                'wymaga jawnego stwierdzenia, że ten mechanizm TU nie jest używany, '.
                'żeby osoba wdrażająca nie sięgnęła po niego z przyzwyczajenia.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/utwórz(cie)?\s+Railway Cron|dodaj\s+Railway Cron|skonfiguruj\s+Railway Cron/iu',
            $tresc,
            self::DOKUMENT.' zachęca do utworzenia Railway Cron — dla obecnej '.
                'konfiguracji (D-88 harmonogramu, granulacja 5 minut) to zła instrukcja.',
        );
    }
}
