<?php

declare(strict_types=1);

namespace App\Domain\Media;

use Intervention\Image\Direction;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Ustawia zdjęcie tak, jak trzymano telefon.
 *
 * PO CO OSOBNA KLASA (issue #430)
 * Bo obracają dziś DWA miejsca i muszą obracać identycznie: `podglad`
 * powstaje synchronicznie w `StoreUploadedImage` (przez `PodgladOdRazu`),
 * a `thumb`/`feed`/`large` w tle, w `ProcessUploadedImage`. Gdyby te dwa
 * miejsca miały własne kopie tej tablicy, autorka zobaczyłaby po publikacji
 * zdjęcie ustawione tak, a po odświeżeniu — inaczej. Nie wywaliłoby to
 * żadnego testu, bo każde miejsce z osobna byłoby poprawne.
 *
 * Znacznik EXIF Orientation ma osiem wartości i cztery z nich to odbicia
 * lustrzane, nie same obroty. Pomijanie ich dawałoby zdjęcia poprawnie
 * obrócone, ale odbite — co przy zdjęciu kartki z przepisem oznacza tekst
 * czytany od tyłu.
 *
 * Wartość czytana jest przy WGRANIU (`StoreUploadedImage::readOrientation`),
 * bo zadanie w tle dostaje ze storage same bajty, a dekoder pracuje
 * z `autoOrientation: false` — patrz komentarz w `ProcessUploadedImage`.
 */
final class OrientacjaZdjecia
{
    public static function zastosuj(ImageInterface $image, ?int $orientacja): void
    {
        if ($orientacja === null || $orientacja === 1) {
            return;
        }

        // INTERVENTION 4 ZMIENIŁ ZNACZENIE TYCH WYWOŁAŃ (issue #2336).
        // W wersji 3 `rotate()` kręcił przeciwnie do ruchu wskazówek zegara,
        // `flip()` odbijał w pionie, a `flop()` w poziomie. W wersji 4
        // `rotate()` kręci ZGODNIE z ruchem wskazówek, a `flip()` bierze
        // kierunek (domyślnie poziomy); `flop()` nie istnieje. Stąd znaki
        // kątów są tu przeciwne niż przed migracją, a wynik ten sam — pilnuje
        // tego `OrientacjaZdjeciaTest` na pikselach, nie na wymiarach.
        match ($orientacja) {
            2 => $image->flip(Direction::HORIZONTAL),
            3 => $image->rotate(180),
            4 => $image->flip(Direction::VERTICAL),
            5 => $image->rotate(90)->flip(Direction::HORIZONTAL),
            6 => $image->rotate(90),
            7 => $image->rotate(-90)->flip(Direction::HORIZONTAL),
            8 => $image->rotate(-90),
            default => null,
        };
    }
}
