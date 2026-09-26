<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

/** Ta sama zapisana instrukcja nie może być celem dwóch niepustych wierszy. */
final class ExistingStepDuplicates
{
    /**
     * @param  array<array-key, array<string, mixed>>  $steps
     * @param  iterable<string>  $existingIds  Identyfikatory kroków wyłącznie edytowanego przepisu.
     * @return array<string, string>
     */
    public static function errors(array $steps, iterable $existingIds): array
    {
        $owned = [];
        foreach ($existingIds as $id) {
            $owned[$id] = true;
        }

        $seen = [];
        $errors = [];
        foreach ($steps as $index => $step) {
            $id = trim((string) ($step['id'] ?? ''));
            // Tak jak cleanSteps: pusty wiersz nie bierze udziału w zapisie.
            if (trim((string) ($step['instruction'] ?? '')) === '' || ! isset($owned[$id])) {
                continue;
            }

            if (isset($seen[$id])) {
                $errors['steps.'.$index.'.instruction'] = 'Ten zapisany krok występuje dwa razy. Przenieś tę instrukcję do nowego kroku i usuń powtórzony wiersz.';
            }
            $seen[$id] = true;
        }

        return $errors;
    }
}
