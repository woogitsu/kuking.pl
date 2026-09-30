<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;
use Monolog\Logger;

/**
 * Fabryka kanału `logging.blad_email` (sterownik `custom`, #599).
 *
 * Poziom jest na sztywno `error` — ta sama umowa co `blad_webhook`: kanał
 * awaryjny, zła wartość w konfiguracji nie może go uciszyć.
 */
final class EmailBleduLogger
{
    /** @param  array<string, mixed>  $config */
    public function __invoke(array $config): Logger
    {
        $adres = $config['adres'] ?? null;

        $logger = new Logger('blad_email');
        $logger->pushHandler(new EmailBleduHandler(
            adres: is_string($adres) && trim($adres) !== '' ? trim($adres) : null,
            level: Level::Error,
        ));

        return $logger;
    }
}
