<?php

declare(strict_types=1);

use App\Domain\Moderation\Actions\AlarmujOPilnymZgloszeniu;
use App\Models\Report;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../../bootstrap.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$argumenty = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

try {
    $report = Report::query()->findOrFail((string) ($argumenty['report_id'] ?? ''));
    $wynik = app(AlarmujOPilnymZgloszeniu::class)->recover($report);

    echo json_encode([
        'ok' => true,
        'sqlstate' => null,
        'komunikat' => '',
        'wartosc' => $wynik,
        'wyjatek' => null,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $wyjatek) {
    echo json_encode([
        'ok' => false,
        'sqlstate' => $wyjatek instanceof PDOException ? $wyjatek->getCode() : null,
        'komunikat' => $wyjatek->getMessage(),
        'wartosc' => null,
        'wyjatek' => $wyjatek::class,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
}
