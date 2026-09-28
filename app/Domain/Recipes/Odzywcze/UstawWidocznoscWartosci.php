<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
    public function handle(Recipe $recipe, User $author, bool $pokazuj): void
    {
        DB::transaction(function () use ($recipe, $author, $pokazuj): void {
            // Ten sam wiersz i ten sam porządek co decyzje moderacyjne. Model
            // z route bindingu mógł już stać się nieedytowalny albo usunięty.
            $swiezy = Recipe::query()->withTrashed()->whereKey($recipe->getKey())
                ->lockForUpdate()->first();

            if ($swiezy === null || $swiezy->trashed()
                || ! Gate::forUser($author)->allows('update', $swiezy)) {
                throw new BladDlaCzlowieka('Przepis zmienił stan i nie można już zmienić widoczności wartości odżywczych.');
            }

            $timestamps = $swiezy->timestamps;
            try {
                $swiezy->timestamps = false;
                $swiezy->forceFill(['pokazuj_wartosci_odzywcze' => $pokazuj])->saveQuietly();
            } finally {
                $swiezy->timestamps = $timestamps;
            }
        });
    }
}
