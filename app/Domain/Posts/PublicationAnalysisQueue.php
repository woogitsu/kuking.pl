<?php

declare(strict_types=1);

namespace App\Domain\Posts;

use App\Jobs\PrzeanalizujTresc;
use App\Models\Post;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

/** Trwałe zlecenie analizy w tej samej transakcji co nowy wpis (#935, #1009). */
final class PublicationAnalysisQueue
{
    public function assertCompatible(): void
    {
        $worker = Queue::connection();
        if ($worker instanceof DatabaseQueue && $worker->getDatabase() !== DB::connection()) {
            throw new LogicException('Kolejka publikacji musi używać tego samego połączenia co wpisy. Sprawdź DB_QUEUE_CONNECTION.');
        }
    }

    public function push(Post $post): void
    {
        PrzeanalizujTresc::dlaWpisu($post)->beforeCommit();
    }
}
