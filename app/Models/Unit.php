<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Jednostki miary. Polska kuchnia domowa mierzy szklankami i łyżkami —
 * i to jest w porządku, słownik musi to obejmować, nie "poprawiać".
 */
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'code',
        'name',
        'name_plural',
        'unit_type',
    ];
}
