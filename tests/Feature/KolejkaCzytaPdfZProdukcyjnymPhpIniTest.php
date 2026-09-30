<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\Support\MalyPdf;
use Tests\TestCase;

/**
 * Import z PDF działa w procesie kolejki OBRAZU PRODUKCYJNEGO (#2293, IN-01).
 *
 * `docker/php.ini` wyłącza `proc_open`, a odczyt PDF uruchamia Popplera przez
 * Symfony Process. Testy PHPUnit chodzą BEZ tego pliku, więc w CI wszystko
 * było zielone, a na produkcji każde zadanie `ImportujPrzepisZPdf` padało na
 * „The Process class relies on proc_open, which is not available".
 *
 * Test odtwarza warunki obrazu: podprocesowi PHP dokłada `docker/php.ini`
 * jako OSTATNI plik ze skanowanego katalogu (`PHP_INI_SCAN_DIR` z pustym
 * pierwszym elementem = domyślny katalog + nasz — jak `conf.d/zz-kuking.ini`
 * w `Dockerfile`) i uruchamia w nim prawdziwy `TekstZPdf::odczytaj()` na
 * małym PDF-ie:
 *
 *  - z flagą procesów kolejki z `docker/entrypoint.sh` odczyt MA się udać,
 *  - bez niej (tak jak FrankenPHP i harmonogram) `proc_open` MA nie istnieć,
 *    a odczyt MA skończyć się nazwanym odrzuceniem, nie `LogicException`.
 *    To zarazem kontrola dodatnia: dowód, że podproces naprawdę czyta
 *    produkcyjny `php.ini`, a nie przechodzi, bo ini się nie załadował.
 *
 * Strażnik tekstu pilnuje, żeby odblokowany był TYLKO `proc_open` i TYLKO
 * dla `queue:work` — serwer WWW zostaje przy pełnym utwardzeniu.
 */
final class KolejkaCzytaPdfZProdukcyjnymPhpIniTest extends TestCase
{
    /** @var list<string> */
    private array $doSprzatniecia = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->doSprzatniecia) as $sciezka) {
            is_dir($sciezka) ? @rmdir($sciezka) : @unlink($sciezka);
        }

        parent::tearDown();
    }

    private function entrypoint(): string
    {
        return (string) file_get_contents(base_path('docker/entrypoint.sh'));
    }

    /** @return list<string> */
    private static function lista(string $wartosc): array
    {
        $funkcje = array_values(array_filter(array_map('trim', explode(',', $wartosc)), fn (string $f): bool => $f !== ''));
        sort($funkcje);

        return $funkcje;
    }

    /** @return list<string> */
    private function listaZPhpIni(): array
    {
        $ini = (string) file_get_contents(base_path('docker/php.ini'));
        $this->assertSame(1, preg_match('/^disable_functions\s*=\s*(\S+)\s*$/m', $ini, $m), 'docker/php.ini nie ma już dyrektywy disable_functions.');

        return self::lista($m[1]);
    }

    private function listaKolejki(): string
    {
        $this->assertSame(
            1,
            preg_match('/^FUNKCJE_ZABRONIONE_KOLEJKI="([^"]*)"$/m', $this->entrypoint(), $m),
            'docker/entrypoint.sh nie definiuje FUNKCJE_ZABRONIONE_KOLEJKI — procesy kolejki stracą proc_open i import z PDF padnie na produkcji (#2293).',
        );

        return $m[1];
    }

    public function test_www_zostaje_bez_proc_open_a_kolejce_odblokowany_jest_tylko_proc_open(): void
    {
        $ini = $this->listaZPhpIni();
        $this->assertContains('proc_open', $ini, 'docker/php.ini przestał wyłączać proc_open — serwer WWW stracił utwardzenie.');

        $oczekiwana = array_values(array_diff($ini, ['proc_open']));
        $this->assertSame(
            $oczekiwana,
            self::lista($this->listaKolejki()),
            'Lista funkcji wyłączonych dla kolejki ma być równa liście z docker/php.ini minus proc_open — nic więcej nie wolno odblokować.',
        );
    }

    public function test_flaga_dostaje_wylacznie_queue_work(): void
    {
        $zrodlo = $this->entrypoint();

        $this->assertSame(
            1,
            preg_match('/^jeden_przebieg_kolejki\(\) \{\n(.*?)\n\}$/ms', $zrodlo, $m),
            'Nie znalazłem funkcji jeden_przebieg_kolejki() w docker/entrypoint.sh.',
        );
        $this->assertStringContainsString('queue:work', $m[1]);
        $this->assertStringContainsString(
            '-d "disable_functions=${FUNKCJE_ZABRONIONE_KOLEJKI}"',
            $m[1],
            'queue:work nie dostaje listy funkcji bez proc_open — import z PDF padnie na produkcji (#2293).',
        );

        $this->assertSame(
            1,
            substr_count($zrodlo, 'disable_functions='),
            'disable_functions nadpisane w entrypoincie w więcej niż jednym miejscu — tylko queue:work może dostać proc_open.',
        );
    }

    public function test_odczyt_pdf_dziala_w_procesie_kolejki_z_produkcyjnym_php_ini(): void
    {
        $wynik = $this->odczytajWPodprocesie(['-d', 'disable_functions='.$this->listaKolejki()]);

        $this->assertStringContainsString('proc_open=tak', $wynik, 'Proces kolejki z produkcyjnym php.ini nie ma proc_open. Wyjście: '.$wynik);
        $this->assertStringContainsString('ODCZYT:Sernik babci', $wynik, 'Proces kolejki z produkcyjnym php.ini nie przeczytał PDF-a (#2293). Wyjście: '.$wynik);
    }

    public function test_bez_flagi_kolejki_produkcyjny_php_ini_blokuje_procesy_i_odczyt_mowi_dlaczego(): void
    {
        $wynik = $this->odczytajWPodprocesie([]);

        $this->assertStringContainsString('proc_open=nie', $wynik, 'Podproces nie wczytał docker/php.ini — test nie odtwarza obrazu produkcyjnego. Wyjście: '.$wynik);
        $this->assertStringContainsString('ODRZUCONY:narzedzie_pdf_niedostepne', $wynik, 'Bez proc_open odczyt PDF ma skończyć się nazwanym odrzuceniem, nie LogicException. Wyjście: '.$wynik);
    }

    /** @param  list<string>  $flagi */
    private function odczytajWPodprocesie(array $flagi): string
    {
        $katalog = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kuking-ini-'.bin2hex(random_bytes(6));
        mkdir($katalog, 0700);
        $this->doSprzatniecia[] = $katalog;

        $ini = $katalog.DIRECTORY_SEPARATOR.'zz-kuking.ini';
        copy(base_path('docker/php.ini'), $ini);
        $this->doSprzatniecia[] = $ini;

        $pdf = $katalog.DIRECTORY_SEPARATOR.'przepis.pdf';
        file_put_contents($pdf, MalyPdf::zTekstem([
            ['Sernik babci', 'Skladniki', '1 kg twarogu', 'Przygotowanie', '1. Utrzyj twarog.'],
        ]));
        $this->doSprzatniecia[] = $pdf;

        $skrypt = $katalog.DIRECTORY_SEPARATOR.'odczyt.php';
        file_put_contents($skrypt, <<<'PHP'
            <?php
            require $argv[1].'/vendor/autoload.php';
            $app = require $argv[1].'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            echo 'proc_open=', function_exists('proc_open') ? 'tak' : 'nie', "\n";
            try {
                echo 'ODCZYT:', (new App\Domain\Import\Pdf\TekstZPdf)->odczytaj($argv[2]), "\n";
            } catch (App\Domain\Import\ImportOdrzucony $e) {
                echo 'ODRZUCONY:', $e->kod, "\n";
            } catch (Throwable $e) {
                echo 'WYJATEK:', get_class($e), ': ', $e->getMessage(), "\n";
            }
            PHP);
        $this->doSprzatniecia[] = $skrypt;

        $skan = getenv('PHP_INI_SCAN_DIR');
        $wynik = Process::env([
            // Pusty pierwszy element = domyślny katalog conf.d tej instalacji PHP
            // (rozszerzenia), a nasz plik ładuje się po nim — jak w obrazie.
            'PHP_INI_SCAN_DIR' => ($skan === false ? '' : $skan).PATH_SEPARATOR.$katalog,
            'APP_BASE_PATH' => base_path(),
            'LOG_CHANNEL' => 'stderr',
        ])->timeout(60)->run([PHP_BINARY, ...$flagi, $skrypt, base_path(), $pdf]);

        return $wynik->output().$wynik->errorOutput();
    }
}
