<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Powiadomienie w aplikacji.
 *
 * `type` jest stabilnym łańcuchem (np. `cooked_event.created`), nie nazwą klasy
 * PHP — po to, żeby refaktor kodu nie unieważnił historii powiadomień ani
 * eksportu danych użytkownika.
 */
class Notification extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Ktoś ugotował z Twojego przepisu. Najważniejsze powiadomienie w Kuking. */
    public const TYPE_COOKED = 'cooked_event.created';

    public const TYPE_COMMENT = 'comment.created';

    public const TYPE_REPLY = 'comment.replied';

    public const TYPE_FOLLOW = 'follow.created';

    public const TYPE_SAVED = 'recipe.saved';

    public const TYPE_MODERATION = 'moderation.decision';

    public const TYPE_WELCOME = 'account.welcome';

    /**
     * Pierwszy wpis nowej osoby — powiadomienie dla GOSPODARZA, nie dla
     * autora (issue #6).
     *
     * 55% osób 55-64 i 62% osób 65+ w mediach społecznościowych to wyłącznie
     * odbiorcy treści. Kto opublikuje pierwszy raz, robi to wbrew własnemu
     * nawykowi — i jeśli nikt nie odpowie, drugi raz już nie spróbuje.
     * Gospodarz musi się o tym dowiedzieć NATYCHMIAST, a nie przy najbliższym
     * zajrzeniu do panelu.
     */
    public const TYPE_FIRST_POST = 'post.first';

    protected $fillable = [
        'user_id',
        'actor_id',
        'type',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Powiadomienia, które ta osoba ma prawo zobaczyć — bez tych od osób,
     * z którymi łączy ją blokada.
     *
     * DLACZEGO FILTR PRZY ODCZYCIE, A NIE KASOWANIE PRZY BLOKADZIE
     *
     * `NotifyUser` od początku odmawiał tworzenia NOWYCH powiadomień, gdy
     * między osobami jest blokada. Nie robił jednak nic z tymi, które już
     * leżały na liście — a ludzie blokują właśnie PO nieprzyjemnym zdarzeniu,
     * czyli wtedy, gdy powiadomienie o nim już istnieje. Blokada zostawiała
     * więc na liście nazwisko i zdjęcie osoby, od której człowiek się odciął.
     *
     * Kasowanie wierszy przy blokadzie byłoby nieodwracalne: odblokowanie
     * kogoś ma przywrócić stan sprzed blokady, a nie zostawić dziurę
     * w historii (i w eksporcie danych — RODO art. 15). Dlatego filtrujemy
     * przy odczycie.
     *
     * Blokada liczy się W OBIE STRONY, tak samo jak w
     * `User::hasBlockRelationWith()` — inaczej byłaby ochroną połowiczną.
     *
     * Powiadomienia bez autora (`actor_id IS NULL` — powitanie, wiadomość
     * od moderacji) zostają zawsze: `NOT EXISTS` nie ma wtedy do czego
     * przyrównać `blocked_id` i nie znajduje żadnego wiersza.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $query->whereNotExists(function (QueryBuilder $sub) use ($viewer): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function (QueryBuilder $warunek) use ($viewer): void {
                    $warunek
                        ->where(function (QueryBuilder $ja) use ($viewer): void {
                            $ja->where('blocks.blocker_id', $viewer->getKey())
                                ->whereColumn('blocks.blocked_id', 'notifications.actor_id');
                        })
                        ->orWhere(function (QueryBuilder $on) use ($viewer): void {
                            $on->whereColumn('blocks.blocker_id', 'notifications.actor_id')
                                ->where('blocks.blocked_id', $viewer->getKey());
                        });
                });
        });
    }
}
