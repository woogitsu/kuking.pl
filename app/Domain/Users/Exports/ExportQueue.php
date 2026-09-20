<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Jobs\GenerateUserExport;
use App\Jobs\NotifyUserExportReady;
use App\Models\DataExport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Eksport i jego zadanie muszą zatwierdzić się na tym samym połączeniu. */
final class ExportQueue
{
    public function dispatch(GenerateUserExport|NotifyUserExportReady $job): void
    {
        $connection = DB::connection(config('queue.connections.database.connection'));

        if (config('queue.connections.database.driver') !== 'database'
            || $connection !== (new DataExport)->getConnection()
            || $connection->transactionLevel() === 0) {
            throw new LogicException('Eksport wymaga kolejki database na tym samym połączeniu i wspólnej transakcji.');
        }

        // beforeCommit to zapis jobs WEWNĄTRZ transakcji, nie po jej końcu.
        // Jawne dispatch nie zależy od chwili zniszczenia PendingDispatch.
        Bus::dispatch($job->onConnection('database')->beforeCommit());
    }
}
