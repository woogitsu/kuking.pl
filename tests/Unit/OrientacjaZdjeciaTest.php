<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Media\OrientacjaZdjecia;
use Intervention\Image\Drivers\Gd\Driver as SterownikGd;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Orientacja EXIF 1-8 sprawdzana NA PIKSELACH (issue #2336).
 *
 * DLACZEGO TO NIE JEST POWIELENIE `ZdjecieNieJestObracaneDwaRazyTest`
 * Tamten test mierzy same wymiary: jedno obrócenie daje zdjęcie pionowe,
 * dwa — poziome. Nie odróżnia jednak obrotu w prawo od obrotu w lewo ani
 * odbicia poziomego od pionowego, a Intervention 4 odwrócił znaczenie
 * `rotate()` (teraz zgodnie z ruchem wskazówek) i `flip()` (teraz poziomo).
 * Stary kod po cichej aktualizacji dawałby zdjęcia obrócone o 180° albo
 * odbite lustrzanie — a tekst na kartce z przepisem czytany od tyłu nie
 * przechodzi żadnej kontroli rozmiaru.
 *
 * Wzorzec oczekiwany jest wzięty z tabeli w specyfikacji EXIF (co zrobić
 * z zapisanymi pikselami, żeby zdjęcie stało tak, jak trzymano telefon),
 * a NIE z kodu `OrientacjaZdjecia` ani z dekodera biblioteki.
 */
final class OrientacjaZdjeciaTest extends TestCase
{
    private const SZEROKOSC = 3;

    private const WYSOKOSC = 2;

    /**
     * Piksel (x, y) obrazu wejściowego ma kolor unikalny dla pozycji.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function kolor(int $x, int $y): array
    {
        return [40 + 70 * $x, 40 + 100 * $y, 200];
    }

    /**
     * Współrzędne piksela wejściowego, który według EXIF ma trafić na (x, y)
     * obrazu wynikowego; `szer` i `wys` to wymiary WEJŚCIA.
     *
     * @return array{0: int, 1: int}
     */
    private static function zrodlo(int $orientacja, int $x, int $y, int $szer, int $wys): array
    {
        return match ($orientacja) {
            1 => [$x, $y],
            2 => [$szer - 1 - $x, $y],
            3 => [$szer - 1 - $x, $wys - 1 - $y],
            4 => [$x, $wys - 1 - $y],
            5 => [$y, $x],
            6 => [$y, $wys - 1 - $x],
            7 => [$szer - 1 - $y, $wys - 1 - $x],
            8 => [$szer - 1 - $y, $x],
            default => throw new \InvalidArgumentException("Nieznana orientacja EXIF: $orientacja"),
        };
    }

    /** @return array<string, array{0: int}> */
    public static function orientacje(): array
    {
        $przypadki = [];

        foreach (range(1, 8) as $orientacja) {
            $przypadki["orientacja $orientacja"] = [$orientacja];
        }

        return $przypadki;
    }

    private function wejscie(): string
    {
        $obraz = imagecreatetruecolor(self::SZEROKOSC, self::WYSOKOSC);

        for ($y = 0; $y < self::WYSOKOSC; $y++) {
            for ($x = 0; $x < self::SZEROKOSC; $x++) {
                [$r, $g, $b] = self::kolor($x, $y);
                imagesetpixel($obraz, $x, $y, (int) imagecolorallocate($obraz, $r, $g, $b));
            }
        }

        ob_start();
        imagepng($obraz);

        return (string) ob_get_clean();
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function piksel(\GdImage $gd, int $x, int $y): array
    {
        $kolor = imagecolorat($gd, $x, $y);

        return [($kolor >> 16) & 0xFF, ($kolor >> 8) & 0xFF, $kolor & 0xFF];
    }

    #[DataProvider('orientacje')]
    public function test_piksele_ida_tam_gdzie_chce_exif(int $orientacja): void
    {
        $obraz = ImageManager::usingDriver(SterownikGd::class, autoOrientation: false)
            ->decodeBinary($this->wejscie());

        OrientacjaZdjecia::zastosuj($obraz, $orientacja);

        $odwrocone = in_array($orientacja, [5, 6, 7, 8], true);
        $this->assertSame($odwrocone ? self::WYSOKOSC : self::SZEROKOSC, $obraz->width(), "orientacja $orientacja: szerokość");
        $this->assertSame($odwrocone ? self::SZEROKOSC : self::WYSOKOSC, $obraz->height(), "orientacja $orientacja: wysokość");

        $gd = $obraz->core()->native();

        for ($y = 0; $y < $obraz->height(); $y++) {
            for ($x = 0; $x < $obraz->width(); $x++) {
                [$sx, $sy] = self::zrodlo($orientacja, $x, $y, self::SZEROKOSC, self::WYSOKOSC);

                $this->assertSame(
                    self::kolor($sx, $sy),
                    self::piksel($gd, $x, $y),
                    "orientacja $orientacja: piksel ($x, $y) powinien pochodzić z ($sx, $sy) obrazu wejściowego",
                );
            }
        }
    }

    /**
     * KONTROLA DODATNIA wzorca: różne orientacje naprawdę dają różne obrazy.
     * Bez tego pomyłka w tabeli `zrodlo()` (np. dwa identyczne wiersze) cicho
     * osłabiłaby cały test.
     */
    public function test_wzorzec_rozroznia_wszystkie_orientacje(): void
    {
        $odciski = [];

        foreach (range(1, 8) as $orientacja) {
            $odwrocone = in_array($orientacja, [5, 6, 7, 8], true);
            $szer = $odwrocone ? self::WYSOKOSC : self::SZEROKOSC;
            $wys = $odwrocone ? self::SZEROKOSC : self::WYSOKOSC;
            $wzor = [];

            for ($y = 0; $y < $wys; $y++) {
                for ($x = 0; $x < $szer; $x++) {
                    $wzor[] = self::zrodlo($orientacja, $x, $y, self::SZEROKOSC, self::WYSOKOSC);
                }
            }

            $odciski[$orientacja] = $szer.'x'.$wys.json_encode($wzor);
        }

        $this->assertCount(8, array_unique($odciski));
    }
}
