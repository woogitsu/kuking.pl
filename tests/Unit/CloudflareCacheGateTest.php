<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @bez-kontroli-dodatniej Czyta dokument JSON i asertuje na sparsowanej strukturze, a asercje tekstowe dotyczą wyjścia skryptu bramki, nie treści źródła aplikacji.
 */
class CloudflareCacheGateTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_bramka_odmawia_bez_pelnego_pomiaru(string $scenario, bool $passes): void
    {
        $root = dirname(__DIR__, 2);
        $cookieFile = tempnam(sys_get_temp_dir(), 'cache-fixture-');
        $counterFile = tempnam(sys_get_temp_dir(), 'cache-counter-');
        file_put_contents($cookieFile, 'fixture=TAJNA_WARTOSC');
        try {
            $process = new Process(['bash', $root.'/tests/skrypty/atrapa-cache-gate.sh', $root.'/scripts/sprawdz-wdrozenie.sh'], $root, [
                'SCENARIO' => $scenario,
                'CACHE_KIND' => str_starts_with($scenario, 'html-') ? 'html' : 'media',
                'CACHE_COUNT_FILE' => $counterFile,
                'CACHE_COOKIE_FILE' => $scenario === 'missing-file' ? '/brak-pliku' : $cookieFile,
                'CACHE_PUBLIC_PATH' => '/public',
                'CACHE_PRIVATE_PATH' => '/private',
            ]);
            $process->run();
        } finally {
            unlink($cookieFile);
            unlink($counterFile);
        }
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame($passes ? 0 : 1, $process->getExitCode(), $output);
        $this->assertStringContainsString($passes ? 'Bramka cache przeszła' : 'ODMOWA CACHE', $output);
        $this->assertStringNotContainsString('TAJNA_WARTOSC', $output);
    }

    public static function cases(): array
    {
        $cases = ['kontrola dodatnia zdjęć' => ['good', true], 'kontrola dodatnia HTML' => ['html-good', true],
            // #610: dokładny nagłówek aplikacji i 403 prywatnego przepisu.
            'nagłówek HTML z aplikacji #610' => ['html-610', true],
            'prywatny przepis 403 dla gościa' => ['html-610-403', true],
            // 403 wolno tylko dla HTML; zdjęcie prywatne ma dawać 404.
            'zdjęcie 403 zamiast 404' => ['media-403', false]];
        foreach (['expired', 'cookie', 'no-control', 'no-public', 'zero-ttl', 'private-public', 'no-hit', 'duplicate', 'timeout', 'truncated', 'auth-public', 'auth-private-only', 'auth-hit', 'auth-miss', 'auth-unknown', 'auth-cdn', 'auth-error', 'auth-after-hit', 'missing-file', 'unsigned', 'cache-longer-than-signature'] as $scenario) {
            $cases[$scenario] = [$scenario, false];
        }

        return $cases;
    }

    public function test_projekty_regul_sa_wylaczone_i_nie_nadpisuja_originu(): void
    {
        $document = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/infra/cloudflare-cache-rules-597-610.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $document['rules'], 'Kontrola musi przeczytać wszystkie trzy reguły.');
        foreach ($document['rules'] as $rule) {
            $this->assertFalse($rule['enabled']);
            if ($rule['action_parameters']['cache']) {
                $this->assertSame(['mode' => 'bypass_by_default'], $rule['action_parameters']['edge_ttl']);
                $this->assertSame(['mode' => 'respect_origin'], $rule['action_parameters']['browser_ttl']);
            }
        }
        $this->assertFalse($document['rules'][2]['action_parameters']['cache'], 'Ostatnia reguła musi odmawiać cache.');
    }

    /**
     * Tryb TTL to połowa reguły; druga połowa to WARUNEK. Reguła „eligible”
     * bez `http.cookie eq ""` wpuszcza do wspólnego cache odpowiedź dla
     * zalogowanego, jeśli kiedyś origin pomyli nagłówek — a to jest dokładnie
     * ten wyciek, przed którym #597 każe się bronić („treść prywatna nie może
     * trafić do wspólnego cache”). Właściciel wkleja wyrażenia z tego pliku
     * do panelu, więc to one muszą być poprawne, nie tylko tryby.
     */
    public function test_warunki_regul_nie_wpuszczaja_stanu_klienta_do_wspolnego_cache(): void
    {
        $rules = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/infra/cloudflare-cache-rules-597-610.json'), true, flags: JSON_THROW_ON_ERROR)['rules'];
        $this->assertCount(3, $rules, 'Kontrola musi przeczytać wszystkie trzy reguły.');

        foreach ($rules as $i => $rule) {
            $this->assertStringStartsWith('(http.host eq "kuking.pl" and ', $rule['expression'], "Reguła {$i}: ten sam dokładny host w każdej regule.");
        }

        foreach ([0, 1] as $i) {
            $expression = $rules[$i]['expression'];
            $this->assertTrue($rules[$i]['action_parameters']['cache'], "Reguła {$i} ma być regułą eligible.");
            foreach ([
                'and http.cookie eq ""',
                'and http.request.uri.query eq ""',
                'and not any(http.request.headers["authorization"][*] ne "")',
            ] as $warunek) {
                $this->assertStringContainsString($warunek, $expression, "Reguła {$i} bez warunku {$warunek}.");
            }
            $this->assertStringNotContainsString(' or http.cookie', $expression, "Reguła {$i}: alternatywa osłabia warunek ciasteczka.");
        }

        $this->assertStringContainsString('starts_with(http.request.uri.path, "/zdjecia/")', $rules[0]['expression']);
        $this->assertStringNotContainsString(' or ', $rules[0]['expression'], 'Reguła zdjęć nie może mieć alternatywy — każdy człon to warunek konieczny.');

        $bypass = $rules[2]['expression'];
        foreach ([
            'http.cookie ne ""',
            'any(http.request.headers["authorization"][*] ne "")',
            'not http.request.method in {"GET" "HEAD"}',
            'http.request.uri.query ne ""',
        ] as $warunek) {
            $this->assertStringContainsString($warunek, $bypass, "Końcowy BYPASS bez warunku {$warunek}.");
        }
    }
}
