<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Models\Recipe;

/**
 * Czy ten przepis MA CO dopisać (issue #364, D-053 „bez martwego przycisku").
 *
 * Ekran dodawania pyta o sześć rzeczy, reszta jest do dopisania później —
 * ale „później" nie może znaczyć „zawsze". Przepis, w którym wypełnione jest
 * już wszystko, dostawałby przycisk prowadzący do formularza bez ani jednego
 * pustego pola. To jest dokładnie ten martwy przycisk, którego D-053
 * zabrania: człowiek klika, trafia gdzieś, gdzie nie ma nic do zrobienia,
 * i traci zaufanie do każdego następnego przycisku na tym ekranie.
 *
 * Reguła żyje w domenie, nie w widoku, bo pyta o nią kilka miejsc naraz
 * (komunikat po publikacji, ekran szczegółów, w przyszłości strona przepisu)
 * i wszystkie muszą odpowiadać tak samo.
 */
final class CoMoznaDopisac
{
    /**
     * Pola przepisu, o które ekran dodawania NIE pyta.
     *
     * Kolejność jak na ekranie „Dopisz szczegóły", żeby czytało się to jak
     * ta sama lista, a nie jak druga, przypadkiem podobna.
     */
    private const POLA = [
        'summary',
        'servings',
        'prep_minutes',
        'cook_minutes',
        'difficulty',
        'source_person',
        'source_note',
        'source_url',
        'family_since_year',
        'source_scan_media_id',
    ];

    public static function jest(Recipe $recipe): bool
    {
        foreach (self::POLA as $pole) {
            $wartosc = $recipe->getAttribute($pole);

            if ($wartosc === null || (is_string($wartosc) && trim($wartosc) === '')) {
                return true;
            }
        }

        // Zdjęcie główne liczy się osobno: przepis bez zdjęcia ma co dopisać
        // nawet wtedy, gdy opis, czasy i pochodzenie są już wypełnione.
        if ($recipe->hero_media_id === null) {
            return true;
        }

        // Wiersze. Składnik bez uwagi i bez grupy to normalny składnik, więc
        // sam ich brak nie jest powodem — ale przepis BEZ ANI JEDNEGO
        // składnika albo bez ani jednego kroku ma co dopisać, i to najwięcej.
        if ($recipe->ingredients()->count() === 0) {
            return true;
        }

        return $recipe->steps()->count() === 0;
    }
}
