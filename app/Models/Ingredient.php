<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Znormalizowany słownik składników. Rośnie sam, z tego, co wpisują ludzie.
 */
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'canonical_name',
        'normalized_name',
    ];

    /**
     * Normalizacja nazwy składnika: małe litery, bez ogonków, bez nadmiarowych
     * spacji. "Mąka Pszenna" i "maka  pszenna" mają trafić na to samo.
     */
    public static function normalize(string $name): string
    {
        return Str::squish(Str::lower(Str::ascii(trim($name))));
    }

    public static function findOrCreateByName(string $name): self
    {
        $normalized = self::normalize($name);

        return self::firstOrCreate(
            ['normalized_name' => $normalized],
            ['canonical_name' => Str::squish($name)],
        );
    }
}
