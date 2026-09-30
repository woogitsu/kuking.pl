<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Carbon;

/**
 * „Dziękuję" autora treści pod jednym komentarzem (issue #2355, F11).
 *
 * `$fillable` PUSTE: komentarz i osobę ustawia wyłącznie
 * `App\Domain\Comments\Actions\ThankForComment`, po sprawdzeniu Policy.
 * Nie ma liczników ani sortowania po tej tabeli — patrz migracja.
 *
 * @property string $id
 * @property string $comment_id
 * @property string $thanker_id
 * @property Carbon $created_at
 */
class CommentThank extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'comment_thanks';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Które komentarze z listy mają podziękowanie, które WIDZI ten widz —
     * mapa „id komentarza → true", JEDNYM zapytaniem dla całej listy (korzenie
     * i wczytane odpowiedzi). Stan widzą dwie osoby: dziękujący i autor
     * komentarza; dla gościa i dla każdego innego wynik jest pusty.
     *
     * @param  iterable<Comment>  $komentarze  komentarze główne z wczytanymi `replies`
     * @return array<string, true>
     */
    public static function dlaListy(iterable $komentarze, ?User $widz): array
    {
        if ($widz === null) {
            return [];
        }

        $komentarze = $komentarze instanceof AbstractPaginator ? $komentarze->getCollection() : collect($komentarze);
        $ids = $komentarze
            ->flatMap(fn (Comment $c) => $c->relationLoaded('replies') ? $c->replies->modelKeys() : [])
            ->merge($komentarze->modelKeys())
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return self::query()
            ->whereIn('comment_id', $ids)
            ->where(fn ($q) => $q->where('thanker_id', $widz->getKey())
                ->orWhereIn('comment_id', Comment::query()->withTrashed()
                    ->whereIn('id', $ids)->where('author_id', $widz->getKey())->select('id')))
            ->pluck('comment_id')
            ->mapWithKeys(fn ($id): array => [(string) $id => true])
            ->all();
    }

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'comment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function thanker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'thanker_id');
    }
}
