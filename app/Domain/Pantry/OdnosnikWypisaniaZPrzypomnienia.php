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

    /**
     * Ile żyje link „Jednak chcę”. Pokazujemy go tylko na stronie wyświetlonej
     * tuż po wypisaniu, a WŁĄCZA zgodę bez logowania — więc krótko (D-333).
     * Link wypisania z listu jest bezterminowy: wypisanie ma działać zawsze.
     */
    public const WAZNOSC_POWROTU_MINUTY = 60;

    /** „Jednak chcę": przycisk na stronie po wypisaniu (tylko POST, ważny 1 godzinę). */
    public static function powrotDla(User $odbiorca): string
    {
        return URL::temporarySignedRoute(
            'spizarnia.wracam',
            now()->addMinutes(self::WAZNOSC_POWROTU_MINUTY),
            ['user' => $odbiorca->getKey()],
        );
    }
}
