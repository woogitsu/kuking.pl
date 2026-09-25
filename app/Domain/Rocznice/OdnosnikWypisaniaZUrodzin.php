<?php

declare(strict_types=1);

namespace App\Domain\Rocznice;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Podpisany odnośnik „wypisz mnie" w liście z życzeniami (issue #1755, etap c).
 *
 * Działa bez logowania — autoryzacją jest podpis aplikacji, nie identyfikator
 * w adresie (AGENTS.md §7), tak jak `App\Domain\Digest\OdnosnikWypisania`.
 */
final class OdnosnikWypisaniaZUrodzin
{
    public static function dla(User $odbiorca): string
    {
        return URL::signedRoute('urodziny.wypisz', ['user' => $odbiorca->getKey()]);
    }
}
