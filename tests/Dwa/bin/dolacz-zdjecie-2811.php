<?php

declare(strict_types=1);

use App\Domain\Recipes\Actions\BlokadaWyslaniaZdjecWykonania;
use App\Domain\Recipes\Actions\DolaczZdjeciaDoWykonania;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}
DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '30s'");
DB::statement("SET idle_in_transaction_session_timeout = '30s'");
DB::selectOne('SELECT set_config(?, ?, false)', ['application_name', $args['barrier'].'-'.$argv[1]]);

try {
    $kucharz = User::query()->findOrFail($args['user']);
    $wykonanie = CookedEvent::query()->findOrFail($args['event']);
    $wynik = app(BlokadaWyslaniaZdjecWykonania::class)->wykonaj(
        $kucharz,
        $wykonanie,
        $args['key'],
        function () use ($argv, $args, $kucharz, $wykonanie): string {
            $swieze = $wykonanie->fresh();
            if (in_array($args['key'], $swieze->photo_submission_keys ?? [], true)) {
                return 'duplicate';
            }
            if ($argv[1] === 'first') {
                DB::select('SELECT pg_advisory_lock(2812, hashtext(?))', [$args['barrier']]);
                DB::select('SELECT pg_advisory_unlock(2812, hashtext(?))', [$args['barrier']]);
            }
            // Model faktycznego zapisu Media po bramce z kontrolera. Właśnie
            // ten wiersz byłby osierocony, gdyby drugi request minął blokadę.
            $media = Media::factory()->create(['owner_id' => $kucharz->getKey()]);
            $ile = app(DolaczZdjeciaDoWykonania::class)->handle(
                $kucharz, $wykonanie, [(string) $media->getKey()], $args['key'],
            );

            return $ile === 1 ? 'added' : 'not-attached';
        },
    );
    echo json_encode(['ok' => true, 'wartosc' => $wynik, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
