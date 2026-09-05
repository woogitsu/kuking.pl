<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CookedEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Ugotowałem" — realne wykonanie czyjegoś przepisu.
 *
 * Nie ma tu unikalności (user_id, recipe_id) i nigdy jej nie dodawaj:
 * ta sama osoba może gotować ten sam przepis dziesiątki razy i każde
 * wykonanie jest osobnym wydarzeniem.
 */
class CookedEvent extends Model
{
    /** @use HasFactory<CookedEventFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'recipe_id',
        'note',
        'would_make_again',
        'perceived_difficulty',
        'actual_minutes',
        'changes_note',
        'cooked_at',
    ];

    protected function casts(): array
    {
        return [
            'cooked_at' => 'datetime',
            'would_make_again' => 'boolean',
            'actual_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'cooked_event_media')
            ->withPivot('position')
            ->orderBy('cooked_event_media.position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)
            ->whereNull('parent_id')
            ->where('status', Comment::STATUS_PUBLISHED)
            ->oldest();
    }

    public function url(): string
    {
        return route('cooked.show', ['cookedEvent' => $this->getKey()]);
    }
}
