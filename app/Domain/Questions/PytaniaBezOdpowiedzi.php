<?php

declare(strict_types=1);

namespace App\Domain\Questions;

use App\Jobs\PrzeliczPytaniaBezOdpowiedzi;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * LICZNIK „CZEKA NA ODPOWIEDŹ (N)” NA /pytania — liczony w tle (#372,
 * decyzja właściciela 25.09.2026).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE `COUNT(*)` NA ŻĄDANIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Przy 200 000 wpisów pełny COUNT kosztował 80–140 ms na KAŻDE wejście na
 * `/pytania` (pomiar: docs/product/WLACZENIE_PYTAN_372.md), a jego koszt
 * rośnie z całą tabelą komentarzy — anty-złączenie gościa skanuje ją
 * w całości.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE JEDNA GLOBALNA LICZBA DLA WSZYSTKICH
 * ────────────────────────────────────────────────────────────────────────
 *
 * Licznik zależy od widza, a blokad nie wolno omijać (AGENTS.md; audyt
 * backendu w #372): pytanie osoby w blokadzie jest dla widza niewidoczne,
 * a odpowiedź osoby w blokadzie nie jest dla niego odpowiedzią. Wpisy „dla
 * obserwujących” i własne niepubliczne pytania widzi tylko część osób.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROZWIĄZANIE: GLOBALNA LICZBA W TLE + DOKŁADNA POPRAWKA WIDZA PO INDEKSIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * G = liczba gościa: publiczne, opublikowane pytania aktywnych autorów bez
 * żadnej widocznej odpowiedzi (`QuestionList::query(null, true)`), osobno
 * dla każdego aktywnego tagu. Liczona w tle (`przelicz()`), trzymana
 * w cache aplikacji (`CACHE_STORE=database`, bez Redisa — AGENTS.md §3)
 * jednym kluczem: jedno `Cache::get()` na odsłonę.
 *
 * Dla zalogowanego widza W:
 *
 *   N(W) = G − (1) + (2) + (3)
 *
 *   (1) pytania z G, których autor jest w blokadzie z W (w dowolną stronę);
 *   (2) niepubliczne pytania, które W widzi (własne, „dla obserwujących”
 *       od obserwowanych) i które są dla niego bez odpowiedzi;
 *   (3) publiczne pytania z odpowiedziami WYŁĄCZNIE od osób w blokadzie
 *       z W — dla wszystkich odpowiedziane, dla W nie.
 *
 * To jest DOKŁADNIE ten sam zbiór co `QuestionList::query(W, true)` —
 * uzasadnienie w docs/product/WLACZENIE_PYTAN_372.md. Każdy składnik zaczyna
 * się od małego zbioru (blokady widza, obserwowani, własne) i idzie po
 * indeksach (`posts_author_published_idx`, `comments_author_idx`), więc nie
 * rośnie z tabelą. (1) i (3) liczymy tylko wtedy, gdy W ma jakąkolwiek
 * blokadę.
 *
 * ŚWIEŻOŚĆ: zdarzenia modeli (`AppServiceProvider`) — zapis pytania,
 * komentarza pod pytaniem i tagu pytania — zlecają `PrzeliczPytaniaBezOdpowiedzi`
 * po commicie, więc nowa odpowiedź odświeża licznik po kilku sekundach pracy
 * kolejki. Harmonogram (`kuking:policz-pytania`, co 5 minut) łapie resztę:
 * zmianę statusu konta autora, scalenie tagów, zapisy z pominięciem modeli.
 * Poprawki (1)–(3) są liczone na żywo. Pusty cache (świeże wdrożenie)
 * liczymy raz na żądanie, zamiast pokazywać zero, które by kłamało.
 */
final class PytaniaBezOdpowiedzi
{
    public const KLUCZ_CACHE = 'pytania:czeka-na-odpowiedz';

    public function __construct(private readonly QuestionList $list = new QuestionList) {}

    /**
     * Przelicza G (wszystkie + każdy aktywny tag) i zapisuje w cache.
     *
     * @return array{wszystkie: int, tagi: array<string, int>}
     */
    public function przelicz(): array
    {
        $wszystkie = $this->list->query(null, true)->count();

        $zbior = $this->list->query(null, true)->toBase()
            ->cloneWithout(['columns', 'orders'])
            ->cloneWithoutBindings(['select', 'order'])
            ->select('posts.id');

        /** @var array<string, int> $tagi */
        $tagi = DB::table('post_tags')
            ->join('tags', 'tags.id', '=', 'post_tags.tag_id')
            ->where('tags.status', Tag::STATUS_ACTIVE)
            ->whereIn('post_tags.post_id', $zbior)
            ->groupBy('tags.slug')
            ->selectRaw('tags.slug, count(*) as ile')
            ->pluck('ile', 'slug')
            ->map(fn ($ile): int => (int) $ile)
            ->all();

        $dane = ['wszystkie' => $wszystkie, 'tagi' => $tagi];
        Cache::forever(self::KLUCZ_CACHE, $dane);

        return $dane;
    }

    /** Liczba gościa z cache; pusty cache liczymy raz na miejscu. */
    public function globalnie(?string $tag = null): int
    {
        $dane = Cache::get(self::KLUCZ_CACHE);

        if (! is_array($dane) || ! isset($dane['wszystkie'], $dane['tagi'])) {
            $dane = $this->przelicz();
        }

        return $tag === null ? (int) $dane['wszystkie'] : (int) ($dane['tagi'][$tag] ?? 0);
    }

    /** N(W) = G − (1) + (2) + (3); dla gościa samo G. */
    public function dla(?User $widz, ?string $tag = null): int
    {
        $wynik = $this->globalnie($tag);

        if ($widz === null) {
            return $wynik;
        }

        $id = $widz->getKey();
        $wBlokadzie = DB::table('blocks')->where('blocker_id', $id)->pluck('blocked_id')
            ->merge(DB::table('blocks')->where('blocked_id', $id)->pluck('blocker_id'))
            ->unique()->values()->all();

        if ($wBlokadzie !== []) {
            // (1) pytania z G, których autora W nie widzi.
            $wynik -= $this->list->query(null, true, $tag)
                ->whereIn('posts.author_id', $wBlokadzie)
                ->count();

            // (3) publiczne pytania odpowiedziane tylko przez osoby w blokadzie z W.
            $wynik += $this->list->query($widz, true, $tag)
                ->where('posts.visibility', Post::VISIBILITY_PUBLIC)
                ->whereHas('allComments', function (Builder $odpowiedzi) use ($wBlokadzie): void {
                    $this->list->visibleAnswers($odpowiedzi, null);
                    $odpowiedzi->whereIn('comments.author_id', $wBlokadzie);
                })
                ->count();
        }

        // (2) niepubliczne pytania widoczne dla W: własne i od obserwowanych.
        $wynik += $this->list->query($widz, true, $tag)
            ->where('posts.visibility', '!=', Post::VISIBILITY_PUBLIC)
            ->where(fn (Builder $autor) => $autor->where('posts.author_id', $id)
                ->orWhereIn('posts.author_id', DB::table('follows')->select('followed_id')->where('follower_id', $id)))
            ->count();

        return max(0, $wynik);
    }

    /**
     * Zleca przeliczenie po commicie bieżącej transakcji. Wołane ze zdarzeń
     * modeli; przy wyłączonym dziale nic nie robi (harmonogram czyści wtedy
     * cache, żeby po włączeniu nie wisiała stara liczba).
     */
    public static function zlecPrzeliczenie(): void
    {
        if (config('kuking.questions.enabled')) {
            PrzeliczPytaniaBezOdpowiedzi::dispatch()->afterCommit();
        }
    }

    /** Czy zapis komentarza może zmienić licznik — tylko komentarz pod pytaniem. */
    public static function dotyczyKomentarza(Comment $komentarz): bool
    {
        if ($komentarz->post_id === null || ! config('kuking.questions.enabled')) {
            return false;
        }

        return Post::withTrashed()->whereKey($komentarz->post_id)->where('kind', Post::KIND_QUESTION)->exists();
    }
}
