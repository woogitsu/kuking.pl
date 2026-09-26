<?php

declare(strict_types=1);

namespace App\Domain\Import\Actions;

use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Odczytany przepis → PRYWATNY SZKIC autora (D-300). Nigdy publikacja.
 *
 *  - `publish: false` i `visibility = private` wpisane tutaj, nie brane
 *    z żądania — import nie ma jak podnieść statusu ani widoczności;
 *  - szkic z adresu: `source_type = external`, `source_url` = adres po
 *    przekierowaniach, bez parametrów śledzących (zablokowane potem przez
 *    `StrazImportu`);
 *  - bez zdjęcia — zdjęć z cudzych stron nie pobieramy;
 *  - wiersz `przepisy_z_importu` w tej samej transakcji co przepis, żeby
 *    szkic z importu nigdy nie istniał bez bramki „Sprawdziłem".
 *
 * Idzie przez `PublishRecipe` — tę samą akcję co kreator i formularz — więc
 * granice pól, slug i wersje działają tak samo jak przy ręcznym szkicu.
 */
final class ZapiszSzkicZImportu
{
    public function __construct(private readonly PublishRecipe $publishRecipe) {}

    /**
     * @param  ?OdczytanyPrzepis  $przepis  `null` = źródło bez treści (robots.txt,
     *                                      brak przepisu) — szkic z samym źródłem
     */
    public function handle(
        User $autor,
        string $zrodlo,
        string $droga,
        ?OdczytanyPrzepis $przepis,
        ?string $sourceUrl = null,
        string $tytulZastepczy = 'Przepis do przepisania',
    ): Recipe {
        return DB::transaction(function () use ($autor, $zrodlo, $droga, $przepis, $sourceUrl, $tytulZastepczy): Recipe {
            $zAdresu = $zrodlo === PrzepisZImportu::ZRODLO_URL;

            $recipe = $this->publishRecipe->handle(
                author: $autor,
                attributes: [
                    'title' => $przepis?->tytul ?: $tytulZastepczy,
                    'summary' => $przepis?->opis,
                    'servings' => $przepis?->porcje,
                    'prep_minutes' => $przepis?->przygotowanieMinut,
                    'cook_minutes' => $przepis?->gotowanieMinut,
                    'visibility' => 'private',
                    'source_type' => $zAdresu ? Recipe::SOURCE_EXTERNAL : Recipe::SOURCE_OWN,
                    'source_url' => $zAdresu ? $sourceUrl : null,
                    'source_note' => $zrodlo === PrzepisZImportu::ZRODLO_PDF ? 'Odczytane z pliku PDF.' : null,
                ],
                ingredients: array_map(static fn (string $t): array => ['text' => $t], $przepis->skladniki ?? []),
                steps: array_map(static fn (string $t): array => ['instruction' => $t], $przepis->kroki ?? []),
                publish: false,
            );

            $pochodzenie = new PrzepisZImportu;
            $pochodzenie->forceFill([
                'recipe_id' => $recipe->getKey(),
                'user_id' => $autor->getKey(),
                'zrodlo' => $zrodlo,
                'droga' => $droga,
                'source_url' => $zAdresu ? $sourceUrl : null,
                'tekst_zrodla' => $zAdresu && $przepis !== null ? $przepis->tekstKrokow() : null,
            ])->save();

            return $recipe;
        });
    }
}
