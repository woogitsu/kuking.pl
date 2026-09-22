<?php

declare(strict_types=1);

namespace Tests\Support;

/** Ładowany wyłącznie wewnątrz osobnego procesu testowego. */
final class LicznikDekodowanGd
{
    public static int $liczba = 0;
}

namespace Intervention\Image\Drivers\Gd\Decoders;

use Tests\Support\LicznikDekodowanGd;

/** Liczymy prawdziwe dekodowania, nie zastępujemy ich wyniku atrapą. */
function imagecreatefromstring(string $bytes): \GdImage|false
{
    LicznikDekodowanGd::$liczba++;

    return \imagecreatefromstring($bytes);
}
