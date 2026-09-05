<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Komentarz. Dotyczy dokładnie jednego obiektu — pilnuje tego CHECK w bazie.
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_REMOVED = 'removed';

    /**
     * Ukrycie komentarzy osób w relacji blokady z oglądającym (issue #41).
     *
     * Komentarz nie ma własnej widoczności — renderuje się wewnątrz strony
     * rodzica i dziedziczy jego ochronę NIEJAWNIE. To wystarcza, dopóki pytanie
     * brzmi „czy wolno mi zobaczyć ten wpis". Nie wystarcza, gdy wpis jest
     * publiczny, a nieprzyjemna jest konkretna OSOBA pod nim.
     *
     * Bez tego filtra blokada znaczyła „nie zobaczę jej wpisów, ale nadal będę
     * czytać jej zaczepki pod cudzymi" — czyli nie chroniła przed dokładnie tym,
     * po co ludzie jej używają.
     *
     * Blokada działa w obie strony (`AGENTS.md` §4). Dla gościa nie ma czego
     * filtrować — blokada jest relacją między dwoma kontami.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeWidoczneDla($query, ?User $widz): void
    {
        if ($widz === null) {
            return;
        }

        $query->whereNotExists(function ($sub) use ($widz): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function ($w) use ($widz): void {
                    $w->where('blocks.blocker_id', $widz->getKey())
                        ->whereColumn('blocks.blocked_id', 'comments.author_id');
                })
                ->orWhere(function ($w) use ($widz): void {
                    $w->whereColumn('blocks.blocker_id', 'comments.author_id')
                        ->where('blocks.blocked_id', $widz->getKey());
                });
        });
    }

    protected $fillable = [
        'author_id',
        'post_id',
        'recipe_id',
        'cooked_event_id',
        'parent_id',
        'body',
        'status',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->where('status', self::STATUS_PUBLISHED)
            ->oldest();
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function cookedEvent(): BelongsTo
    {
        return $this->belongsTo(CookedEvent::class);
    }

    /** Obiekt, którego dotyczy komentarz — dokładnie jeden z trzech. */
    public function subject(): Post|Recipe|CookedEvent|null
    {
        return $this->post ?? $this->recipe ?? $this->cookedEvent;
    }

    /** Kto powinien dostać powiadomienie o tym komentarzu. */
    public function notifiableUserId(): ?string
    {
        $subject = $this->subject();

        return match (true) {
            $subject instanceof Post => $subject->author_id,
            $subject instanceof Recipe => $subject->author_id,
            $subject instanceof CookedEvent => $subject->user_id,
            default => null,
        };
    }
}
