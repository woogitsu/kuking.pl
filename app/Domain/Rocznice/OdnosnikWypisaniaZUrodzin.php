<?php

declare(strict_types=1);

namespace App\Domain\Rocznice;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Podpisane odnośniki listu z życzeniami (issue #1755, etap c, D-269).
 *
 * Działają bez logowania — autoryzacją jest podpis aplikacji, nie
 * identyfikator w adresie (AGENTS.md §7), tak jak
 * `App\Domain\Digest\OdnosnikWypisania`.
 */
final class OdnosnikWypisaniaZUrodzin
{
    /** Wypisanie: GET pokazuje pytanie, zapisuje wyłącznie POST. */
    public static function dla(User $odbiorca): string
    {
        return URL::signedRoute('urodziny.wypisz', ['user' => $odbiorca->getKey()]);
    }

    /** „Jednak chcę": przycisk na stronie po wypisaniu (tylko POST). */
    public static function powrotDla(User $odbiorca): string
    {
        return URL::signedRoute('urodziny.wracam', ['user' => $odbiorca->getKey()]);
    }
}
