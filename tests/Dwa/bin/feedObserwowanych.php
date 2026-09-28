<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #2026: połączenie A składa stronę Obserwowanych.
 *
 * Woła PRAWDZIWY `FollowingFeed::paginate()` na własnym połączeniu. Bariera
 * należy wyłącznie do przyrządu: zaraz PO zapytaniu o listę obserwowanych
 * osób (`$viewer->following()->pluck(...)`) proces staje w kolejce po blokadę
 * doradczą, którą trzyma test. W tym czasie test zatwierdza blokadę na innym
 * połączeniu, a po zwolnieniu bariery proces wykonuje resztę — w tym główny
 * SELECT wpisów. Poza tym jednym punktem zatrzymania nic tu nie udaje kodu
 * produktu.
 *
 * Melduje identyfikatory wpisów ze strony i przebieg zapytań (czy bariera
 * zadziałała, czy SELECT wpisów wypadł PO niej, a nie przed), żeby test
 * mógł udowodnić, że zmierzył właściwy przeplot.
 */

use App\Domain\Feed\FollowingFeed;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
// Ta sama reguła rodziny baz co w `komentarz.php` i `zeszyt.php`.
if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}
DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '20s'");
DB::statement("SET idle_in_transaction_session_timeout = '20s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    $viewer = User::query()->findOrFail($args['viewer']);

    $armed = true;
    $przebieg = ['bariera' => false, 'posts_przed' => 0, 'posts_po' => 0, 'w_transakcji' => null];
    DB::listen(function (QueryExecuted $query) use (&$armed, &$przebieg, $viewer, $args): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'from "posts"')) {
            $przebieg[$przebieg['bariera'] ? 'posts_po' : 'posts_przed']++;
        }

        if (! $armed
            || ! str_contains($sql, 'inner join "follows"')
            || ! str_contains($sql, '"follows"."follower_id" = ?')
            || $query->bindings !== [$viewer->getKey()]) {
            return;
        }

        $armed = false;
        $przebieg['bariera'] = true;
        // Poza transakcją: A nie trzyma migawki ani blokad wierszy, więc
        // następne zapytanie (READ COMMITTED) zobaczy to, co B zatwierdziło.
        $przebieg['w_transakcji'] = DB::transactionLevel();
        DB::selectOne('SELECT pg_advisory_xact_lock(2026, hashtext(?))', [$args['name']]);
    });

    $ids = array_map(
        static fn (Post $post): string => (string) $post->getKey(),
        app(FollowingFeed::class)->paginate($viewer)->items(),
    );

    echo json_encode(['ok' => true, 'wartosc' => ['ids' => $ids] + $przebieg,
        'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
