<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * Wybór „Zapamiętaj mnie na tym urządzeniu” przy logowaniu (#2708, pyt. 15, decyzja właściciela z 2.10.2026).
 *
 * Pole jest DOMYŚLNIE ZAZNACZONE. Zaznaczone znaczy to, co działało dotąd:
 * `Auth::login(..., remember: true)` i długotrwałe ciasteczko `remember_web_*`
 * (400 dni). Odznaczone znaczy zwykłą sesję, bez tego ciasteczka.
 *
 * FORMULARZ WYSYŁA DWA POLA o tej samej nazwie: ukryte `0` i pole wyboru `1`.
 * Odznaczone pole wyboru nie jest wysyłane wcale, więc bez ukrytego `0` nie
 * dałoby się go odróżnić od „starego formularza, który o tym polu nie wie”.
 * ŻĄDANIE BEZ POLA (stary formularz, aplikacja, zapamiętana karta) zachowuje
 * się jak zaznaczone, czyli tak jak przed tą zmianą.
 *
 * Logowanie z drugim składnikiem (2FA) zostaje `remember: false` na stałe,
 * niezależnie od tego wyboru (`TwoFactorChallengeController`).
 *
 * DOSTAWCY ZEWNĘTRZNI (Google, Facebook): wejście zaczyna się zwykłym GET-em,
 * a logowanie kończy się w innym żądaniu po powrocie od dostawcy. Wybór
 * zapisujemy więc w sesji przy starcie i czytamy przy logowaniu.
 */
final class ZapamietajMnie
{
    public const POLE = 'zapamietaj';

    private const KLUCZ_SESJI = 'logowanie.zapamietaj';

    public static function zZadania(Request $request): bool
    {
        return self::wartosc($request->input(self::POLE));
    }

    /** Przy starcie wejścia przez dostawcę: zapamiętuje wybór do chwili powrotu. */
    public static function zapiszWSesji(Request $request): void
    {
        $request->session()->put(self::KLUCZ_SESJI, self::zZadania($request));
    }

    /** Przy logowaniu po powrocie od dostawcy; brak zapisu w sesji = zaznaczone. */
    public static function zSesji(Session $sesja): bool
    {
        return (bool) $sesja->get(self::KLUCZ_SESJI, true);
    }

    public static function zapomnij(Session $sesja): void
    {
        $sesja->forget(self::KLUCZ_SESJI);
    }

    private static function wartosc(mixed $surowa): bool
    {
        if ($surowa === null) {
            return true;
        }

        return ! in_array(strtolower(trim(is_scalar($surowa) ? (string) $surowa : '')), ['0', 'false', 'off', 'nie'], true);
    }
}
