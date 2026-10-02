<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Exceptions\BladDlaCzlowieka;

/**
 * Formularz korekty wykonania otwarto na innej treści, niż baza ma teraz
 * (#2459: druga karta albo drugie urządzenie zapisało poprawkę w międzyczasie,
 * albo formularz nie niósł odcisku). Nic nie zapisano; kontroler odsyła na
 * formularz z tekstem człowieka w polach i aktualnym odciskiem.
 */
final class KonfliktPoprawkiWykonania extends BladDlaCzlowieka
{
    public function __construct()
    {
        parent::__construct('To wykonanie zmieniło się w innej karcie albo na innym urządzeniu. Pola pokazują Twój tekst, a pod nimi widzisz, jak jest zapisane teraz. Sprawdź je i naciśnij „Zapisz poprawkę” jeszcze raz.');
    }
}
