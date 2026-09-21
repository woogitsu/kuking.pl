<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;
use Monolog\Logger;

/**
 * Fabryka kanału `logging.blad_email` (sterownik `custom`, `config/logging.php`).
 *
 * Bliźniak `WebhookBleduLogger` — ta sama umowa, inny środek transportu.
 * Zero nowych zależności Composera: `Monolog\Logger` i `Mail` są już częścią
 * frameworka, a transport EmailLabs stoi w tym repozytorium od dawna
 * (`app/Poczta/TransportEmailLabs.php`) i jest jedyną pocztą, jakiej ten
 * serwis używa na produkcji.
 */
final class EmailBleduLogger
{
    /**
     * Laravel przekazuje tu CAŁY wpis kanału z `config/logging.php`
     * (`driver`, `via`, `adres`, `level`) — nie tylko to, czego używamy.
     *
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('blad_email');

        $logger->pushHandler(new EmailBleduHandler(
            adres: $this->adres($config),
            level: $this->poziom($config['level'] ?? 'error'),
        ));

        return $logger;
    }

    /**
     * `null`, gdy `LOG_BLAD_EMAIL` jest puste albo nie jest tekstem —
     * `EmailBleduHandler` traktuje `null` jako „kanał wyłączony, nic nie
     * wysyłaj" (wymóg: brak zmiennej = zero efektu, zero wyjątku, dokładnie
     * jak dziś z webhookiem).
     *
     * @param  array<string, mixed>  $config
     */
    private function adres(array $config): ?string
    {
        $adres = $config['adres'] ?? null;

        return is_string($adres) && trim($adres) !== '' ? trim($adres) : null;
    }

    /**
     * Nazwa poziomu z configu na enum Monologa, z cichym fallbackiem na
     * `Error` przy literówce albo brakującym kluczu. Ten fallback jest
     * ŚWIADOMY: to jest kanał AWARYJNY — zła wartość w configu nie ma prawa
     * sama stać się kolejną przyczyną błędu 500.
     */
    private function poziom(mixed $poziom): Level
    {
        return match (is_string($poziom) ? strtolower($poziom) : '') {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Error,
        };
    }
}
