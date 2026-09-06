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
// UWAGA NA `Schedule::command()` — NIE UŻYWAMY GO TUTAJ.
//
// `Schedule::command()` uruchamia zadanie przez Symfony Process, a ten wymaga
// `proc_open`, wyłączonego w `docker/php.ini` (hardening, AGENTS.md zabrania
// go osłabiać). Na produkcji kończyło się to natychmiastowym:
//
//     The Process class relies on proc_open, which is not available
//     on your PHP installation.
//
// a w roli `all` śmierć harmonogramu kładła CAŁY kontener — objawiało się to
// losowymi 502 w trakcie zwykłej pracy.
//
// `Schedule::call()` wykonuje domknięcie w TYM SAMYM procesie PHP, więc
// `proc_open` nie jest potrzebny. `Artisan::call()` uruchamia tę samą komendę
// co wcześniej i zwraca jej kod wyjścia.
//
// Kosztem jest utrata `runInBackground()`: sprzątanie eksportów blokuje pętlę
// harmonogramu na czas swojego działania. Przy jednym uruchomieniu na dobę
// i `withoutOverlapping()` to jest do przyjęcia — a alternatywą byłoby
// osłabienie zabezpieczenia, którego nie wolno ruszać.
Schedule::call(fn () => Artisan::call('kuking:sprzataj-eksporty'))
    ->name('kuking:sprzataj-eksporty')
    ->dailyAt('03:20')
    ->withoutOverlapping();

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
// `Schedule::call()`, nie `command()` — uzasadnienie przy zadaniu wyżej.
Schedule::call(fn () => Artisan::call('kuking:zdejmij-wygasle-kary'))
    ->name('kuking:zdejmij-wygasle-kary')
    ->hourly()
    ->withoutOverlapping();

// Egzekucja 30-dniowej karencji po zgłoszeniu usunięcia konta (audyt A8).
//
// Bez tego zadania obietnica z `docs/legal/COMPLIANCE.md` i z ekranu
// „Twoje dane" ("po 30 dniach dane zostaną usunięte na stałe") jest fikcją —
// nic wcześniej nie egzekwowało karencji, konto zostawało `pending_delete`
// bez końca. Codziennie w nocy, nie co godzinę: to nie kara z konkretną
// godziną wygaśnięcia jak zawieszenie, tylko okno na zmianę zdania liczone
// w dniach — dobowa dokładność wystarcza.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
Schedule::call(fn () => Artisan::call('kuking:usun-wygasle-konta'))
    ->name('kuking:usun-wygasle-konta')
    ->dailyAt('03:50')
    ->withoutOverlapping();
