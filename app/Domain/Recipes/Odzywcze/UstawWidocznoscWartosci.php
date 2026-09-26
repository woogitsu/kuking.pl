<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

use App\Models\Recipe;

/**
 * Autor pokazuje albo ukrywa szacunkowe wartości odżywcze przy swoim
 * przepisie (D-299). Domyślnie sekcja jest widoczna.
 *
 * Jawna, nazwana akcja zamiast `$fillable`: to jest decyzja autora o tym,
 * co pokazuje jego przepis (D-088 pilnuje jej przy cofaniu migracji), więc
 * nie może przyjść „przy okazji” z masowego przypisania w innym formularzu.
 *
 * Nie ruszamy `updated_at` ani wersji przepisu: to ustawienie widoku, nie
 * zmiana treści — przepis nie wskakuje przez nie na górę „ostatnio
 * poprawionych” i nie dostaje nowej pozycji w historii wersji.
 */
final class UstawWidocznoscWartosci
{
    public function handle(Recipe $recipe, bool $pokazuj): void
    {
        $recipe->forceFill(['pokazuj_wartosci_odzywcze' => $pokazuj]);
        $recipe->timestamps = false;
        $recipe->saveQuietly();
        $recipe->timestamps = true;
    }
}
