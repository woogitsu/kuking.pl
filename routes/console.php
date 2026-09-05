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

// Zdejmowanie kar, którym minął termin (issue #40).
//
// Co godzinę, nie raz na dobę: kara „do 12 września” ma się skończyć 12
// września, a nie następnej nocy. Przy dobowym harmonogramie ktoś ukarany na
// 7 dni siedziałby realnie do ośmiu — i to bez żadnej decyzji człowieka.
//
// Middleware EnsureAccountIsActive i tak przywraca konto natychmiast, gdy
// karany wejdzie na stronę po terminie. Ta komenda pilnuje kont, które po
// prostu nie wracają, żeby stan w bazie zgadzał się z rzeczywistością także
// dla moderacji i statystyk.
Schedule::command('kuking:zdejmij-wygasle-kary')
    ->hourly()
    ->withoutOverlapping();
