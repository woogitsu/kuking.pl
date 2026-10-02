<?php

declare(strict_types=1);

// Wyłącznie izolowana lokalna baza CI. Każda edycja przechodzi przez PublishRecipe.
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
    || $db->getDatabaseName() !== 'kuking_minutnik_2589'
    || getenv('DB_URL')
    || realpath((string) getenv('APP_BASE_PATH')) !== realpath(__DIR__.'/../..')) {
    throw new RuntimeException('MINUTNIK_2589 wymaga jawnej izolowanej lokalnej bazy i APP_BASE_PATH.');
}

$email = 'minutnik2589@example.invalid';
$tytul = 'Minutnik 2589 zupa';
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
} elseif ($polecenie === 'zamien' || $polecenie === 'zmien') {
    if (! $przepis || ! $autor) {
        throw new RuntimeException('Brak fixture do edycji.');
    }
    $kroki = $przepis->steps()->orderBy('position')->get()->keyBy('instruction');
    $a = $kroki->get('Pokrój warzywa.');
    $b = $kroki->get('Gotuj zupę.');
    if (! $a || ! $b) {
        throw new RuntimeException('Fixture kroków ma nieoczekiwany stan.');
    }
    $przepis = $akcja->handle(
        author: $autor,
        attributes: ['title' => $tytul, 'visibility' => 'public'],
        steps: [
            ['id' => $b->getKey(), 'instruction' => $polecenie === 'zmien' ? 'Gotuj zupę pod przykryciem.' : 'Gotuj zupę.', 'timer_minutes' => $polecenie === 'zmien' ? 2 : 1],
            ['id' => $a->getKey(), 'instruction' => 'Pokrój warzywa.', 'timer_minutes' => 2],
        ],
        publish: true,
        existing: $przepis,
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
