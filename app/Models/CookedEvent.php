<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CookedEventFactory;
use Illuminate\Database\Eloquent\Builder;
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

    // ---------------------------------------------------------------------
    // Zakresy
    // ---------------------------------------------------------------------

    /**
     * Wykonania, które WOLNO pokazać temu widzowi na liście — np. w galerii
     * „Komu wyszło" pod przepisem (audyt A4).
     *
     * DLACZEGO TO JEST SCOPE, A NIE FILTR W BLADE
     * `RecipeController::show` ładował do dwunastu wykonań jedną instrukcją
     * SQL i renderował je w pętli bez pytania, kto je zrobił. Zablokowana
     * osoba wracała więc oglądającemu przez CUDZY przepis — miejsce, na które
     * `CookedEventPolicy::view` w ogóle nie ma wpływu, bo tam nikt nie klika
     * pojedynczego wykonania, tylko przegląda galerię. Filtrowanie w pętli
     * Blade byłoby też zapytaniem `hasBlockRelationWith()` per wiersz, czyli
     * N+1 na stronie przepisu — jednej z najczęściej odwiedzanych.
     *
     * Wzorzec identyczny jak `Post::scopeWidoczneDla` i `Recipe::scopeWidoczneDla`:
     * blokada pierwsza, bezwarunkowa, w OBIE strony. Wykonanie samo w sobie
     * nie ma widoczności (public/followers/private) — idzie za przepisem,
     * a widoczność przepisu jest już rozstrzygnięta wcześniej, jednym
     * wywołaniem `RecipePolicy::view` na całą stronę. Tu liczy się wyłącznie
     * to, KTO ugotował, bo to ta osoba (nie autor przepisu) może być
     * zablokowana przez widza albo odwrotnie.
     *
     * @param  Builder<CookedEvent>  $query
     */
    public function scopeWidoczneDla(Builder $query, ?User $widz): void
    {
        if ($widz === null) {
            return;
        }

        $widzId = $widz->getKey();

        $query->whereNotExists(function ($sub) use ($widzId): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function ($w) use ($widzId): void {
                    $w->where('blocks.blocker_id', $widzId)
                        ->whereColumn('blocks.blocked_id', 'cooked_events.user_id');
                })
                ->orWhere(function ($w) use ($widzId): void {
                    $w->whereColumn('blocks.blocker_id', 'cooked_events.user_id')
                        ->where('blocks.blocked_id', $widzId);
                });
        });
    }
}
