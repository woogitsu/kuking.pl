<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Własne dopiski z zeszytów do podglądu w trybie gotowania (#2433).
 *
 * Tylko ODCZYT, tylko dla zalogowanej osoby, tylko z jej własnych zeszytów:
 *
 * - właściciel i przepis zawsze z argumentów (zalogowana osoba, przepis już
 *   sprawdzony przez `RecipePolicy::view`), nigdy z żądania;
 * - zeszyt musi być PRYWATNY i NIEWSPÓŁDZIELONY (brak wierszy w
 *   `collection_members`). Wspólne zeszyty mają reguły D-302 i dopisek w nich
 *   mógł napisać ktoś inny, więc tutaj ich nie ma — rozstrzygnięcie
 *   najbezpieczniejsze dla prywatności, rozszerzenie wymaga osobnej decyzji;
 * - kilka zeszytów = kilka osobnych pozycji z nazwą zeszytu, bez scalania
 *   i bez wybierania „ważniejszej”;
 * - pusta albo składająca się z białych znaków notatka nie tworzy pozycji;
 * - wynik NIGDY nie trafia do danych przepisu przekazywanych pomocnikom,
 *   do cache ani do powiadomień — to prosta lista dla widoku tej jednej osoby.
 */
final class NotatkiZZeszytowDoGotowania
{
    /** Górny bezpiecznik listy — zwykła osoba ma kilka zeszytów, nie setki. */
    private const MAKS_POZYCJI = 20;

    /**
     * @return list<array{zeszyt: string, tresc: string}>
     */
    public function dla(?User $osoba, Recipe $recipe): array
    {
        if ($osoba === null) {
            return [];
        }

        $wiersze = DB::table('collection_items')
            ->join('collections', 'collections.id', '=', 'collection_items.collection_id')
            ->where('collection_items.recipe_id', $recipe->getKey())
            ->where('collections.owner_id', $osoba->getKey())
            ->where('collections.visibility', 'private')
            ->whereNotExists(fn ($wspolny) => $wspolny->selectRaw('1')
                ->from('collection_members')
                ->whereColumn('collection_members.collection_id', 'collections.id'))
            ->whereNotNull('collection_items.note')
            ->whereRaw("collection_items.note !~ '^\\s*$'")
            ->orderBy('collections.name')
            ->orderBy('collections.id')
            ->limit(self::MAKS_POZYCJI)
            ->get(['collections.name', 'collection_items.note']);

        return $wiersze
            ->map(fn ($wiersz): array => ['zeszyt' => (string) $wiersz->name, 'tresc' => trim((string) $wiersz->note)])
            ->values()
            ->all();
    }
}
