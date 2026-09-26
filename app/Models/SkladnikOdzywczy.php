<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pozycja tabeli wartości odżywczych (D-299) — kopia wiersza z otwartej
 * tabeli CIQUAL albo USDA, wartości na 100 g części jadalnej.
 *
 * Wypełnia ją WYŁĄCZNIE `kuking:importuj-wartosci-odzywcze` z pliku
 * `database/data/odzywcze/skladniki.csv`. Stąd `$guarded = []` byłoby
 * wygodne, ale `$fillable` jest jawną listą, bo tak robi reszta modeli.
 *
 * @property string $klucz
 * @property string $nazwa
 * @property string $zrodlo
 * @property string $zrodlo_id
 * @property string $zrodlo_nazwa
 * @property float $kcal_100g
 * @property float $bialko_100g
 * @property float $tluszcz_100g
 * @property float $weglowodany_100g
 * @property float|null $gestosc_g_ml
 * @property bool $pomijalny
 */
class SkladnikOdzywczy extends Model
{
    use HasUuids;

    protected $table = 'skladniki_odzywcze';

    public const ZRODLO_CIQUAL = 'ciqual';

    public const ZRODLO_USDA = 'usda';

    protected $fillable = [
        'klucz',
        'nazwa',
        'zrodlo',
        'zrodlo_id',
        'zrodlo_nazwa',
        'kcal_100g',
        'bialko_100g',
        'tluszcz_100g',
        'weglowodany_100g',
        'gestosc_g_ml',
        'pomijalny',
    ];

    protected function casts(): array
    {
        return [
            'kcal_100g' => 'float',
            'bialko_100g' => 'float',
            'tluszcz_100g' => 'float',
            'weglowodany_100g' => 'float',
            'gestosc_g_ml' => 'float',
            'pomijalny' => 'boolean',
        ];
    }

    /** @return HasMany<MiaraDomowa, $this> */
    public function miary(): HasMany
    {
        return $this->hasMany(MiaraDomowa::class, 'skladnik_odzywczy_id');
    }

    /** @return HasMany<AliasSkladnika, $this> */
    public function aliasy(): HasMany
    {
        return $this->hasMany(AliasSkladnika::class, 'skladnik_odzywczy_id');
    }
}
