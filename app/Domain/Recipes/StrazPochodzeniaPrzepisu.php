<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\User;

/**
 * Reguły przepisu, które wynikają z jego POCHODZENIA (dziś: import z adresu
 * strony, PDF-a albo zdjęcia — D-300), wołane przez `PublishRecipe` na każdej
 * drodze zapisu: kreator, formularz jednostronicowy, akcja wołana wprost.
 *
 * Kontrakt stoi w `Recipes`, implementacja w `Import` i łączy je wiązanie
 * w `AppServiceProvider` — tak samo jak `ObserwowanieGospodarza` (#971).
 * Dzięki temu `Recipes` nie zna modułu `Import` i graf modułów nie ma cyklu.
 */
interface StrazPochodzeniaPrzepisu
{
    /**
     * Przed zapisem istniejącego przepisu: może poprawić atrybuty (np. źródło
     * szkicu z adresu jest zablokowane) albo odmówić publikacji wyjątkiem
     * `BladDlaCzlowieka` (np. brak „Sprawdziłem odczytany tekst").
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function przedZapisem(User $author, Recipe $existing, array $attributes, bool $publish): array;

    /** W transakcji zapisu, gdy przepis właśnie przeszedł ze szkicu do opublikowanego. */
    public function poPublikacji(Recipe $recipe): void;
}
