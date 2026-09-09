<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CzytaJobDostepnosci;
use Tests\TestCase;

/**
 * Job, który do sprawdzenia dostępności musi odpytać cudze repozytorium
 * pakietów, oblewa PR-y z powodów niezwiązanych ze zmianą w PR-ze.
 *
 * CO SIĘ STAŁO 9 WRZEŚNIA 2026, WIECZOREM
 * Krok „Przeglądarka" wołał `npx playwright install --with-deps chromium`.
 * Flaga `--with-deps` przełącza się na roota i robi `apt-get update`, a na
 * maszynie z runnerami w liście źródeł siedzi PPA `ondrej/php` (dodane kiedyś
 * przez `setup-php`). Tego wieczoru źródło zaczęło zwracać 404 na plik
 * Release dla Ubuntu „resolute":
 *
 *     Err:4 https://ppa.setup-php.com/ondrej/php/ubuntu resolute Release
 *       404  Not Found [IP: 172.66.155.62 443]
 *     E: The repository ... does not have a Release file.
 *     Failed to install browsers
 *     Error: Installation process exited with code: 100
 *
 * `apt-get update` skończył się niezerowo, Playwright przerwał instalację,
 * a job umarł po 42 sekundach — zanim zobaczył pierwszy ekran. Skan axe nie
 * znalazł niczego złego w kodzie, bo nigdy się nie uruchomił. Blokowało to
 * cztery PR-y naraz (#209, #211, #212, #214) i było DRUGĄ z rzędu awarią
 * tego joba, która nie miała nic wspólnego z jakością stron (pierwsza:
 * issue #215).
 *
 * DLACZEGO USUNIĘCIE FLAGI JEST BEZPIECZNE
 * Biblioteki systemowe Chromium instaluje się na tej maszynie raz. `--with-deps`
 * miało sens na runnerze GitHuba, który wstaje od zera; nasze runnery stoją na
 * jednym, stałym systemie. Gdy kiedyś naprawdę zabraknie którejś biblioteki,
 * Playwright powie to wprost przy starcie przeglądarki i wymieni pakiety —
 * job pójdzie na czerwono z prawdziwą przyczyną, a doinstalowanie jest wtedy
 * jednorazową robotą na maszynie, nie przy każdym przebiegu CI.
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Że przeglądarka wstanie. Dowodzi jednego: że przygotowanie przeglądarki
 * w tym jobie nie sięga do apta, czyli że przebieg CI nie zależy już od
 * dostępności cudzego repozytorium pakietów.
 */
class JobDostepnosciNieWolaAptaTest extends TestCase
{
    use CzytaJobDostepnosci;

    #[Test]
    public function przygotowanie_przegladarki_nie_siega_do_apta(): void
    {
        $job = $this->jobDostepnosci();

        // Kontrola metody pomiaru: gdybym czytał zły fragment pliku, test
        // przechodziłby „bo nie ma tam apta" — i nie mierzyłby niczego.
        $this->assertStringContainsString(
            'playwright install',
            $job,
            'W czytanym fragmencie nie ma instalacji przeglądarki. Czytam zły job albo zły plik.',
        );

        // KOMENTARZE MUSZĄ ZOSTAĆ POZA POMIAREM — i to nie jest drobiazg:
        // pierwsza wersja tego testu oblewała się na WŁASNYM komentarzu
        // w `ci.yml`, który tłumaczy, dlaczego `--with-deps` tam nie ma.
        // Test, który zabrania opisać usuniętą flagę, zabraniałby też
        // wyjaśnić następnemu czytającemu, po co to usunięcie.
        $polecenia = implode("\n", array_map(
            static fn (string $wiersz): string => (string) preg_replace('/#.*$/', '', $wiersz),
            preg_split('/\R/', $job) ?: [],
        ));

        foreach (['--with-deps', 'install-deps', 'apt-get', 'apt install'] as $wzorzec) {
            $this->assertStringNotContainsString(
                $wzorzec,
                $polecenia,
                'Job `dostepnosc` znowu woła apta ('.$wzorzec.'). Wtedy przebieg CI zależy od tego, '
                .'czy cudze repozytorium pakietów akurat odpowiada — 9 września PPA `ondrej/php` '
                .'zwróciło 404 na plik Release i job padł na czterech PR-ach, nie sprawdziwszy ani '
                .'jednego ekranu. Biblioteki systemowe doinstaluj RAZ na maszynie z runnerami, '
                .'a nie przy każdym przebiegu.',
            );
        }
    }
}
