<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * „Tap" kanałów logu serwera (`config/logging.php`): podpina
 * `BezDanychOsobowychWLogu` pod KAŻDY HANDLER kanału, więc działa przed jego
 * formaterem (JSON na Railway, tekst lokalnie). Tap zamiast `processors`,
 * bo `processors` obsługuje tylko sterownik `monolog`, a `single`/`daily` nie.
 *
 * DLACZEGO NA HANDLERZE, A NIE NA LOGGERZE
 * Kanał `stack` (`LOG_CHANNEL=stack`, `LOG_STACK=…`) zbiera z kanałów
 * składowych i handlery, i procesory LOGGERA — procesory lecą potem na
 * wszystkie handlery stosu. Gdyby ktoś ustawił `LOG_STACK=single,blad_webhook`,
 * procesor loggera kanału `single` zamieniłby `context['exception']` w tablicę
 * także dla `WebhookBleduHandler` — a ten bez obiektu wyjątku traci klasę,
 * plik:linię i odcisk i wysyła na webhook treść rekordu. Procesor handlera
 * jedzie z handlerem i dotyczy tylko jego (test
 * `LogSerweraBezDanychOsobowychTest::test_stos_z_webhookiem…`).
 *
 * Handler bez procesorów (nie `ProcessableHandlerInterface`) to w tych
 * kanałach sytuacja nieznana — wtedy procesor ląduje na loggerze, bo log bez
 * filtra jest gorszy niż webhook bez szczegółów.
 */
final class FiltrDanychOsobowych
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        $naLoggerze = false;

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(new BezDanychOsobowychWLogu);
            } elseif (! $naLoggerze) {
                $monolog->pushProcessor(new BezDanychOsobowychWLogu);
                $naLoggerze = true;
            }
        }
    }
}
