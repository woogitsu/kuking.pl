<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Miara domowa jednego składnika (D-299): „1 szklanka mąki pszennej = 140 g”.
 *
 * @property string $skladnik_odzywczy_id
 * @property string $jednostka
 * @property float $gramy
 * @property string|null $uwagi
 */
class MiaraDomowa extends Model
{
    use HasUuids;

    protected $table = 'miary_domowe';

    public $timestamps = false;

    protected $fillable = ['skladnik_odzywczy_id', 'jednostka', 'gramy', 'uwagi'];

    protected function casts(): array
    {
        return ['gramy' => 'float'];
    }

    /** @return BelongsTo<SkladnikOdzywczy, $this> */
    public function skladnik(): BelongsTo
    {
        return $this->belongsTo(SkladnikOdzywczy::class, 'skladnik_odzywczy_id');
    }
}
