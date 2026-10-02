<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Polskie znaki nie są zapisane w zepsutym kodowaniu (mojibake).
 *
 * Przy scalaniu paczki L do C trafił komunikat Planera „Tej pozycji juÅ¼ nie ma
 * w planie. OdÅ›wieÅ¼ stronÄ™” — tekst UTF-8 odczytany raz jako Latin-1 i zapisany
 * ponownie. Taki napis przechodzi wszystkie testy, które sprawdzają tylko kod
 * odpowiedzi, a człowiek 50+ widzi krzaki zamiast wskazówki, co zrobić.
 *
 * Listy sekwencji nie wpisujemy ręcznie: liczymy ją z polskich liter, czytając
 * ich bajty UTF-8 jako ISO-8859-1 i jako Windows-1252 (dwa najczęstsze sposoby
 * psucia). Sekwencje Windows-1252 z nieprzypisanym bajtem (`?`) pomijamy, bo
 * w takim przypadku i tak zostaje sekwencja ISO-8859-1.
 *
 * Kontrola ujemna: wpis w `scripts/kontrole-negatywne-alfa08.py` psuje kodowanie
 * jednego komunikatu w `PlanerController` i ten test ma zapalić.
 */
class BrakZepsutegoKodowaniaPolskichZnakowTest extends TestCase
{
    private const POLSKIE_LITERY = 'ąćęłńóśźżĄĆĘŁŃÓŚŹŻ';

    /** Katalogi z kodem i widokami; w `resources/` także pliki .md. */
    private const KATALOGI = ['app', 'resources/views', 'resources/js', 'lang', 'config', 'routes', 'database'];

    private const ROZSZERZENIA = ['php', 'js', 'mjs'];

    public function test_kod_i_widoki_nie_zawieraja_polskich_liter_w_zepsutym_kodowaniu(): void
    {
        $sekwencje = self::sekwencjeMojibake();
        $pliki = self::pliki();

        $this->assertGreaterThan(100, count($pliki), 'Strażnik nie znalazł plików do sprawdzenia.');

        $znalezione = [];
        foreach ($pliki as $plik) {
            $znalezione = [...$znalezione, ...self::szukaj((string) file_get_contents($plik), $sekwencje, self::wzgledna($plik))];
        }

        $this->assertSame(
            [],
            $znalezione,
            "Polskie litery zapisane w zepsutym kodowaniu (UTF-8 odczytane jako Latin-1). Wpisz w tych miejscach właściwe litery:\n"
            .implode("\n", $znalezione),
        );
    }

    public function test_kontrola_dodatnia_lista_sekwencji_i_wyszukiwanie_lapia_zepsuty_napis(): void
    {
        $sekwencje = self::sekwencjeMojibake();

        $this->assertContains('Å¼', $sekwencje);
        $this->assertContains('Ä…', $sekwencje);
        $this->assertContains('Ã³', $sekwencje);
        $this->assertNotContains('ż', $sekwencje);

        $zepsuty = mb_convert_encoding("<?php\n\$a = 'ok';\n\$b = 'Odśwież stronę';\n", 'UTF-8', 'ISO-8859-1');
        $wynik = self::szukaj($zepsuty, $sekwencje, 'przyklad.php');

        $this->assertCount(1, $wynik);
        $this->assertStringContainsString('przyklad.php:3', $wynik[0]);
        $this->assertSame([], self::szukaj("<?php\n\$b = 'Odśwież stronę, Łódź, ŻÓŁW';\n", $sekwencje, 'dobry.php'));
    }

    /** @return list<string> */
    private static function sekwencjeMojibake(): array
    {
        $sekwencje = [];
        foreach (mb_str_split(self::POLSKIE_LITERY) as $litera) {
            foreach (['ISO-8859-1', 'Windows-1252'] as $kodowanie) {
                $zepsuta = mb_convert_encoding($litera, 'UTF-8', $kodowanie);
                if ($zepsuta !== $litera && ! str_contains($zepsuta, '?')) {
                    $sekwencje[] = $zepsuta;
                }
            }
        }

        return array_values(array_unique($sekwencje));
    }

    /**
     * @param  list<string>  $sekwencje
     * @return list<string> „plik:linia: fragment”
     */
    private static function szukaj(string $tresc, array $sekwencje, string $nazwa): array
    {
        $wynik = [];
        foreach (preg_split('/\R/u', $tresc) ?: [] as $nr => $linia) {
            foreach ($sekwencje as $sekwencja) {
                if (str_contains($linia, $sekwencja)) {
                    $wynik[] = $nazwa.':'.($nr + 1).': '.trim(mb_substr($linia, 0, 160));
                    break;
                }
            }
        }

        return $wynik;
    }

    /** @return list<string> */
    private static function pliki(): array
    {
        $pliki = [];
        foreach (self::KATALOGI as $katalog) {
            $sciezka = base_path($katalog);
            if (! is_dir($sciezka)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sciezka, FilesystemIterator::SKIP_DOTS));
            /** @var SplFileInfo $plik */
            foreach ($iterator as $plik) {
                if ($plik->isFile() && in_array($plik->getExtension(), self::ROZSZERZENIA, true)) {
                    $pliki[] = $plik->getPathname();
                }
            }
        }

        $zasoby = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources'), FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $plik */
        foreach ($zasoby as $plik) {
            if ($plik->isFile() && $plik->getExtension() === 'md') {
                $pliki[] = $plik->getPathname();
            }
        }

        $pliki = array_values(array_unique($pliki));
        sort($pliki);

        return $pliki;
    }

    private static function wzgledna(string $plik): string
    {
        return ltrim(substr($plik, strlen(base_path())), '/');
    }
}
