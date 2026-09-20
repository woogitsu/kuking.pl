<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Pomiar całej sondy i rzeczywistego kroku workflow; transport i CLI są atrapami. */
final class SondaWdrozeniaTest extends TestCase
{
    #[DataProvider('probeCases')]
    public function test_sonda_mierzy_zamiast_zgadywac(string $scenario, string $verdict, bool $success, int $exit): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process(['bash', $root.'/tests/skrypty/atrapa-sondy.sh', $root.'/scripts/sprawdz-wdrozenie.sh'], $root, [
            'SCENARIO' => $scenario,
            'SESSION_COOKIE' => $scenario === 'cookie-no-name' ? '' : 'secure-fixture-session',
            'KUKING_CDN_HOST' => 'cdn.example.invalid',
        ]);
        $process->run();
        $output = preg_replace('/\x1b\[[0-9;]*m/', '', $process->getOutput().$process->getErrorOutput());
        $this->assertSame($exit, $process->getExitCode(), $output);
        $this->assertStringContainsString($verdict, $output);
        $label = str_starts_with($scenario, 'www') ? 'www przekierowuje na apex' : (str_starts_with($scenario, 'cookie') ? 'Ciasteczko sesji ma flagę Secure' : 'Endpoint Livewire nie jest cache\'owany');
        if ($success) {
            $this->assertStringContainsString('✓ '.$label, $output);
        } else {
            $this->assertStringNotContainsString('✓ '.$label, $output);
        }
        $this->assertStringNotContainsString('TAJNA_WARTOSC', $output);
        if (str_ends_with($scenario, '-timeout')) {
            $this->assertStringContainsString('curl: 28', $output);
        }
    }

    public static function probeCases(): array
    {
        return [
            '806 poprawne BYPASS' => ['livewire-good', "Endpoint Livewire nie jest cache'owany", true, 0],
            '806 poprawne DYNAMIC' => ['livewire-dynamic', "Endpoint Livewire nie jest cache'owany", true, 0],
            '806 nagłówki proxy' => ['livewire-proxy', "Endpoint Livewire nie jest cache'owany", true, 0],
            '806 nagłówki ucięte mimo kodu zero' => ['livewire-truncated', 'Nie sprawdzono cache Livewire', false, 1],
            '806 timeout nagłówków' => ['livewire-timeout', 'Nie sprawdzono cache Livewire', false, 1],
            '806 niepełna odpowiedź' => ['livewire-partial', 'Nie sprawdzono cache Livewire', false, 1],
            '806 puste nagłówki' => ['livewire-empty', 'Nie sprawdzono cache Livewire', false, 1],
            '806 brak endpointu' => ['livewire-404', 'Nie sprawdzono cache Livewire', false, 1],
            '806 błąd serwera' => ['livewire-500', 'Nie sprawdzono cache Livewire', false, 1],
            '806 brak dowodu cache' => ['livewire-no-cache-header', 'Nie sprawdzono cache Livewire', false, 1],
            '806 HIT' => ['livewire-HIT', 'Endpoint Livewire jest cache', false, 1],
            '806 MISS duże nagłówki' => ['livewire-MISS', 'Endpoint Livewire jest cache', false, 1],
            '807 poprawna sesja' => ['cookie-good', 'Ciasteczko sesji ma flagę Secure', true, 0],
            '807 różna wielkość liter' => ['cookie-mixed', 'Ciasteczko sesji ma flagę Secure', true, 0],
            '807 Secure w nazwie' => ['cookie-name', 'Ciasteczko sesji BEZ flagi Secure', false, 1],
            '807 Secure w wartości' => ['cookie-value', 'Ciasteczko sesji BEZ flagi Secure', false, 1],
            '807 inne ciasteczko' => ['cookie-other', 'Ciasteczko sesji BEZ flagi Secure', false, 1],
            '807 Secure z wartością' => ['cookie-assignment', 'Ciasteczko sesji BEZ flagi Secure', false, 1],
            '807 brak sesji' => ['cookie-missing', 'Nie sprawdzono flagi Secure', false, 1],
            '807 brak oczekiwanej nazwy' => ['cookie-no-name', 'Nie sprawdzono flagi Secure', false, 1],
            '807 dwa nagłówki sesji' => ['cookie-duplicate', 'Ciasteczko sesji BEZ flagi Secure', false, 1],
            '807 timeout' => ['cookie-timeout', 'Nie sprawdzono flagi Secure', false, 1],
            '807 niepełna odpowiedź' => ['cookie-partial', 'Nie sprawdzono flagi Secure', false, 1],
            '808 poprawne 301' => ['www-good', 'www przekierowuje na apex', true, 0],
            '808 poprawne 308' => ['www-308', 'www przekierowuje na apex', true, 0],
            '808 jawny port HTTPS' => ['www-port', 'www przekierowuje na apex', true, 0],
            '808 wielkość liter i ścieżka' => ['www-case', 'www przekierowuje na apex', true, 0],
            '808 pętla' => ['www-loop', 'www nie przekierowuje na apex', false, 1],
            '808 obca domena' => ['www-other', 'www nie przekierowuje na apex', false, 1],
            '808 prefiks domeny' => ['www-prefix', 'www nie przekierowuje na apex', false, 1],
            '808 userinfo' => ['www-userinfo', 'www nie przekierowuje na apex', false, 1],
            '808 HTTP' => ['www-http', 'www nie przekierowuje na apex', false, 1],
            '808 pusty cel' => ['www-empty', 'www nie przekierowuje na apex', false, 1],
            '808 bez przekierowania' => ['www-200', 'www nie przekierowuje na apex', false, 1],
            '808 timeout' => ['www-timeout', 'Nie sprawdzono przekierowania www', false, 1],
        ];
    }

    #[DataProvider('deleteCases')]
    public function test_usuwanie_preview_nie_ukrywa_bledu_cli(int $code, string $message): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = file_get_contents($root.'/.github/workflows/preview.yml');
        $count = preg_match_all('/      - name: Usuń środowisko PR\R        run: \|\R((?:          .*\R|\R)+)/u', $workflow, $matches);
        $this->assertSame(1, $count, 'Test musi znaleźć dokładnie jeden wykonywany krok usuwania.');
        $script = preg_replace('/^          /m', '', $matches[1][0]);
        $script = str_replace('${{ github.event.inputs.pr_number }}', '999999', $script);
        $stub = <<<'BASH'
railway() {
    [[ "$*" == 'environment delete pr-999999 --yes' ]] || exit 99
    printf '%s\n' "$CLI_MESSAGE" >&2
    return "$CLI_CODE"
}
export -f railway
BASH;
        $process = new Process(['bash', '-c', $stub."\n".$script], $root, ['CLI_CODE' => (string) $code, 'CLI_MESSAGE' => $message]);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame($code, $process->getExitCode(), $output);
        $this->assertStringContainsString($message, $output);
        if ($code === 0) {
            $this->assertStringContainsString('Gotowe.', $output);
        } else {
            $this->assertStringNotContainsString('Gotowe.', $output);
        }
    }

    public static function deleteCases(): array
    {
        // Kody są syntetyczne. Test nie przypisuje im znaczenia z Railway CLI.
        return [
            '805 sukces' => [0, 'ATRAPA_USUNIETO'],
            '805 odmowa dostępu' => [73, 'ATRAPA_ODMOWA'],
            '805 awaria komunikacji' => [28, 'ATRAPA_KOMUNIKACJA'],
            '805 brak środowiska zgłoszony błędem' => [1, 'ATRAPA_BRAK_SRODOWISKA'],
        ];
    }
}
