<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wątki komentarzy w API — strona komentarzy głównych i ich odpowiedzi
 * (issue #1970, D-272).
 *
 * DLACZEGO LIMIT ODPOWIEDZI JEST W BAZIE, A NIE W ZASOBIE
 * Do #1970 strona komentarzy głównych była stronicowana, ale relacja
 * `replies` ładowała się bez granicy: jeden popularny wątek przynosił
 * w jednym żądaniu cały podwątek z autorami i awatarami. Teraz każdy wątek
 * niesie najwyżej `kuking.api.odpowiedzi_w_watku` NAJSTARSZYCH odpowiedzi
 * — `limit()` w ograniczeniu relacji, które Laravel zamienia na
 * `ROW_NUMBER() OVER (PARTITION BY parent_id …)`, więc limit działa na
 * wątek, nie na całą stronę, i obcina się w PostgreSQL-u, nie w PHP.
 * Obok leży `replies_count` (tylko odpowiedzi widoczne dla widza) — z niego
 * zasób wie, czy podać adres dalszych odpowiedzi (`odpowiedzi()`).
 *
 * Filtr blokad (`Comment::scopeWidoczneDla`) stoi przy KAŻDEJ z tych trzech
 * kwerend: w liście, w liczniku i na stronie dalszych odpowiedzi.
 */
final class WatkiKomentarzy
{
    /**
     * @param  HasMany<Comment, *>  $komentarze
     * @return LengthAwarePaginator<int, Comment>
     */
    public static function strona(HasMany $komentarze, ?User $widz): LengthAwarePaginator
    {
        $limit = max(1, (int) config('kuking.api.odpowiedzi_w_watku'));

        return $komentarze
            ->widoczneDla($widz)
            ->with([
                'author.profile.avatar',
                'replies' => fn ($q) => $q->widoczneDla($widz)->orderBy('comments.id')->limit($limit),
                'replies.author.profile.avatar',
            ])
            ->withCount(['replies' => fn (Builder $q) => $q->widoczneDla($widz)])
            ->paginate((int) config('kuking.comments.page_size'));
    }

    /**
     * Odpowiedzi jednego wątku, od najstarszej, stronami z kursorem.
     *
     * @return CursorPaginator<int, Comment>
     */
    public static function odpowiedzi(Comment $korzen, ?User $widz): CursorPaginator
    {
        return $korzen->replies()
            ->widoczneDla($widz)
            ->orderBy('comments.id')
            ->with('author.profile.avatar')
            ->cursorPaginate((int) config('kuking.comments.page_size'));
    }
}
