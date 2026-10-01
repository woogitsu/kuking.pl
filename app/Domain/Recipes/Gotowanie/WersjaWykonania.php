<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Domain\Recipes\Historia\HistoriaWersji;
use App\Models\CookedEvent;
use App\Models\RecipeVersion;
use App\Models\User;

/**
 * Wersja przepisu przypięta do WŁASNEGO wykonania (issue #2378).
 *
 * To prywatny powrót kucharza do tego, z czego gotował — NIE publiczna
 * historia (#2024), która celowo nic nie mówi o wykonaniach. Żaden publiczny
 * widok (karta wykonania, galeria „Komu wyszło", historia zmian, eksport
 * przepisu) nie korzysta z tej klasy.
 *
 * Wersję zwracamy wyłącznie, gdy NARAZ:
 *  1. pyta kucharz tego wykonania (autor przepisu, moderator i obcy: `null`),
 *  2. wykonanie ma wskaźnik (`NULL` = „nie wiadomo", także po retencji #2024),
 *  3. przepis jest dziś opublikowany i widoczny dla tej osoby
 *     (`HistoriaWersji::wolnoOgladac` — Policy, blokady, status konta autora),
 *  4. wersja nie jest ukryta; ukrytą widzi tylko autor przepisu (#2270), więc
 *     kucharz, który nim nie jest, dostaje `null` jak przy usuniętej.
 *
 * Każdy powód odmowy daje to samo `null`: ekran nie mówi, CZEGO zabrakło,
 * żeby nie ujawnić ani tytułu, ani faktu ukrycia.
 */
final class WersjaWykonania
{
    public static function dla(CookedEvent $wykonanie, ?User $widz): ?RecipeVersion
    {
        if ($widz === null || $wykonanie->user_id !== $widz->getKey() || $wykonanie->recipe_version_id === null) {
            return null;
        }

        $recipe = $wykonanie->recipe;

        if ($recipe === null || ! HistoriaWersji::wolnoOgladac($widz, $recipe)) {
            return null;
        }

        return HistoriaWersji::zapytanie($recipe, HistoriaWersji::widziUkryte($widz, $recipe))
            ->whereKey($wykonanie->recipe_version_id)
            ->first();
    }
}
