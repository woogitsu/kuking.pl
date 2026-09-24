<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Opisy w kodzie nie udają działającego przeliczania porcji (#741).
 *
 * Skalowanie porcji to plan V2 (`docs/FEATURES.md`) — strona przepisu nie ma
 * przelicznika. Po uproszczeniu formularza (#364) trzy komentarze twierdziły,
 * że „przeliczanie porcji działa dalej”. Kolejny autor albo agent czyta taki
 * komentarz jako dowód istniejącej funkcji i obiecuje ją dalej.
 *
 * Reguła: każde zdanie o przeliczaniu/skalowaniu porcji w `app/`
 * i `resources/views/` mówi w tym samym albo następnym wierszu „V2”,
 * czyli wprost, że to zamiar, nie działająca funkcja. Gdy przelicznik
 * naprawdę powstanie, ten test należy usunąć razem z wpisem w FEATURES.md.
 */
final class OpisySkalowaniaPorcjiMowiaOPlanieV2Test extends TestCase
{
    private const WZOR = '/(przelicza\w*|skalowa\w*)\s+porcji/iu';

    public function test_kazda_wzmianka_o_przeliczaniu_porcji_nazywa_ja_planem_v2(): void
    {
        $znalezione = 0;
        $bez = [];

        foreach (['app', 'resources/views'] as $katalog) {
            $pliki = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($katalog)));

            foreach ($pliki as $plik) {
                if (! $plik->isFile() || $plik->getExtension() !== 'php') {
                    continue;
                }

                $wiersze = file($plik->getPathname(), FILE_IGNORE_NEW_LINES);

                foreach ($wiersze as $i => $wiersz) {
                    if (preg_match(self::WZOR, $wiersz) !== 1) {
                        continue;
                    }

                    $znalezione++;
                    $okno = $wiersz.' '.($wiersze[$i + 1] ?? '');

                    if (! str_contains($okno, 'V2')) {
                        $bez[] = str_replace(base_path().'/', '', $plik->getPathname()).':'.($i + 1).': '.trim($wiersz);
                    }
                }
            }
        }

        // Skan, który nic nie znajduje, nie mierzy niczego (docs/PULAPKI_TESTOW.md).
        $this->assertGreaterThanOrEqual(5, $znalezione, 'Skan nie znalazł znanych wzmianek — sprawdź ścieżki i wzór.');
        $this->assertSame([], $bez, "Opis mówi o przeliczaniu porcji bez „V2” — dopisz, że to plan, nie działająca funkcja:\n".implode("\n", $bez));
    }
}
