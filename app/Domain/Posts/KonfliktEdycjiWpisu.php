<?php

declare(strict_types=1);

namespace App\Domain\Posts;

use App\Exceptions\BladDlaCzlowieka;

/**
 * Formularz edycji wpisu został otwarty na innej wersji wpisu niż ta, którą
 * baza ma teraz (issue #981: druga karta albo drugie urządzenie zapisało
 * zmianę w międzyczasie). Osobna klasa, żeby kontroler mógł pokazać obie
 * wersje obok siebie, a nie tylko komunikat.
 */
final class KonfliktEdycjiWpisu extends BladDlaCzlowieka
{
    public function __construct()
    {
        parent::__construct('Ten wpis zmienił się w innej karcie albo na innym urządzeniu. Nad formularzem widzisz, jak jest zapisany teraz, a w formularzu — Twój tekst. Popraw go, jeśli trzeba, i naciśnij „Zapisz zmiany” jeszcze raz.');
    }
}
