<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use RuntimeException;

/** Uruchamia komendę w procesie PHP, bez wyłączonego na produkcji proc_open. */
final class ScheduledArtisanCommand
{
    /** @param array<string, mixed> $parameters */
    public static function artisan(string $command, array $parameters = []): CallbackEvent
    {
        // CallbackEvent rozpoznaje jako porażkę tylko wyjątek albo false —
        // liczbowe 1 i 2 oznaczałyby sukces (#835, #1342). Wyjątek oznacza
        // przebieg jako nieudany i trafia do zgłaszania błędów. W treści tylko
        // nazwa komendy i kod: parametry mogą nieść dane, szczegóły są w logu.
        return Schedule::call(function () use ($command, $parameters): void {
            $code = Artisan::call($command, $parameters);

            if ($code !== 0) {
                throw new RuntimeException("{$command} zakończone kodem {$code} — szczegóły w logu komendy.");
            }
        })->name($command);
    }
}
