<?php

declare(strict_types=1);

use App\Domain\Zakupy\ListaZakupow;
use App\Jobs\GenerateUserExport;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);

if (preg_match('/\Akuking_race(_|\z)/', (string) DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}

DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '30s'");
DB::statement("SET idle_in_transaction_session_timeout = '30s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    $wynik = match ($argv[1]) {
        'eksport' => (function () use ($args): string {
            config([
                "filesystems.disks.{$args['disk']}" => ['driver' => 'local', 'root' => $args['root']],
                'kuking.exports.disk' => $args['disk'],
            ]);

            $zatrzymany = false;
            DB::listen(static function (QueryExecuted $query) use ($args, &$zatrzymany): void {
                $sql = strtolower($query->sql);
                if ($zatrzymany || ! str_starts_with(trim($sql), 'select')
                    || ! str_contains($sql, 'from "shopping_lists"')) {
                    return;
                }

                // Pierwszy odczyt nazw: pozycja została już pobrana, a
                // osobne końcowe zestawienie list dopiero nadejdzie.
                $zatrzymany = true;
                DB::selectOne('SELECT pg_advisory_xact_lock(2847, hashtext(?))', [$args['name']]);
            });

            (new GenerateUserExport($args['export']))->handle();

            if (! $zatrzymany) {
                throw new RuntimeException('Eksport nie odczytał nazwy listy — przeplot nie został zmierzony.');
            }

            return 'gotowy';
        })(),
        'przemianuj' => (function () use ($args): string {
            $user = User::query()->findOrFail($args['user']);
            $lista = ShoppingList::query()->findOrFail($args['list']);
            app(ListaZakupow::class)->zmienNazweListy($user, $lista, $args['new_name']);
            $inna = ShoppingList::query()->findOrFail($args['other_list']);
            app(ListaZakupow::class)->zmienNazweListy($user, $inna, $args['old_name']);

            return (string) $lista->fresh()?->name;
        })(),
        default => throw new RuntimeException('Nieznany scenariusz.'),
    };

    echo json_encode(['ok' => true, 'wartosc' => $wynik, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'komunikat' => $exception->getMessage(), 'wyjatek' => $exception::class,
    ]);
}
