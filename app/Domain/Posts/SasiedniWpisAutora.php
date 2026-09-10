<?php

declare(strict_types=1);

namespace App\Domain\Posts;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sąsiedni wpis TEGO SAMEGO AUTORA — nawigacja „poprzedni / następny" na
 * stronie wpisu (issue: „kolejne zdjęcie", inspiracja Garnek.pl — z jednej
 * fotografii dało się przejść do kolejnej tej samej osoby bez powrotu na
 * profil).
 *
 * CHRONOLOGICZNIE, PO `published_at` — „następny" znaczy nowszy, „poprzedni"
 * starszy, tak jak archiwum profilu (`ProfileController::postsFor()`).
 * Żadnego algorytmu: AGENTS.md §8 chce chronologicznego feedu, a ta sama
 * zasada dotyczy każdej innej kolejności wpisów w serwisie.
 *
 * WIDOCZNOŚĆ JEST TU CAŁYM RYZYKIEM, WIĘC NIE WYMYŚLAMY JEJ OD NOWA.
 * Kandydatów szuka się przez `Post::scopeWidoczneDla()` (blokady w OBIE
 * strony + widoczność public/followers/private, własne wpisy zawsze) ORAZ
 * `User::scopeDostepnyJakoAutor()` (konto autora nie jest zbanowane ani
 * w trakcie kasowania) — DOKŁADNIE ta sama para zakresów, którą stosuje
 * `CollectionController::show()` dla cudzej treści w cudzym pojemniku
 * (audyt W5-08: to są dwie różne granice i obie są konieczne, `widoczneDla`
 * nic nie wie o statusie konta). `PostPolicy::view()` rozstrzyga to samo dla
 * JEDNEGO wpisu pod jego własnym adresem; tutaj potrzebne jest w zapytaniu,
 * bo kandydatów jest więcej niż jeden i żadnego pojedynczo nie sprawdzamy
 * przez Gate.
 *
 * SZKICE AUTORA NIGDY NIE WCHODZĄ DO TEJ NAWIGACJI — decyzja świadoma, nie
 * przeoczenie. `published()` w zapytaniu odcina wpisy bez `published_at`,
 * więc szkic (nawet własny) nie ma gdzie stanąć w kolejności chronologicznej
 * — nie da się go ułożyć „przed" ani „po" żadnym zdjęciu bez daty publikacji.
 * Szkic ma już swoją drogę (ekran edycji) i nie jest częścią przeglądania
 * archiwum, o którym mówi ten ekran — w Garnku też kartkowało się
 * OPUBLIKOWANE zdjęcia, nie robocze wersje.
 *
 * TYLKO OPUBLIKOWANY WPIS MA SĄSIADÓW z tego samego powodu: gdy sam oglądany
 * wpis nie ma `published_at` (własny szkic otwarty wprost pod adresem),
 * pytanie „co było wcześniej/później" nie ma odpowiedzi — obie metody
 * oddają wtedy `null` bez zapytania do bazy.
 *
 * WYDAJNOŚĆ: `posts_author_published_idx (author_id, published_at DESC, id
 * DESC)` (migracja `2026_09_05_000500_create_posts_tables`) obsługuje obie
 * metody — `LIMIT 1` po indeksie, niezależnie od tego, ile wpisów ma autor.
 */
final class SasiedniWpisAutora
{
    /** Starszy wpis tego samego autora — `null`, gdy to najstarszy wpis w archiwum albo widz nie ma do niego prawa. */
    public function poprzedni(Post $post, ?User $widz): ?Post
    {
        if ($post->published_at === null) {
            return null;
        }

        return $this->kandydaci($post, $widz)
            ->where(function (Builder $wcześniej) use ($post): void {
                $wcześniej->where('published_at', '<', $post->published_at)
                    ->orWhere(function (Builder $remis) use ($post): void {
                        $remis->where('published_at', $post->published_at)
                            ->where('id', '<', $post->getKey());
                    });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->with('media')
            ->first();
    }

    /** Nowszy wpis tego samego autora — `null`, gdy to najnowszy wpis w archiwum albo widz nie ma do niego prawa. */
    public function nastepny(Post $post, ?User $widz): ?Post
    {
        if ($post->published_at === null) {
            return null;
        }

        return $this->kandydaci($post, $widz)
            ->where(function (Builder $pozniej) use ($post): void {
                $pozniej->where('published_at', '>', $post->published_at)
                    ->orWhere(function (Builder $remis) use ($post): void {
                        $remis->where('published_at', $post->published_at)
                            ->where('id', '>', $post->getKey());
                    });
            })
            ->orderBy('published_at')
            ->orderBy('id')
            ->with('media')
            ->first();
    }

    /** @return Builder<Post> */
    private function kandydaci(Post $post, ?User $widz): Builder
    {
        return Post::query()
            ->where('author_id', $post->author_id)
            ->published()
            ->widoczneDla($widz)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor());
    }
}
