<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;
use Monolog\Logger;

/**
 * Fabryka kanału `logging.blad_webhook` (sterownik `custom`, `config/logging.php`).
 *
 * PO CO WŁASNA FABRYKA, A NIE WBUDOWANY STEROWNIK `slack`
 * `LogManager::createSlackDriver()` buduje `Monolog\Handler\SlackWebhookHandler`,
 * który łączy się bezpośrednio przez `curl_init()`, z pominięciem klienta HTTP
 * Laravela — nie da się tego przechwycić `Http::fake()` w testach, więc nie da
 * się DOWIEŚĆ testem, że treść wysłana na zewnątrz nie niesie danych osobowych.
 * Ten sterownik zamiast tego wywołuje `WebhookBleduHandler`, który wysyła przez
 * `Illuminate\Support\Facades\Http` — w pełni testowalny i w pełni pod naszą
 * kontrolą co do treści (patrz komentarz tamtej klasy).
 *
 * Zero nowych zależności Composera: `Monolog\Logger` jest już wymagany przez
 * `laravel/framework`, a `Http` jest częścią frameworka.
 */
final class WebhookBleduLogger
{
    /**
     * Laravel przekazuje tu CAŁY wpis kanału z `config/logging.php`
     * (`driver`, `via`, `url`, `level`) — nie tylko to, czego używamy.
     *
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('blad_webhook');

        $logger->pushHandler(new WebhookBleduHandler(
            url: $this->url($config),
            level: $this->poziom($config['level'] ?? 'error'),
        ));

        return $logger;
    }

    /**
     * `null`, gdy `LOG_BLAD_WEBHOOK_URL` jest puste albo nie jest tekstem —
     * `WebhookBleduHandler` traktuje `null` jako „kanał wyłączony, nic nie wysyłaj"
     * (wymóg: brak zmiennej = zero efektu, zero wyjątku).
     */
    private function url(array $config): ?string
    {
        $url = $config['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
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
