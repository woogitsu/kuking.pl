<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Users\Import\ZapisSzkicuZPaczki;
use App\Models\Recipe;
use App\Models\User;

/**
 * Zapis przepisu z wczytanej paczki własnych danych (#1985) jako prywatnego
 * szkicu — tą samą drogą co każdy szkic (`PublishRecipe`, `publish: false`).
 */
final class ZapiszSzkicZPaczki implements ZapisSzkicuZPaczki
{
    public function __construct(private readonly PublishRecipe $publishRecipe) {}

    public function zapisz(User $autor, array $dane): Recipe
    {
        return $this->publishRecipe->handle(
            author: $autor,
            attributes: [
                'title' => $dane['tytul'],
                'summary' => $dane['opis'],
                'visibility' => 'private',
                'source_type' => Recipe::SOURCE_OWN,
            ],
            ingredients: $dane['skladniki'],
            steps: $dane['kroki'],
            publish: false,
        );
    }
}
