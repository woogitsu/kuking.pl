<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/** Uruchamia komendę w procesie PHP, bez wyłączonego na produkcji proc_open. */
final class ScheduledArtisanCommand
{
    /** @param array<string, mixed> $parameters */
    public static function artisan(string $command, array $parameters = []): CallbackEvent
    {
        // CallbackEvent rozpoznaje tylko false jako porażkę. Liczbowe 1 i 2
        // oznaczałyby sukces. Wyjątki pozostają wyjątkami, obsługuje je Laravel.
        return Schedule::call(fn (): bool => Artisan::call($command, $parameters) === 0)
            ->name($command);
    }
}
