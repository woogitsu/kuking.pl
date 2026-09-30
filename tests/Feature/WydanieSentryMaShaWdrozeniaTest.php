<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * #2230 — wydanie Sentry nosi SHA commita, który Railway wdrożył.
 *
 * Job `verify` w deploy.yml chodzi na `deployment_status: success`. Test
 * dymny sprawdza, że `/wydanie` podaje `github.event.deployment.sha`, a krok
 * „Rejestracja wydania w Sentry” rejestrował `github.sha`. To SHA z kontekstu
 * przebiegu, a nie z obiektu wdrożenia, więc błędy produkcji mogły trafić do
 * wydania innego commita niż ten, który działa (inne suspect commits).
 *
 * Test czyta deploy.yml przez yaml.safe_load i porównuje wyrażenie `version`
 * kroku Sentry z SHA, które sprawdza test dymny. Funkcja reguły idzie też na
 * syntetycznym kroku sprzed #2230 (kontrola dodatnia).
 */
final class WydanieSentryMaShaWdrozeniaTest extends TestCase
{
    private const SHA_WDROZENIA = '${{ github.event.deployment.sha }}';

    /** @return array<string, mixed> */
    private function job(): array
    {
        $proces = new Process([
            'python3', '-c',
            'import sys, json, yaml; print(json.dumps(yaml.safe_load(open(sys.argv[1], encoding="utf-8"))))',
            base_path('.github/workflows/deploy.yml'),
        ]);
        $proces->run();
        $this->assertSame(0, $proces->getExitCode(), 'yaml.safe_load nie przeczytał deploy.yml: '.$proces->getErrorOutput());

        $dane = json_decode($proces->getOutput(), true);
        $this->assertIsArray($dane['jobs']['verify'] ?? null, 'deploy.yml nie ma joba `verify`. Jeśli zmienił nazwę, popraw ten test.');

        return $dane['jobs']['verify'];
    }

    /** @return array<string, mixed> */
    private function krok(array $job, string $nazwa): array
    {
        $kroki = array_values(array_filter(
            (array) ($job['steps'] ?? []),
            static fn ($k): bool => is_array($k) && ($k['name'] ?? null) === $nazwa,
        ));
        $this->assertCount(1, $kroki, "Job `verify` ma mieć dokładnie jeden krok „{$nazwa}”.");

        return $kroki[0];
    }

    /** @return list<string> */
    private function naruszenia(array $sentry, string $shaTestuDymnego): array
    {
        $wersja = (string) ($sentry['with']['version'] ?? '');
        $naruszenia = [];
        if ($wersja !== self::SHA_WDROZENIA) {
            $naruszenia[] = "Wydanie Sentry ma version: {$wersja}, a powinno ".self::SHA_WDROZENIA
                .' — SHA wdrożenia z deployment_status, nie github.sha (#2230).';
        }
        if ($wersja !== $shaTestuDymnego) {
            $naruszenia[] = "Wydanie Sentry ({$wersja}) i test dymny ({$shaTestuDymnego}) wskazują różne commity.";
        }

        return $naruszenia;
    }

    #[Test]
    public function wydanie_sentry_ma_ten_sam_sha_co_test_dymny_wdrozenia(): void
    {
        $job = $this->job();
        $sentry = $this->krok($job, 'Rejestracja wydania w Sentry');
        $dymny = $this->krok($job, 'Test dymny');

        $this->assertStringStartsWith('getsentry/action-release@', (string) ($sentry['uses'] ?? ''), 'Krok Sentry nie używa już getsentry/action-release — popraw ten test.');
        $shaDymnego = (string) ($dymny['env']['OCZEKIWANY_SHA'] ?? '');
        $this->assertSame(self::SHA_WDROZENIA, $shaDymnego, 'Test dymny nie sprawdza już SHA wdrożenia — reguła wydania straciła punkt odniesienia.');

        $naruszenia = $this->naruszenia($sentry, $shaDymnego);
        $this->assertSame([], $naruszenia, implode("\n", $naruszenia));

        // Kontrola dodatnia: krok sprzed #2230.
        $this->assertNotSame([], $this->naruszenia(['with' => ['version' => '${{ github.sha }}']], $shaDymnego), 'Reguła przepuściła wydanie Sentry z github.sha.');
    }
}
