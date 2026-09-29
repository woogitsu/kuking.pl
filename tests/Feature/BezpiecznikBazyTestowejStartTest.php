<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Bezpiecznik z D-334 jest WŁĄCZONY w starcie PHPUnita i nie robi fałszywych
 * odmów tam, gdzie repozytorium naprawdę uruchamia testy.
 *
 * Trzy rzeczy, których nie pokaże test samej funkcji (`BezpiecznikBazyTestowejTest`):
 *
 *  1. `tests/bootstrap.php` faktycznie ją woła — uruchamiamy go jako osobny
 *     proces PHP z podstawioną bazą. Nic się nie łączy z bazą: odmowa (i
 *     przejście) następuje przed `vendor/autoload.php`.
 *  2. Job CI, który uruchamia `artisan test`, nie ma ustawionej bazy spoza rodziny —
 *     inaczej bezpiecznik zatrzymałby CI, a lokalnie nikt by tego nie zauważył.
 *  3. To samo dla skryptów w `scripts/` i `tests/skrypty/` (stanowiska floty,
 *     kontrole ujemne, `check.sh`).
 *
 * @bez-kontroli-dodatniej skan CI i skryptów ma własny test na sztucznym wejściu (`test_skan_ci_zapala_sie_na_zlej_bazie_w_jobie_z_testami`), który pokazuje, że potrafi zapalić; start bootstrapu jest sprawdzany procesem, nie tekstem.
 */
class BezpiecznikBazyTestowejStartTest extends TestCase
{
    /** Polecenia, po których poznajemy, że blok uruchamia PHPUnita. */
    private const POLECENIE_TESTOW = '/artisan test|bin\/phpunit|testy-dwa-polaczenia\.sh|kontrole-negatywne-alfa08\.py/';

    #[Test]
    public function test_bootstrap_odmawia_startu_na_bazie_deweloperskiej(): void
    {
        [$kod, $wyjscie] = $this->uruchomBootstrap(['DB_DATABASE' => 'kuking', 'DB_URL' => '']);

        $this->assertSame(2, $kod, 'Bootstrap na bazie „kuking” musi kończyć się kodem 2. Wyjście: '.$wyjscie);
        $this->assertStringContainsString('ODMAWIAM STARTU', $wyjscie);
        $this->assertStringContainsString('„kuking”', $wyjscie);
        $this->assertStringContainsString('unset DB_DATABASE DB_URL', $wyjscie);
    }

    #[Test]
    public function test_bootstrap_odmawia_startu_gdy_db_url_kieruje_poza_rodzine(): void
    {
        [$kod, $wyjscie] = $this->uruchomBootstrap([
            'DB_DATABASE' => 'kuking_test',
            'DB_URL' => 'postgres://uzytkownik:tajne-haslo@db.example:5432/railway',
        ]);

        $this->assertSame(2, $kod, 'Wyjście: '.$wyjscie);
        $this->assertStringContainsString('DB_URL', $wyjscie);
        $this->assertStringNotContainsString('tajne-haslo', $wyjscie);
    }

    #[Test]
    public function test_bootstrap_przepuszcza_bazy_z_rodziny_testowej(): void
    {
        foreach (['kuking_test', 'kuking_test_wt_x', 'kuking_race_wt_x', 'kuking_flota_gpt-onboarding'] as $baza) {
            [$kod, $wyjscie] = $this->uruchomBootstrap(['DB_DATABASE' => $baza, 'DB_URL' => '']);

            $this->assertSame(0, $kod, 'Fałszywa odmowa dla '.$baza.'. Wyjście: '.$wyjscie);
            $this->assertSame('', $wyjscie, 'Bezpiecznik nie ma nic mówić, gdy wszystko w porządku ('.$baza.').');
        }
    }

    #[Test]
    public function test_bootstrap_bez_jawnej_bazy_liczy_wlasna_i_ja_przepuszcza(): void
    {
        // Zwykły `php artisan test` bez żadnej zmiennej — najczęstszy przypadek.
        [$kod, $wyjscie] = $this->uruchomBootstrap(['DB_DATABASE' => false, 'DB_URL' => false]);

        $this->assertSame(0, $kod, 'Wyjście: '.$wyjscie);
        $this->assertSame('', $wyjscie);
    }

    #[Test]
    public function test_joby_ci_z_testami_nie_maja_bazy_spoza_rodziny(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
        $this->assertIsString($workflow);

        $sprawdzone = $this->sprawdzKrokiCi($workflow);

        $this->assertSame([], $sprawdzone['bledy'], implode("\n", $sprawdzone['bledy']));
        // Pułapka 2 z docs/PULAPKI_TESTOW.md: skan, który niczego nie znalazł, nie może przejść.
        $this->assertGreaterThanOrEqual(3, $sprawdzone['kroki'], 'Skan nie znalazł kroków z testami w ci.yml — zmieniło się jego czytanie.');
    }

    #[Test]
    public function test_skan_ci_zapala_sie_na_zlej_bazie_w_jobie_z_testami(): void
    {
        $zly = <<<'YAML'
jobs:
  test:
    runs-on: ubuntu-latest
    env:
      DB_DATABASE: kuking
    steps:
      - name: Testy
        run: php artisan test
YAML;
        $zlyKrok = <<<'YAML'
jobs:
  test:
    runs-on: ubuntu-latest
    env:
      DB_DATABASE: kuking_test
    steps:
      - name: Testy
        env:
          DB_DATABASE: railway
        run: php artisan test
YAML;
        $dobry = <<<'YAML'
jobs:
  test:
    runs-on: ubuntu-latest
    env:
      DB_DATABASE: kuking_test
    steps:
      - name: Pomiar
        env:
          DB_DATABASE: kuking_port_pomiar
        run: node scripts/port-projektu.mjs
      - name: Testy
        run: php artisan test
YAML;

        $this->assertCount(1, $this->sprawdzKrokiCi($zly)['bledy']);
        $this->assertCount(1, $this->sprawdzKrokiCi($zlyKrok)['bledy']);
        $this->assertSame([], $this->sprawdzKrokiCi($dobry)['bledy'], 'Krok z pomiarem (node) nie jest testem i ma prawo mieć własną bazę.');
        $this->assertSame(1, $this->sprawdzKrokiCi($dobry)['kroki']);
    }

    #[Test]
    public function test_skrypty_uruchamiajace_testy_nie_podaja_bazy_spoza_rodziny(): void
    {
        $pliki = array_merge(
            glob(base_path('scripts/*.sh')) ?: [],
            glob(base_path('scripts/*/*.sh')) ?: [],
            glob(base_path('tests/skrypty/*.sh')) ?: [],
        );

        $this->assertNotEmpty($pliki);

        $bledy = [];
        $zTestami = 0;

        foreach ($pliki as $plik) {
            $tresc = file_get_contents($plik);

            if (! is_string($tresc) || preg_match(self::POLECENIE_TESTOW, $tresc) !== 1) {
                continue;
            }

            $zTestami++;

            foreach (explode("\n", $tresc) as $numer => $wiersz) {
                $bez = ltrim($wiersz);

                // Komentarze i przyrządy Node (dostepnosc.mjs, wydajnosc.mjs…) nie uruchamiają PHPUnita.
                if (str_starts_with($bez, '#') || str_contains($wiersz, ' node ')) {
                    continue;
                }

                if (preg_match_all('/(?<![A-Z_])DB_DATABASE=([A-Za-z0-9_-]+)/', $wiersz, $trafienia) < 1) {
                    continue;
                }

                foreach ($trafienia[1] as $baza) {
                    if (kuking_ocen_baze_testowa($baza) !== null) {
                        $bledy[] = str_replace(base_path().'/', '', $plik).':'.($numer + 1).' podaje bazę „'.$baza.'”, którą bezpiecznik odrzuci.';
                    }
                }
            }
        }

        $this->assertSame([], $bledy, implode("\n", $bledy));
        $this->assertGreaterThanOrEqual(5, $zTestami, 'Skan nie znalazł skryptów uruchamiających testy — zmieniło się jego czytanie.');
    }

    /**
     * Rozbija workflow na kroki (`- name:` na 6 spacjach) i dla każdego kroku,
     * który uruchamia PHPUnita, sprawdza bazę: krokową, a gdy jej nie ma — z env joba.
     *
     * @return array{bledy: list<string>, kroki: int}
     */
    private function sprawdzKrokiCi(string $workflow): array
    {
        $bledy = [];
        $kroki = 0;

        $joby = preg_split('/^  ([A-Za-z0-9_-]+):\s*$/m', $workflow, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        // [0] = nagłówek pliku, potem naprzemiennie: nazwa joba, treść joba.
        for ($i = 1; $i + 1 < count($joby); $i += 2) {
            $nazwa = $joby[$i];
            $tresc = $joby[$i + 1];

            $kawalki = preg_split('/^      - /m', $tresc) ?: [];
            $naglowek = (string) array_shift($kawalki);

            $bazaJoba = preg_match('/^    env:\s*\n(?:      .*\n|\s*\n|      #.*\n)*?      DB_DATABASE:\s*"?([A-Za-z0-9_-]+)"?/m', $naglowek, $m) === 1
                ? $m[1]
                : null;

            foreach ($kawalki as $krok) {
                if (preg_match(self::POLECENIE_TESTOW, $krok) !== 1) {
                    continue;
                }

                $kroki++;

                $baza = preg_match('/^\s*DB_DATABASE:\s*"?([A-Za-z0-9_-]+)"?\s*$/m', $krok, $k) === 1
                    ? $k[1]
                    : $bazaJoba;

                if ($baza !== null && kuking_ocen_baze_testowa($baza) !== null) {
                    $bledy[] = 'Job „'.$nazwa.'” uruchamia testy na bazie „'.$baza.'”, którą bezpiecznik odrzuci.';
                }
            }
        }

        return ['bledy' => $bledy, 'kroki' => $kroki];
    }

    /**
     * Uruchamia `tests/bootstrap.php` jako osobny proces PHP.
     *
     * @param  array<string, string|false>  $srodowisko  false = zmienna nieustawiona
     * @return array{int, string} kod wyjścia i połączone stdout+stderr
     */
    private function uruchomBootstrap(array $srodowisko): array
    {
        $proces = new Process([PHP_BINARY, base_path('tests/bootstrap.php')], base_path(), $srodowisko);
        $proces->setTimeout(60);
        $proces->run();

        return [(int) $proces->getExitCode(), $proces->getOutput().$proces->getErrorOutput()];
    }
}
