<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * „Tap" kanałów logu serwera (`config/logging.php`): podpina
 * `BezDanychOsobowychWLogu` na poziomie loggera, więc działa przed KAŻDYM
 * formaterem (JSON na Railway, tekst lokalnie). Tap zamiast `processors`,
 * bo `processors` obsługuje tylko sterownik `monolog`, a `single`/`daily` nie.
 * Kanał `stack` dziedziczy procesory kanałów, z których się składa.
 */
final class FiltrDanychOsobowych
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new BezDanychOsobowychWLogu);
        }
    }
}
