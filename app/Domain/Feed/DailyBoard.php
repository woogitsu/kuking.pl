<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\DailyPick;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * „kuKINGi na dziś" — kilka osób i kilka dań wartych zobaczenia dzisiaj.
 *
 * To NIE jest ranking i nigdy nim nie będzie. Kolejność decyzyjna:
 *
 *   1. wybór redakcyjny gospodarza na dzisiejszy dzień (tabela `daily_picks`),
 *   2. jeśli go nie ma — chronologicznie, maksymalnie JEDNA pozycja
 *      od tej samej osoby.
 *
 * Ograniczenie „jedna od osoby" jest tu najważniejsze. Bez niego jedna aktywna
 * osoba zasłania cały serwis, a nowy użytkownik odnosi wrażenie, że „tu jest
 * tylko ta pani" (docs/product/COLD_START.md).
 *
 * Świadomie NIE MA tu żadnej miary popularności: ani liczby obserwujących,
 * ani liczby komentarzy jako kryterium sortowania. Publiczne rankingi
 * natychmiast dzielą ludzi na dwie klasy i wyłączają publikowanie
 * u większości (AGENTS.md).
 */
final class DailyBoard
{
    /** Ile osób i ile wpisów pokazujemy. Krótka lista, nie ściana kafelków. */
    private const PEOPLE = 4;

    private const POSTS = 4;

    /**
     * @return array{people: Collection<int, User>, posts: Collection<int, Post>, curated: bool, notes: array<string, string>}
     */
    public function forViewer(?User $viewer): array
    {
        $picks = DailyPick::query()->forDate()->get();

        if ($picks->isNotEmpty()) {
            return $this->fromCuratedPicks($picks, $viewer);
        }

        return [
            'people' => $this->peopleToFollow($viewer, self::PEOPLE),
            'posts' => $this->automaticPosts($viewer),
            'curated' => false,
            'notes' => [],
        ];
    }

    /**
     * Wybór redakcyjny. Pozycje niedostępne dla tego widza (blokada, treść
     * schowana w międzyczasie) po prostu wypadają — tablica nie może pokazać
     * pustej karty ani zdradzić, że coś tu było.
     *
     * @param  Collection<int, DailyPick>  $picks
     * @return array{people: Collection<int, User>, posts: Collection<int, Post>, curated: bool, notes: array<string, string>}
     */
    private function fromCuratedPicks(Collection $picks, ?User $viewer): array
    {
        $hidden = $this->hiddenAuthorIdsFor($viewer);
        $notes = [];

        foreach ($picks as $pick) {
            if ($pick->note !== null) {
                $notes[$pick->subject_id] = $pick->note;
            }
        }

        $people = User::query()
            ->whereIn('id', $picks->where('subject_type', DailyPick::TYPE_USER)->pluck('subject_id'))
            ->whereNotIn('id', $hidden)
            ->where('status', User::STATUS_ACTIVE)
            ->with(['profile.avatar', 'posts' => fn ($query) => $query->publiclyVisible()->latest('published_at')->limit(3)->with('media')])
            ->get();

        $posts = Post::query()
            ->whereIn('id', $picks->where('subject_type', DailyPick::TYPE_POST)->pluck('subject_id'))
            ->publiclyVisible()
            ->whereNotIn('author_id', $hidden)
            ->with(['author.profile.avatar', 'media'])
            ->withCount('comments')
            ->get();

        return [
            'people' => $people,
            'posts' => $posts,
            'curated' => true,
            'notes' => $notes,
        ];
    }

    /**
     * Osoby, których widz jeszcze nie obserwuje, a które ostatnio coś pokazały.
     *
     * Jedyne miejsce w aplikacji, które odpowiada na pytanie „kogo pokazać
     * do zaobserwowania" — używa tego i tablica „kuKINGi na dziś",
     * i onboarding. Dwie różne odpowiedzi na to samo pytanie rozjechałyby się
     * przy pierwszej zmianie.
     *
     * @return Collection<int, User>
     */
    public function peopleToFollow(?User $viewer, int $limit = self::PEOPLE): Collection
    {
        $excluded = $this->hiddenAuthorIdsFor($viewer);

        if ($viewer !== null) {
            $excluded = array_values(array_unique([
                ...$excluded,
                $viewer->getKey(),
                ...$viewer->following()->pluck('users.id')->all(),
            ]));
        }

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('id', $excluded))
            ->whereHas('posts', fn ($query) => $query->publiclyVisible())
            // Sortujemy po tym, KIEDY ktoś ostatnio coś pokazał, nie po tym,
            // ile ma obserwujących. Obserwowanie osoby, która nic nie wrzuca,
            // nie zapełnia feedu.
            ->orderByDesc(
                Post::query()
                    ->selectRaw('max(published_at)')
                    ->whereColumn('posts.author_id', 'users.id')
                    ->publiclyVisible(),
            )
            ->with(['profile.avatar', 'posts' => fn ($query) => $query->publiclyVisible()->latest('published_at')->limit(3)->with('media')])
            ->limit($limit)
            ->get();
    }

    /**
     * Świeże wpisy, maksymalnie jeden od osoby.
     *
     * @return Collection<int, Post>
     */
    private function automaticPosts(?User $viewer): Collection
    {
        $hidden = $this->hiddenAuthorIdsFor($viewer);

        return Post::query()
            ->publiclyVisible()
            ->when($hidden !== [], fn ($query) => $query->whereNotIn('author_id', $hidden))
            ->with(['author.profile.avatar', 'media'])
            ->withCount('comments')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            // Pobieramy z zapasem i dopiero potem odsiewamy powtórzonych
            // autorów. Przy tej skali to jest tańsze i prostsze niż okienkowe
            // zapytanie z DISTINCT ON.
            ->limit(self::POSTS * 6)
            ->get()
            ->unique('author_id')
            ->take(self::POSTS)
            ->values();
    }

    /** @return list<string> */
    private function hiddenAuthorIdsFor(?User $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        return array_values(array_unique([
            ...$viewer->blocking()->pluck('users.id')->all(),
            ...$viewer->blockedBy()->pluck('users.id')->all(),
        ]));
    }
}
