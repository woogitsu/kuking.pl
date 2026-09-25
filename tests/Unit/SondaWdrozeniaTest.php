<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Pomiar całej sondy i rzeczywistego kroku workflow; transport i CLI są atrapami.
 *
 * @bez-kontroli-dodatniej assertSame(1, $count) wymaga dokładnie jednego kroku usuwania w preview.yml, więc zgubiony albo przeredagowany krok daje czerwień, nie cichą zieleń.
 */
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

    /**
     * Kontrakt kroku „Usuń środowisko PR" po decyzji właściciela: najpierw
     * `environment list --json`, dopiero potem `delete`.
     *
     * @param  list<string>  $expectedInOutput  fragmenty, które MUSZĄ paść
     * @param  list<string>  $forbiddenInOutput  fragmenty, których NIE WOLNO zobaczyć
     */
    #[DataProvider('deleteCases')]
    public function test_usuwanie_preview_nie_ukrywa_bledu_cli(
        string $listStdout,
        string $listStderr,
        int $listCode,
        int $deleteCode,
        string $deleteMessage,
        int $expectedExit,
        bool $expectGotowe,
        array $expectedInOutput,
        array $forbiddenInOutput,
    ): void {
        $root = dirname(__DIR__, 2);
        $workflow = file_get_contents($root.'/.github/workflows/preview.yml');
        // Między nazwą a `run:` mogą stać komentarz i `env:` kroku (RAILWAY_TOKEN
        // tylko w tym kroku, audyt B10-02) — liczy się sam wykonywany skrypt.
        $count = preg_match_all('/      - name: Usuń środowisko PR\R(?:        (?!run:).*\R)*        run: \|\R((?:          .*\R|\R)+)/u', $workflow, $matches);
        $this->assertSame(1, $count, 'Test musi znaleźć dokładnie jeden wykonywany krok usuwania.');
        $script = preg_replace('/^          /m', '', $matches[1][0]);
        $script = str_replace('${{ github.event.inputs.pr_number }}', '999999', $script);
        $stub = <<<'BASH'
railway() {
    case "$*" in
        'environment list --json')
            printf '%s' "$LIST_STDOUT"
            if [[ -n "$LIST_STDERR" ]]; then printf '%s\n' "$LIST_STDERR" >&2; fi
            return "$LIST_CODE"
            ;;
        'environment delete pr-999999 --yes')
            printf '%s\n' "$DELETE_MESSAGE" >&2
            return "$DELETE_CODE"
            ;;
        *)
            printf 'ATRAPA_NIEZNANE_WYWOLANIE: %s\n' "$*" >&2
            exit 99
            ;;
    esac
}
export -f railway
BASH;
        $process = new Process(['bash', '-c', $stub."\n".$script], $root, [
            'LIST_STDOUT' => $listStdout,
            'LIST_STDERR' => $listStderr,
            'LIST_CODE' => (string) $listCode,
            'DELETE_CODE' => (string) $deleteCode,
            'DELETE_MESSAGE' => $deleteMessage,
        ]);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame($expectedExit, $process->getExitCode(), $output);
        foreach ($expectedInOutput as $fragment) {
            $this->assertStringContainsString($fragment, $output);
        }
        foreach ($forbiddenInOutput as $fragment) {
            $this->assertStringNotContainsString($fragment, $output);
        }
        if ($expectGotowe) {
            $this->assertStringContainsString('Gotowe.', $output);
        } else {
            $this->assertStringNotContainsString('Gotowe.', $output);
        }
    }

    public static function deleteCases(): array
    {
        // DLACZEGO ZESTAW PRZYPADKÓW SIĘ ZMIENIŁ (decyzja właściciela — trzecia droga).
        //
        // Stara wersja testu karmiła atrapę kodami 0, 73, 28 i 1, jakby po samym
        // kodzie wyjścia dało się odróżnić „brak środowiska" od „wygasły token".
        // Te kody były syntetyczne i — co ważniejsze — w tym CLI NIE ISTNIEJĄ:
        // Railway CLI zwraca 1 dla KAŻDEGO błędu. Przepisanie 73 i 28 pod nowy
        // krok utrwaliłoby fikcję, że krok może po nich cokolwiek rozpoznać.
        //
        // Nowy kontrakt nie zgaduje po kodzie, tylko najpierw pyta o listę,
        // więc przypadki rozdzielają się wzdłuż DWÓCH wywołań CLI:
        //   1. czego nie ma na liście — nie ma czego kasować (zielono, cicho),
        //   2. jest i skasowane — zielono, z „Gotowe.",
        //   3. jest i kasowanie padło — czerwono, komunikat CLI przepuszczony,
        //   4. padła sama lista — czerwono, bo inaczej ukrywamy błąd piętro wyżej.
        // Jedyny kod błędu w zestawie to 1, bo tylko taki to CLI produkuje.
        $lista = static fn (string ...$names): string => json_encode(
            array_map(static fn (string $n): array => ['id' => 'env_'.$n, 'name' => $n], $names),
            JSON_THROW_ON_ERROR,
        );

        return [
            '805 brak środowiska na liście — nie ma czego usuwać' => [
                $lista('production', 'staging', 'pr-111111'), '', 0,
                0, 'ATRAPA_NIE_WOLNO_KASOWAC',
                0, false,
                ['nie ma czego usuwać'],
                // Dowód, że krok NIE dotknął `delete` — to jest cała stawka
                // pytania o listę przed kasowaniem.
                ['ATRAPA_NIE_WOLNO_KASOWAC'],
            ],
            '805 środowisko jest, kasowanie się udaje' => [
                $lista('production', 'staging', 'pr-999999'), '', 0,
                0, 'ATRAPA_USUNIETO',
                0, true,
                ['ATRAPA_USUNIETO'],
                ['nie ma czego usuwać'],
            ],
            '805 środowisko jest, kasowanie pada' => [
                $lista('staging', 'pr-999999'), '', 0,
                1, 'ATRAPA_ODMOWA_TOKENU',
                1, false,
                ['ATRAPA_ODMOWA_TOKENU'],
                ['nie ma czego usuwać'],
            ],
            '805 padła sama lista środowisk' => [
                '', 'ATRAPA_LISTA_PADLA', 1,
                0, 'ATRAPA_NIE_WOLNO_KASOWAC',
                1, false,
                ['ATRAPA_LISTA_PADLA'],
                ['ATRAPA_NIE_WOLNO_KASOWAC', 'nie ma czego usuwać'],
            ],
        ];
    }
}
