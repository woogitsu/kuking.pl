<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Feed tematów — wpisy z tematów, które ta osoba obserwuje (issue #31).
 *
 * PO CO ISTNIEJE
 * Nowe konto nikogo nie obserwuje, więc feed obserwowanych jest z definicji
 * pusty. Do tej pory pokazywaliśmy wtedy „Świeżo z Kuking", czyli wszystko
 * jak leci. To działa, ale nie jest ŻADNYM feedem tej osoby — nie różni się
 * niczym od tego, co widzi każdy inny.
 *
 * Tematy wybrane w onboardingu są jedyną rzeczą, którą o kimś wiemy
 * w pierwszej minucie. Feed z nich zbudowany jest pierwszym ekranem, który
 * należy do tego człowieka, a nie do serwisu — i to jest cała różnica między
 * „to nie jest dla mnie" a „o, zupy".
 *
 * CHRONOLOGICZNIE, TAK JAK WSZYSTKO
 * Żadnego rankingu i żadnego „najpopularniejsze w temacie". Ta sama decyzja
 * co przy feedzie obserwowanych i z tego samego powodu: ranking zamienia
 * dzielenie się jedzeniem w konkurs (AGENTS.md).
 */
final class TopicFeed
{
    /** @return CursorPaginator<int, Post> */
    public function paginate(User $viewer, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('kuking.feed.page_size');

        return Post::query()
            ->whereIn('topic_id', $this->obserwowaneTematy($viewer))
            // Ta sama macierz widoczności co wszędzie indziej: temat NIE MOŻE
            // być obejściem ustawień prywatności ani blokady.
            ->widoczneDla($viewer)
            ->tylkoOdAktywnychAutorow()
            ->with([
                'author.profile.avatar',
                'media',
                'recipe:id,title,slug',
                'topic:id,slug,name',
            ])
            ->withCount(['comments' => fn ($q) => $q->widoczneDla($viewer)])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Czy jest z czego zbudować ten feed.
     *
     * Pyta o TREŚĆ, a nie o to, czy człowiek zaznaczył cokolwiek
     * w onboardingu. Ktoś, kto wybrał trzy tematy, w których nikt jeszcze nic
     * nie ugotował, dostałby inaczej pusty ekran — czyli dokładnie to, czemu
     * ten feed ma zapobiegać.
     */
    public function maTresci(User $viewer): bool
    {
        $tematy = $this->obserwowaneTematy($viewer);

        if ($tematy === []) {
            return false;
        }

        return Post::query()
            ->whereIn('topic_id', $tematy)
            ->widoczneDla($viewer)
            ->tylkoOdAktywnychAutorow()
            ->exists();
    }

    /** @return list<string> */
    private function obserwowaneTematy(User $viewer): array
    {
        return $viewer->followedTopics()->pluck('topics.id')->all();
    }
}
