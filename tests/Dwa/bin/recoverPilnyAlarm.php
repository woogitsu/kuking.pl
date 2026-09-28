<?php

declare(strict_types=1);

use App\Domain\Moderation\Actions\AlarmujOPilnymZgloszeniu;
use App\Models\Report;

require __DIR__.'/../../bootstrap.php';

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
