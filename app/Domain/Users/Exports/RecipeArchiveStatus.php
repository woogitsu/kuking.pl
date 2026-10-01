<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Models\Recipe;

/** Jeden opis bieżącego stanu dla karty i spisu w osobistej paczce. */
final class RecipeArchiveStatus
{
    /** @return array{full: string, short: string}|null */
    public static function badge(string $status, bool $wasPublished): ?array
    {
        return match ($status) {
            Recipe::STATUS_DRAFT => [
                'full' => $wasPublished ? 'Przepis jest teraz szkicem' : 'To był szkic — nigdy nie został opublikowany',
                'short' => 'szkic',
            ],
            Recipe::STATUS_HIDDEN => ['full' => 'Przepis ukryty', 'short' => 'ukryty'],
            Recipe::STATUS_REMOVED => ['full' => 'Przepis usunięty', 'short' => 'usunięty'],
            default => null,
        };
    }
}
