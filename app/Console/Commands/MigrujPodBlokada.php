<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Wspólna blokada dla ręcznych migracji i Railway pre-deploy (#2082). */
final class MigrujPodBlokada extends Command
{
    protected $signature = 'kuking:migruj-pod-blokada';

    protected $description = 'Uruchamia migracje tylko po zdobyciu blokady sesyjnej PostgreSQL.';

    private const BLOKADA = 'pg_try_advisory_lock(2082, 1)';

    private const ZWOLNIJ = 'pg_advisory_unlock(2082, 1)';

    public function handle(): int
    {
        $polaczenie = DB::connection();

        if ($polaczenie->getDriverName() !== 'pgsql') {
            $this->error('Migracje wdrożeniowe wymagają PostgreSQL.');

            return self::FAILURE;
        }

        // Zachowujemy referencję do tej sesji przez cały przebieg migratora.
        // Blokada transakcyjna nie wystarcza: część migracji działa poza transakcją.
        $pdo = $polaczenie->getPdo();

        $zdobyta = filter_var($pdo->query('SELECT '.self::BLOKADA)->fetchColumn(), FILTER_VALIDATE_BOOLEAN);

        if (! $zdobyta) {
            $this->error('Inny proces już uruchamia migracje w tej bazie. Spróbuj ponownie po jego zakończeniu.');

            return 12;
        }

        try {
            return $this->call('migrate', ['--force' => true, '--no-interaction' => true]);
        } finally {
            // Używamy tej samej sesji, na której zdobyto blokadę. Zamknięcie
            // sesji przez PostgreSQL również zwalnia ją automatycznie.
            $pdo->query('SELECT '.self::ZWOLNIJ);
        }
    }
}
