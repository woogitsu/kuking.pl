<?php

declare(strict_types=1);

namespace App\Domain\Social;

/**
 * Wiersze `blocks` zmieniły się (dodane albo skasowane) w tym żądaniu.
 *
 * Domena nie zna warstwy HTTP, więc zamiast czyścić pamięć żądania sama
 * ogłasza zmianę; nasłuch w `AppServiceProvider` czyści
 * `App\Http\Support\BlokadyWZadaniu` (audyt wydajności, P3 W8).
 */
final class BlokadyZmienione {}
