<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedna pozycja na tablicy „kuKINGi na dziś".
 */
class DailyPick extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_USER = 'user';

    public const TYPE_POST = 'post';

    protected $fillable = [
        'shown_on',
        'subject_type',
        'subject_id',
        'position',
        'curator_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'shown_on' => 'date',
            'position' => 'integer',
        ];
    }

    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'curator_id');
    }

    /** @param  Builder<DailyPick>  $query */
    public function scopeForDate(Builder $query, ?\DateTimeInterface $date = null): void
    {
        $query->whereDate('shown_on', $date ?? now())->orderBy('position');
    }
}
