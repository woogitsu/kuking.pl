<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\DailyPick;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            // Konto autora aktywne (audyt A5) — ta tablica żyje na tej samej
            // stronie /odkryj co reszta feedu i redakcja mogła wybrać wpis
            // wcześniej, zanim autora zawieszono albo zbanowano.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            ->with(['author.profile.avatar', 'media'])
            ->withCount(['comments' => fn ($q) => $q->widoczneDla($viewer)])
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

        // JEDNA AGREGACJA NA CAŁE `posts`, A NIE JEDNA NA KAŻDE KONTO.
        //
        // Wcześniej sortowanie szło skorelowanym podzapytaniem: baza liczyła
        // `max(published_at)` OSOBNO dla każdego konta, które przeszło
        // `whereHas`, sortowała całość i dopiero potem brała cztery pozycje.
        // A `forViewer` chodzi na trzech ekranach, w tym na publicznym
        // landingu — czyli także dla każdego robota indeksującego.
        //
        // ZMIERZONE na syntetycznych 5000 kont i 20 000 wpisów (AGENTS.md §3
        // wymaga pomiaru przed optymalizacją, więc nie jest to domysł):
        //   * skorelowane podzapytanie — mediana 28,6 ms
        //   * złączenie z agregatem    — mediana 12,9 ms
        // Obie rosną z liczbą wpisów, ale tylko pierwsza rośnie także
        // z liczbą KONT.
        //
        // DLACZEGO NIE CACHE
        // Bo ta lista zależy od widza: wyklucza osoby już obserwowane
        // i zablokowane. Cache musiałby być per widz, czyli byłby to nie tyle
        // cache, co osobna kopia danych dla każdego konta. Pomiar zmienił tu
        // decyzję — pierwotny pomysł z raportu (`cache()->remember` na 10 minut)
        // nie dałby się pogodzić z tym filtrem.
        $ostatniePublikacje = Post::query()
            ->selectRaw('author_id, max(published_at) as ostatnia_publikacja')
            ->publiclyVisible()
            ->groupBy('author_id');

        return User::query()
            ->select('users.*')
            ->where('users.status', User::STATUS_ACTIVE)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('users.id', $excluded))
            // Złączenie wewnętrzne zastępuje `whereHas`: konto bez ani jednego
            // publicznego wpisu po prostu nie ma z czym się złączyć.
            ->joinSub($ostatniePublikacje, 'ostatnie', fn ($join) => $join->on('ostatnie.author_id', '=', 'users.id'))
            // Sortujemy po tym, KIEDY ktoś ostatnio coś pokazał, nie po tym,
            // ile ma obserwujących. Obserwowanie osoby, która nic nie wrzuca,
            // nie zapełnia feedu.
            ->orderByDesc('ostatnie.ostatnia_publikacja')
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

        // DISTINCT ON (author_id), NIE „pobierz z zapasem i odsiej".
        //
        // Wcześniej ta metoda brała 24 najnowsze wpisy (`POSTS * 6`) i
        // dopiero potem odsiewała powtórzonych autorów przez
        // `unique('author_id')`. Komentarz nazywał to świadomym
        // kompromisem, ale zapas 6× był ZGADYWANY, nie gwarantowany —
        // i zmierzone: gdy jedna osoba opublikowała 30 najnowszych wpisów,
        // odsiew zostawiał z nich JEDEN i tablica pokazywała jedną kartę
        // zamiast czterech, mimo że trzech innych autorów miało dostępne
        // (tylko starsze) treści.
        //
        // To nie był przypadek teoretyczny: `COLD_START.md` §4.2 każe
        // gospodarzowi publikować codziennie, więc jeden bardzo aktywny
        // autor jest wzorcem wpisanym w plan startu, nie anomalią.
        //
        // Postgresowy `DISTINCT ON` daje NAJNOWSZY wpis KAŻDEGO autora
        // niezależnie od tego, ilu wpisów dodał — a dopiero z tego zbioru
        // bierzemy cztery najnowsze. Liczba autorów na tablicy nie zależy
        // już od rozkładu publikacji.
        //
        // `ORDER BY` w podzapytaniu MUSI zaczynać się od `author_id` —
        // tego wymaga Postgres od `DISTINCT ON`. Dalsze kolumny wybierają,
        // KTÓRY wpis danego autora wygrywa: najnowszy, a przy równej
        // sekundzie większe `id`.
        $najnowszyKazdegoAutora = Post::query()
            ->selectRaw('DISTINCT ON (posts.author_id) posts.id, posts.published_at')
            ->publiclyVisible()
            ->when($hidden !== [], fn ($query) => $query->whereNotIn('author_id', $hidden))
            // Konto autora aktywne (audyt A5) — patrz uzasadnienie przy
            // DiscoverFeed::paginate(): to jest promowanie treści, więc próg
            // jest surowszy niż zwykłe wejście na adres wpisu.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            ->orderBy('posts.author_id')
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id');

        $wybrane = DB::query()
            ->fromSub($najnowszyKazdegoAutora, 'najnowsze')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::POSTS)
            ->pluck('id');

        if ($wybrane->isEmpty()) {
            return new Collection;
        }

        // Drugie zapytanie po pełne modele z relacjami. Osobno, bo
        // `DISTINCT ON` nie znosi `with()`/`withCount()` w tym samym
        // przebiegu, a kolejność i tak trzeba narzucić na zewnątrz.
        return Post::query()
            ->whereIn('id', $wybrane)
            ->with(['author.profile.avatar', 'media'])
            ->withCount(['comments' => fn ($q) => $q->widoczneDla($viewer)])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();
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
