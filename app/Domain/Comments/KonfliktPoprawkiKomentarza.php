<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Exceptions\BladDlaCzlowieka;

/**
 * Formularz poprawki komentarza otwarto na innej treści niż ta, którą baza
 * ma teraz (issue #982: druga karta albo drugie urządzenie zapisało poprawkę
 * w międzyczasie). Osobna klasa, żeby kontroler pokazał zapisaną treść obok
 * tekstu z formularza, a nie tylko komunikat.
 */
final class KonfliktPoprawkiKomentarza extends BladDlaCzlowieka
{
    public function __construct()
    {
        parent::__construct('Ten komentarz zmienił się w innej karcie albo na innym urządzeniu. Nad polem widzisz, jak jest zapisany teraz, a w polu — Twój tekst. Popraw go, jeśli trzeba, i naciśnij „Zapisz poprawkę” jeszcze raz.');
    }
}
