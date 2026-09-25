<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tags\LiczbyTagowWCache;
use App\Domain\Tags\TagCollage;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Strona tagu — lista wpisów oznaczonych jednym tagiem (D-021, zastępuje
 * `TopicController`).
 *
 * DLACZEGO TO ISTNIEJE
 * Ten sam powód co przy Temacie: tag bez własnej strony jest etykietą,
 * a nie miejscem. Osoba, która nikogo nie obserwuje, ma DOKĄD pójść
 * (SOUL.md 4.7 — cytowane też przy `TopicController`, bo to dokładnie ta
 * sama wartość produktowa, tylko na otwartej taksonomii zamiast zamkniętej).
 *
 * Lista jest CHRONOLOGICZNA, tak jak feed. Żadnego rankingu — ta sama
 * decyzja co przy feedzie obserwowanych i z tego samego powodu: ranking
 * zamienia dzielenie się jedzeniem w konkurs.
 */
class TagController extends Controller
{
    public function __construct(
        private readonly LiczbyTagowWCache $liczby = new LiczbyTagowWCache,
        private readonly TagCollage $collage = new TagCollage,
    ) {}

    /**
     * Spis wszystkich tagów (#273, druga połowa — D-026 dała słownik,
     * ta strona daje do niego wejście; pełne uzasadnienie kolejności
     * w `docs/DECISIONS.md`, D-087).
     *
     * DWIE SEKCJE, ŻADNA PO POPULARNOŚCI
     *   - „Polecane" — `Tag::promowane()`, kolejność redakcyjna gospodarza
     *     (D-021, ustawiana w panelu `/admin/tagi-promowane`).
     *   - „Wszystkie A-Z" — `Tag::aktywne()`, alfabetycznie po nazwie,
     *     stronicowane przyciskiem „Pokaż więcej" (bez infinite scroll).
     * Tak jak na stronie pojedynczego tagu: zero sortowania po liczbie
     * wpisów albo obserwujących — to byłby ranking, którego zakazuje
     * `AGENTS.md`.
     */
    public function index(Request $request): View
    {
        // LICZBA WPISÓW TO TEN SAM ZAKRES CO `tylkoPubliczne()` (D-087, #941),
        // ale liczona w `LiczbyTagowWCache`, z cache per tag (audyt B4 W2).
        // Wcześniej `withCount('posts')` przeliczało wszystkie wpisy stu tagów
        // przy każdej odsłonie. Liczba jest ta sama dla każdego widza, więc
        // cache jej nie zmienia — tylko przesuwa świeżość o kilka minut.
        //
        // ŚWIADOMIE NIE `Post::widoczneDla($widz)` (jak w `show()` niżej):
        // tamten zakres liczy się PER WIDZ (blokady, obserwowanie), a liczba
        // w spisie ma znaczyć to samo dla każdego — to, co zobaczy gość
        // wchodząc na `/tag/{slug}`. Dla zalogowanej osoby to bezpieczne
        // niedoszacowanie, nigdy zawyżenie.
        $polecane = Tag::query()
            ->promowane()
            ->get();

        $tagi = Tag::query()
            ->aktywne()
            ->orderBy('name')
            ->paginate((int) config('kuking.tags.index_page_size'))
            ->withQueryString();

        $publicStats = $this->liczby->forTags(
            array_merge($polecane->modelKeys(), $tagi->getCollection()->modelKeys()),
        );

        foreach ([...$polecane, ...$tagi->getCollection()] as $tag) {
            $tag->setAttribute('posts_count', $publicStats[$tag->getKey()]['postsCount'] ?? 0);
        }

        return view('pages.tags.index', [
            'polecane' => $polecane,
            'tagi' => $tagi,
            'collages' => $this->collage->forTagsWCache(
                array_unique(array_merge($polecane->modelKeys(), $tagi->getCollection()->modelKeys())),
                $request->user(),
            ),
            'publicStats' => $publicStats,
        ]);
    }

    public function show(Request $request, Tag $tag): View|RedirectResponse
    {
        // Tag ukryty (moderacja) nie ma publicznej strony — w odróżnieniu
        // od scalenia (niżej), to nie jest „przenieś się gdzie indziej",
        // tylko „tej treści tu nie ma".
        if ($tag->status === Tag::STATUS_HIDDEN) {
            abort(404);
        }

        // Tag SCALONY zostaje w bazie ze swoim slugiem (SPEC §1.8: „nie
        // kasować źródłowego tagu twardo") — więc stara strona istnieje
        // nadal, ale ma przekierować na kanoniczną. Bez osobnej tabeli
        // przekierowań (`recipe_slug_redirects`) — R1 §1.8 tłumaczy,
        // dlaczego tagi jej nie potrzebują: `Tag::tagKanoniczny()` już
        // wie, dokąd prowadzić. 301, nie domyślne 302: scalenie jest trwałe
        // i nie ma drogi powrotu, więc wyszukiwarka ma przenieść adres
        // na kanoniczny (issue #1350).
        if ($tag->isMerged()) {
            return redirect()->route('tags.show', $tag->tagKanoniczny(), status: 301);
        }

        $widz = $request->user();

        $wpisy = Post::query()
            ->whereHas('tags', fn ($q) => $q->whereKey($tag->getKey()))
            ->published()
            // Ta sama macierz widoczności co wszędzie indziej: wpisy tylko
            // dla obserwujących i prywatne NIE MOGĄ wypłynąć przez tag.
            ->widoczneDla($widz)
            // Zapowiedź przepisu (issue #368) jest na stałe `public`, bo
            // widoczność trzyma PRZEPIS, nie jego zapowiedź — `widoczneDla()`
            // wyżej jej więc nie odcina. Bez tej drugiej bramki strona tagu
            // wypisywała tytuł i zdjęcie główne cudzego przepisu „tylko dla
            // obserwujących" (issue #941). Ten sam zakres i w tej samej roli
            // stoi w `TagFeed`, `TagCollage`, `TagPublicStats`, `FollowingFeed`,
            // `DiscoverFeed`, `DailyBoard` i `PodpowiedziTagow`.
            ->zWidocznymPrzepisem($widz)
            // Strona tagu POLECA treść nieznajomym, tak jak „Świeżo z Kuking":
            // konto pod sankcją nie ma być z niej promowane (audyt A5).
            ->tylkoOdAktywnychAutorow()
            // Relacje karty, licznik komentarzy i zapisów — jeden kontrakt
            // `Post::scopeDlaKarty()` (#1037), ten sam na każdej liście wpisów.
            ->dlaKarty($widz)
            ->latest('published_at')
            ->latest('id')
            // Kursor, nie OFFSET (audyt B4 W2): `paginate()` liczył przy
            // każdej odsłonie pełny COUNT wszystkich wpisów tagu, a dalsze
            // strony płaciły OFFSET-em. Feedy robią to tak samo.
            ->cursorPaginate((int) config('kuking.feed.page_size'))
            ->withQueryString();

        // Indeksowanie (issue #1007). Pusty tag zostaje dla ludzi (D-087:
        // prawdziwe zero i „Dodaj wpis z tym tagiem"), ale wyszukiwarka nie
        // ma dostać ~1400 prawie identycznych stron bez treści — dostaje
        // `noindex, follow`, dopóki tag nie ma choć jednego wpisu widocznego
        // dla gościa. TEN SAM zakres co licznik w spisie (`tylkoPubliczne()`),
        // NIE `$wpisy->total()`: tamto liczy per widz, więc zalogowany autor
        // z prywatnym wpisem dostałby inną dyrektywę niż robot. Jedno
        // zapytanie EXISTS, bez cache — dyrektywa przełącza się od razu
        // przy pierwszym i po ostatnim publicznym wpisie.
        $maPublicznyWpis = $this->tylkoPubliczne(
            Post::query()->whereHas('tags', fn ($q) => $q->whereKey($tag->getKey())),
        )->exists();

        return view('pages.tags.show', [
            'tag' => $tag,
            'indeksowalny' => $maPublicznyWpis,
            'collage' => $this->collage->forTagsWCache([$tag->getKey()], $widz)[$tag->getKey()],
            'tagNote' => $tag->promotion?->note,
            'publicStats' => $publicStats = $this->liczby->forTags([$tag->getKey()])[$tag->getKey()],
            // Opis dla wyszukiwarki mówi liczbę widoczną dla GOŚCIA — robot
            // odwiedza jako anonim. Kursor nie liczy `total()`, więc bierzemy
            // publiczną liczbę z `LiczbyTagowWCache` (ten sam zakres co spis).
            'liczbaPublicznychWpisow' => $publicStats['postsCount'],
            'posts' => $wpisy,
            'obserwowany' => $widz !== null && $widz->isFollowingTag($tag),
        ]);
    }

    /**
     * Wpisy widoczne dla KAŻDEGO — liczba w spisie tagów (D-087) i warunek
     * indeksowania strony tagu (issue #1007) muszą znaczyć to samo.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function tylkoPubliczne($query)
    {
        return $query
            ->publiclyVisible()
            ->tylkoOdAktywnychAutorow()
            ->zWidocznymPrzepisem(null);
    }
}
