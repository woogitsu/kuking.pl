<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Podpisane odnośniki sobotniego przypomnienia o produktach do zużycia
 * (#1903, D-333).
 *
 * Działają bez logowania — autoryzacją jest podpis aplikacji, nie
 * identyfikator w adresie (AGENTS.md §7), tak jak
 * `App\Domain\Rocznice\OdnosnikWypisaniaZUrodzin`.
 */
final class OdnosnikWypisaniaZPrzypomnienia
{
    /** Wypisanie: GET pokazuje pytanie, zapisuje wyłącznie POST. */
    public static function dla(User $odbiorca): string
    {
        return URL::signedRoute('spizarnia.wypisz', ['user' => $odbiorca->getKey()]);
    }

    /** „Jednak chcę": przycisk na stronie po wypisaniu (tylko POST). */
    public static function powrotDla(User $odbiorca): string
    {
        return URL::signedRoute('spizarnia.wracam', ['user' => $odbiorca->getKey()]);
    }
}
