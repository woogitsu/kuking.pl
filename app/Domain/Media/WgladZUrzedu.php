<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\User;

/**
 * Słownik wglądu z urzędu w zdjęcie (D-333) po stronie modułu Media.
 *
 * `DostepDoZdjecia` rozstrzyga, CZY moderator widzi zdjęcie tylko dzięki
 * roli, i musi nazwać powód wglądu oraz przyciąć listę spraw. Sam wpis do
 * dziennika robi `App\Domain\Moderation\DziennikWgladu`, który bierze stąd
 * te same stałe. Kierunek zależności jest celowy: Moderation → Media, nigdy
 * odwrotnie — import Moderation w Media zamykał cykl
 * Compliance/Media/Moderation/Users (#2149, GrafModulowDomenyBezCykliTest).
 */
final class WgladZUrzedu
{
    public const POWOD_ZGLOSZENIE = 'zgloszenie';

    public const POWOD_UKRYTA_TRESC = 'ukryta_tresc';

    /** Treść jawna z nazwy, ale niewidoczna bez roli (np. konto autora zbanowane). */
    public const POWOD_ROLA = 'rola_moderatora';

    /** Najwięcej identyfikatorów spraw w jednym wpisie. */
    public const MAKS_SPRAW = 10;

    /**
     * To samo konto BEZ roli obsługi — do pytania „czy zobaczyłby to bez
     * roli". Kopia w pamięci, nigdy niezapisywana; pole sterujące `role`
     * ustawiamy `forceFill`, bo nie jest w `$fillable` (AGENTS.md §7).
     */
    public static function jakZwykleKonto(User $konto): User
    {
        $kopia = clone $konto;
        $kopia->forceFill(['role' => User::ROLE_USER]);

        return $kopia;
    }
}
