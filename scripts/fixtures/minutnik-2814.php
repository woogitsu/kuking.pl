<?php

declare(strict_types=1);

// Wyłącznie izolowana lokalna baza CI: dwa prawdziwe kroki PublishRecipe.
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = DB::connection();
if (PHP_SAPI !== 'cli'
    || ! $app->environment(['local', 'testing'])
    || $db->getDriverName() !== 'pgsql'
    || $db->getConfig('host') !== '127.0.0.1'
    || ! getenv('DB_PORT')
    || (string) $db->getConfig('port') !== (string) getenv('DB_PORT')
    || $db->getDatabaseName() !== 'kuking_minutnik_2814'
    || getenv('DB_URL')
    || realpath((string) getenv('APP_BASE_PATH')) !== realpath(__DIR__.'/../..')) {
    throw new RuntimeException('MINUTNIK_2814 wymaga jawnej izolowanej lokalnej bazy i APP_BASE_PATH.');
}

$email = 'minutnik2814@example.invalid';
$tytul = 'Minutnik 2814 zupa';
$polecenie = $argv[1] ?? '';
$przepis = Recipe::where('title', $tytul)->first();
$autor = User::where('email', $email)->first();

if ($polecenie === 'usun') {
    $przepis?->forceDelete();
    $autor?->forceDelete();
    exit;
}

$akcja = app(PublishRecipe::class);
if ($polecenie === 'utworz') {
    if ($przepis || $autor) {
        throw new RuntimeException('Fixture istnieje; nie nadpisuję jej.');
    }
    $autor = User::factory()->create(['email' => $email]);
    $przepis = $akcja->handle(
        author: $autor,
        attributes: ['title' => $tytul, 'visibility' => 'public'],
        steps: [
            ['instruction' => 'Pokrój warzywa.', 'timer_minutes' => 2],
            ['instruction' => 'Gotuj zupę.', 'timer_minutes' => 1],
        ],
        publish: true,
    );
} else {
    throw new RuntimeException('Nieznane polecenie fixture.');
}

$kroki = $przepis->steps()->orderBy('position')->get();
echo json_encode([
    'slug' => $przepis->slug,
    'steps' => $kroki->map(fn ($step): array => [
        'id' => (string) $step->getKey(),
        'fingerprint' => $step->timerFingerprint(),
        'instruction' => $step->instruction,
    ])->all(),
], JSON_THROW_ON_ERROR);
