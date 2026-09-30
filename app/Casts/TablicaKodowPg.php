<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Kolumna `text[]` PostgreSQL z kodami (`[a-z0-9_]+`) jako lista PHP.
 *
 * Sterownik oddaje tablicę jako napis `{gluten,milk}`. Kody ze słownika
 * (`Alergen`) nie zawierają cudzysłowów, przecinków ani nawiasów, więc
 * wystarczy prosty parser — dowolny inny napis to błąd, nie zgadywanie
 * (kolumna ma w bazie CHECK na zamkniętą listę, `recipes_allergens_closed_list_check`).
 *
 * @implements CastsAttributes<list<string>, array<array-key, string>>
 */
final class TablicaKodowPg implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        if (! is_string($value) || preg_match('/^\{([a-z0-9_]+(,[a-z0-9_]+)*)\}$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("Nieczytelna tablica kodów w kolumnie {$key}.");
        }

        return explode(',', $m[1]);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $kody = is_array($value) ? array_values($value) : [];

        foreach ($kody as $kod) {
            if (! is_string($kod) || preg_match('/^[a-z0-9_]+$/', $kod) !== 1) {
                throw new InvalidArgumentException("Kod niedozwolony w kolumnie {$key}.");
            }
        }

        return '{'.implode(',', $kody).'}';
    }
}
