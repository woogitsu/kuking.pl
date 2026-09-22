<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Recipe;

/** Te same granice pól w walidacji i w odzyskiwaniu formularza (#524). */
final class LimityTekstuPrzepisu
{
    public const POLA = [
        'title' => 180,
        'summary' => 2000,
        'source_person' => 120,
        'source_note' => 2000,
        'source_url' => 2000,
        'ingredients.*.text' => 240,
        'ingredients.*.group_name' => 120,
        'ingredients.*.note' => 300,
        'steps.*.instruction' => 4000,
        'skladniki_tekst' => 30000,
        'przygotowanie_tekst' => 120000,
    ];

    public static function maksZnakowFormularza(): int
    {
        // Liczymy także obie reprezentacje tekstowe: kontroler przyjmuje je
        // razem z tablicami. Dodatkowe 64 znaki na pole mieszczą identyfikatory,
        // enumy i zwykłe wartości liczbowe, nie są nowym limitem produktu.
        $suma = 0;
        foreach (self::POLA as $pole => $limit) {
            $suma += $limit * (str_starts_with($pole, 'ingredients.') ? Recipe::MAX_INGREDIENTS
                : (str_starts_with($pole, 'steps.') ? Recipe::MAX_STEPS : 1));
        }

        return $suma + self::maksPol() * 64;
    }

    public static function maksPol(): int
    {
        // Cztery wartości składnika, cztery kroku; 32 miejsca na metrykę,
        // dwa pola tekstowe, klucz wysłania, akcję i pola wyboru plików.
        return Recipe::MAX_INGREDIENTS * 4 + Recipe::MAX_STEPS * 4 + 32;
    }
}
