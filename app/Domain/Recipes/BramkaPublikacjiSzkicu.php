<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Models\Recipe;
use Illuminate\Validation\ValidationException;

/**
 * Kontrakt: coś może zablokować pierwszą publikację szkicu (issue #971,
 * wzorem `App\Domain\Users\ObserwowanieGospodarza`).
 *
 * DLACZEGO TO JEST KONTRAKT, A NIE WYWOŁANIE `App\Domain\Import\BramkaPublikacjiOdczytu`
 * `PublishRecipe` (`Recipes`) jest jedynym wejściem publikacji przepisu —
 * stąd bramka „Sprawdziłem odczytany tekst” (D-298) musi być wołana właśnie
 * stamtąd, żeby obejmowała kreator, formularz bez JavaScriptu i każdą
 * przyszłą drogę zapisu. Sama bramka należy jednak do modułu `Import`, który
 * z kolei potrzebuje `Recipes` (`ZlecImportPrzepisu`, `OdczytajPrzepis` wołają
 * `PublishRecipe`). Bezpośredni import `BramkaPublikacjiOdczytu`
 * w `PublishRecipe` zamykał cykl `Import → Recipes → Import`.
 *
 * Kontrakt mieszka po stronie wołającego (`Recipes`), implementacja po
 * stronie `Import` (`App\Domain\Import\BramkaPublikacjiOdczytu`), a łączy je
 * `AppServiceProvider` — jedyne miejsce, które zna oba moduły. Kierunek
 * zależności to wtedy wyłącznie `Import → Recipes`. Pilnuje tego
 * `GrafModulowDomenyBezCykliTest`.
 */
interface BramkaPublikacjiSzkicu
{
    /**
     * @param  array<string, mixed>  $atrybuty
     * @param  list<array{ingredient_text: string}>  $skladniki
     * @param  list<array{instruction: string}>  $kroki
     *
     * @throws ValidationException gdy bramka blokuje publikację
     */
    public function sprawdz(Recipe $przepis, array $atrybuty, string $tytul, array $skladniki, array $kroki): void;
}
