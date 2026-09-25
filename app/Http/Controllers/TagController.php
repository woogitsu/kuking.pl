<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\ZapisyWpisu;
use App\Domain\Tags\TagCollage;
use App\Domain\Tags\TagPublicStats;
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
        private readonly ZapisyWpisu $zapisy = new ZapisyWpisu,
        private readonly TagPublicStats $publicStats = new TagPublicStats,
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
        // JEDNO domknięcie, użyte w obu sekcjach, żeby liczba wpisów nigdy
        // nie rozjechała się między „Polecane" a „Wszystkie" — ten sam
        // zakres, który komentarz `Post::scopeTylkoOdAktywnychAutorow()`
        // wymienia wprost jako przeznaczony m.in. dla feedu tagów.
        //
        // ŚWIADOMIE NIE `Post::widoczneDla($widz)` (jak w `show()` niżej):
        // ten zakres liczy się PER WIDZ (blokady, obserwowanie), więc na
        // liście z jednym zapytaniem dla wielu tagów naraz dałby inną
        // liczbę każdej zalogowanej osobie — nie do zmierzenia raz i nie
        // do wytłumaczenia. `publiclyVisible()` daje TĘ SAMĄ liczbę
        // każdemu i jest dokładnie tym, co zobaczy gość wchodząc na
        // `/tag/{slug}` — dla zalogowanej osoby to bezpieczne
        // niedoszacowanie, nigdy zawyżenie (D-087).
        //
        // `zWidocznymPrzepisemAlboWlasnaTrescia(null)` (w `tylkoPubliczne()`)
        // — z tego samego powodu co w `show()`: zapowiedź przepisu jest na
        // stałe `public`, więc `publiclyVisible()` jej nie odcina. Bez tej
        // bramki zapowiedź przepisu „tylko dla obserwujących", ukrytego albo
        // usuniętego podnosiła liczbę, choć gość na stronie tagu jej nie
        // zobaczy, a `TagPublicStats` i `TagCollage` na tym samym ekranie ją
        // pomijają (issue #941).
        //
        // `null`, nie widz: liczba ma być ta sama dla każdego. Wpis z własną
        // treścią liczy się według własnej widoczności — tak jak stoi na
        // stronie tagu (issue #1377). `TagPublicStats`, `TagCollage`
        // i `PodpowiedziTagow` zostają przy węższym `zWidocznymPrzepisem()`
        // — ich testy utrwalają to od #941; różnica to niedoszacowanie,
        // nigdy zawyżenie (D-087).
        $liczPubliczneWpisy = fn ($query) => $this->tylkoPubliczne($query);

        $polecane = Tag::query()
            ->promowane()
            ->withCount(['posts' => $liczPubliczneWpisy])
            ->get();

        $tagi = Tag::query()
            ->aktywne()
            ->withCount(['posts' => $liczPubliczneWpisy])
            ->orderBy('name')
            ->paginate((int) config('kuking.tags.index_page_size'))
            ->withQueryString();

        return view('pages.tags.index', [
            'polecane' => $polecane,
            'tagi' => $tagi,
            'collages' => $this->collage->forTags(
                array_unique(array_merge($polecane->modelKeys(), $tagi->getCollection()->modelKeys())),
                $request->user(),
            ),
            'publicStats' => $this->publicStats->forTags(
                array_merge($polecane->modelKeys(), $tagi->getCollection()->modelKeys()),
            ),
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
        // wie, dokąd prowadzić.
        if ($tag->isMerged()) {
            return redirect()->route('tags.show', $tag->tagKanoniczny());
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
            //
            // Wpis z własną treścią zostaje według własnej widoczności
            // (issue #1377); przepis zdejmuje z karty
            // `Post::ukryjNiedostepnePrzepisy()` po paginacji.
            ->zWidocznymPrzepisemAlboWlasnaTrescia($widz)
            // Strona tagu POLECA treść nieznajomym, tak jak „Świeżo z Kuking":
            // konto pod sankcją nie ma być z niej promowane (audyt A5).
            ->tylkoOdAktywnychAutorow()
            ->with([
                'author.profile.avatar',
                'media',
                'tags:id,slug,name,status',
                // ZMIERZONE, NIE ZAŁOŻONE (pomiar N+1, `scripts/pomiar-n1.php`).
                // `components/post-card.blade.php` czyta z wpisu WSKAZUJĄCEGO
                // PRZEPIS trzy rzeczy: widoczność (`$post->recipe?->visibility`),
                // tytuł z odnośnikiem i — gdy wpis nie ma własnych zdjęć —
                // zdjęcie główne przepisu. Bez tej linijki każda z tych rzeczy
                // szła osobnym `select * from recipes where id = ?`.
                //
                // Pomiar na stronie tagu (10 000 wpisów, po `ANALYZE`):
                // 25 / 31 / 36 zapytań przy 5 / 15 / 25 wpisach na stronie —
                // czyli jedno zapytanie na każdy wpis wskazujący przepis.
                // Po tej zmianie liczba jest TA SAMA przy 5, 15 i 25 wierszach: 23.
                //
                // Ten sam zestaw kolumn co w `FollowingFeed`, `DiscoverFeed`
                // i `TagFeed` (issue #368) i z tego samego powodu: kolumna
                // pominięta w selekcie wraca jako `null`, więc karta po cichu
                // napisałaby „publicznie" pod przepisem widocznym tylko dla
                // obserwujących. Strona tagu była JEDYNYM z czterech strumieni
                // wpisów bez tego `with()`.
                'recipe:id,title,slug,visibility,hero_media_id',
                'recipe.heroMedia',
            ])
            ->withVisibleCommentCount($widz)
            // Liczba zapisów i stan „mam to w zeszycie" — TYM SAMYM
            // zapytaniem (issue #275, D-081). Reguły siedzą w `ZapisyWpisu`,
            // tutaj jest tylko miejsce, w którym dokładamy kolumnę do SELECT-a.
            ->tap(fn ($query) => $this->zapisy->dolicz($query, $widz))
            ->latest('published_at')
            ->latest('id')
            ->paginate((int) config('kuking.feed.page_size'))
            ->withQueryString()
            ->tap(fn ($strona) => Post::ukryjNiedostepnePrzepisy($strona->items(), $widz));

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
            'collage' => $this->collage->forTags([$tag->getKey()], $widz)[$tag->getKey()],
            'tagNote' => $tag->promotion?->note,
            'publicStats' => $this->publicStats->forTags([$tag->getKey()])[$tag->getKey()],
            'posts' => $wpisy,
            'obserwowany' => $widz !== null && $widz->isFollowingTag($tag),
        ]);
    }

    /**
     * Wpisy widoczne dla KAŻDEGO — liczba w spisie tagów (D-087) i warunek
     * indeksowania strony tagu (issue #1007) muszą znaczyć to samo.
     *
     * `zWidocznymPrzepisemAlboWlasnaTrescia(null)`, nie węższe
     * `zWidocznymPrzepisem(null)`: wpis z własną treścią liczy się według
     * własnej widoczności, tak jak na stronie tagu (issue #1377) — inaczej
     * ukrycie cudzego przepisu zdejmowałoby z licznika i z indeksowania
     * wpis, który dalej stoi na liście.
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
            ->zWidocznymPrzepisemAlboWlasnaTrescia(null);
    }
}
