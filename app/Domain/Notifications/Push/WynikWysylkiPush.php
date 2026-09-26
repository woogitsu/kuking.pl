<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

enum WynikWysylkiPush
{
    case Wyslano;

    /** Usługa push odpowiedziała 404 albo 410 — subskrypcji już nie ma. Wiersz znika. */
    case Wygasla;

    /** Każdy inny błąd. Wiersz zostaje; powiadomienie i tak czeka w serwisie. */
    case Blad;
}
