<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Polska nazwa składnika w jednej formie („mąki pszennej”, „jajek”),
 * znormalizowana `Ingredient::normalize()` — D-299.
 *
 * @property string $alias
 * @property string $skladnik_odzywczy_id
 */
class AliasSkladnika extends Model
{
    use HasUuids;

    protected $table = 'aliasy_skladnikow';

    public $timestamps = false;

    protected $fillable = ['alias', 'skladnik_odzywczy_id'];

    /** @return BelongsTo<SkladnikOdzywczy, $this> */
    public function skladnik(): BelongsTo
    {
        return $this->belongsTo(SkladnikOdzywczy::class, 'skladnik_odzywczy_id');
    }
}
