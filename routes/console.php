<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Harmonogram
|--------------------------------------------------------------------------
*/

// Wygasłe paczki z danymi (RODO — minimalizacja: nie trzymamy kopii całych
// kont dłużej, niż potrzeba na ich pobranie). Codziennie w nocy, bo sprzątanie
// dotyka storage, a w nocy nikt nie czeka na odpowiedź serwisu.
// `withoutOverlapping` — przy dużej liczbie plików jedno uruchomienie może
// trwać dłużej niż dobę i nie chcemy dwóch naraz.
Schedule::command('kuking:sprzataj-eksporty')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->runInBackground();
