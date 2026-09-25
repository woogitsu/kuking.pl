<?php

declare(strict_types=1);

namespace App\Support\Baza;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;

/**
 * KAŻDA MIGRACJA DOSTAJE `lock_timeout` (audyt B3 W3).
 *
 * PO CO
 * Migracje uruchamia rola `migrate` (`docker/entrypoint.sh`) na ŻYWEJ bazie.
 * `ALTER TABLE posts` staje w kolejce po `ACCESS EXCLUSIVE` za długim
 * zapytaniem (raport, eksport) — a KAŻDE następne zapytanie do `posts`,
 * także zwykły `SELECT` z feedu, staje w kolejce ZA tym ALTER-em. Serwis
 * przestaje odpowiadać na czas trwania raportu, a nie na milisekundy DDL.
 * Z `lock_timeout` DDL, który nie dostał blokady w kilka sekund, pada
 * i daje się powtórzyć, zamiast kłaść serwis.
 *
 * JAK
 * `SET lock_timeout` (sesyjne, nie `LOCAL`) przy `MigrationStarted`
 * i `RESET` przy `MigrationEnded`, na połączeniu, na którym migracja
 * naprawdę chodzi. Sesyjne, bo migracje z `$withinTransaction = false`
 * (`CREATE INDEX CONCURRENTLY`, `NOT VALID` + `VALIDATE`) nie mają
 * transakcji, w której `SET LOCAL` by coś znaczyło. W migracji
 * transakcyjnej nieudany DDL wycofuje transakcję razem z `SET`, więc
 * limit nie przecieka dalej; w nietransakcyjnej proces `artisan migrate`
 * i tak się wtedy kończy.
 *
 * `CREATE INDEX CONCURRENTLY` też czeka na blokady (na koniec starszych
 * transakcji) i też podlega limitowi. Przerwany zostawia indeks INVALID —
 * migracja ma go wtedy zdjąć i zbudować od nowa (reguła w AGENTS.md §6).
 *
 * Migracja, która świadomie potrzebuje dłuższego czekania, może w `up()`
 * sama ustawić `SET lock_timeout = '…'` — nasz `RESET` przy końcu migracji
 * i tak przywróci wartość domyślną.
 */
final class LimitBlokadMigracji
{
    public const LIMIT = '5s';

    public function __construct(private readonly Migrator $migrator) {}

    public function przyStarcie(MigrationStarted $zdarzenie): void
    {
        $polaczenie = $this->polaczenie($zdarzenie->migration);

        if ($polaczenie?->getDriverName() === 'pgsql') {
            $polaczenie->statement("SET lock_timeout = '".self::LIMIT."'");
        }
    }

    public function przyKoncu(MigrationEnded $zdarzenie): void
    {
        $polaczenie = $this->polaczenie($zdarzenie->migration);

        if ($polaczenie?->getDriverName() === 'pgsql') {
            $polaczenie->statement('RESET lock_timeout');
        }
    }

    private function polaczenie(object $migracja): ?Connection
    {
        $nazwa = method_exists($migracja, 'getConnection') ? $migracja->getConnection() : null;
        $polaczenie = $this->migrator->resolveConnection($nazwa);

        return $polaczenie instanceof Connection ? $polaczenie : null;
    }
}
