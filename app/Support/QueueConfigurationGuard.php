<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * Pilnuje atomowości zleceń zapisywanych przez kolejkę database (#2402).
 *
 * Importy zlecają zadanie w tej samej transakcji, w której zapisują import.
 * Przy `after_commit=false` jest to bezpieczne wyłącznie wtedy, gdy kolejka
 * używa tego samego połączenia bazy. Rozjazd nazw połączeń pozwoliłby
 * workerowi zobaczyć zadanie przed commitem albo pozostawić je po rollbacku.
 */
final class QueueConfigurationGuard
{
    public static function assertCompatible(): void
    {
        if (config('queue.default') !== 'database') {
            return;
        }

        $appConnection = (string) config('database.default');
        $queueConnection = (string) config('queue.connections.database.connection');

        if ($queueConnection !== $appConnection) {
            throw new LogicException(
                'Kolejka database musi używać tego samego połączenia co aplikacja. '.
                'Sprawdź DB_QUEUE_CONNECTION i DB_CONNECTION.',
            );
        }

        if (config('queue.connections.database.after_commit') !== false) {
            throw new LogicException(
                'Kolejka database musi mieć after_commit=false, ponieważ import zapisuje zadanie w tej samej transakcji.',
            );
        }
    }
}
