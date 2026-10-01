<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;

// Dwa kolejne dni pomiaru: audyt może przejść przez północ w Polsce.
// Zegar czytamy raz, żeby nawet sam zapis fixture nie zgubił dnia.
return static function (): array {
    $dzis = Carbon::now('Europe/Warsaw')->startOfDay();

    return [
        $dzis->copy()->subYear()->setTime(12, 0),
        $dzis->copy()->addDay()->subYear()->setTime(12, 0),
    ];
};
