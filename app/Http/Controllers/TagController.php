<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\ZapisyWpisu;
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
        private readonly ZapisyWpisu $zapisy = new ZapisyWpisu,
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
        // ŚWIADOMIE BEZ `Post::widoczneDla($widz)` (który `show()` niżej
        // dokłada dla blokad): tamten zakres liczy się PER WIDZ, a liczba
        // w spisie ma znaczyć to samo dla każdego — to, co zobaczy gość
        // wchodząc na `/tag/{slug}`. Od #1338 lista na stronie tagu to ten
        // sam zakres publiczny dla każdego widza; różnić się może tylko
        // o wpisy osób, z którymi widz ma blokadę.
        //
        // Wpis z własną treścią liczy się według własnej widoczności — tak
        // jak stoi na stronie tagu (issue #1377); `LiczbyTagowWCache` trzyma
        // ten sam zakres co `tylkoPubliczne()`, więc licznik i warunek
        // indeksowania (#1007) dalej znaczą to samo. `TagPublicStats`,
        // `TagCollage` i `PodpowiedziTagow` zostają przy węższym
        // `zWidocznymPrzepisem()` — ich testy utrwalają to od #941; różnica
        // to niedoszacowanie, nigdy zawyżenie (D-087).
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
            // TYLKO WPISY PUBLICZNE — DLA KAŻDEGO, TAKŻE DLA AUTORA (decyzja
            // właściciela z 26.09, #1338). Strona tagu jest miejscem
            // publicznym: każdy widz, zalogowany czy nie, widzi na niej to
            // samo co gość. Wpisy „tylko dla obserwujących" i „tylko dla
            // mnie" nie wypływają tu ani obserwującemu, ani samemu autorowi
            // — autor ma je w swoim profilu i w „Moje". Zakres jest ten sam
            // co licznik w spisie i warunek indeksowania (`tylkoPubliczne()`
            // niżej), więc liczba, dyrektywa robota i lista znaczą jedno.
            // Pilnuje `FeedTagowTylkoOpublikowaneTest::test_strona_tagu_pokazuje_kazdemu_tylko_wpisy_publiczne_takze_autorowi`.
            ->tap(fn ($query) => $this->tylkoPubliczne($query))
            // `widoczneDla($widz)` zostaje dla BLOKAD: publiczny wpis osoby,
            // z którą widz ma blokadę (w którąkolwiek stronę), nadal nie
            // może wypłynąć przez tag.
            ->widoczneDla($widz)
            // `tylkoPubliczne()` niesie też dwie bramki, które stały tu
            // osobno:
            //   - `zWidocznymPrzepisemAlboWlasnaTrescia(null)` — zapowiedź
            //     przepisu (issue #368) jest na stałe `public`, bo widoczność
            //     trzyma PRZEPIS; bez tej bramki strona tagu wypisywała tytuł
            //     i zdjęcie cudzego przepisu „tylko dla obserwujących"
            //     (issue #941). Z `null`, nie z `$widz`: własny nie-publiczny
            //     przepis autora też tu nie wypływa (#1338). Wpis z własną
            //     treścią zostaje według własnej widoczności (issue #1377);
            //     przepis zdejmuje z karty `Post::ukryjNiedostepnePrzepisy()`
            //     po paginacji;
            //   - `tylkoOdAktywnychAutorow()` — strona tagu POLECA treść
            //     nieznajomym, konto pod sankcją nie ma być z niej promowane
            //     (audyt A5).
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
            // Kursor, nie OFFSET (audyt B4 W2): `paginate()` liczył przy
            // każdej odsłonie pełny COUNT wszystkich wpisów tagu, a dalsze
            // strony płaciły OFFSET-em. Feedy robią to tak samo.
            ->cursorPaginate((int) config('kuking.feed.page_size'))
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

        // Własne niepubliczne wpisy widza z tym tagiem — TYLKO do zdania,
        // które tłumaczy autorowi, dlaczego ich tu nie ma (#681, #1392,
        // #1338). Od #1338 lista wyżej ich nie pokazuje, więc bez tego
        // zdania autor widziałby „dodałem wpis z tagiem i go nie ma".
        // Liczymy wyłącznie wpisy widza: o cudzych, niewidocznych wpisach
        // nie mówimy nawet półsłówkiem.
        $wlasneNiepubliczne = $widz === null ? [] : Post::query()
            ->whereHas('tags', fn ($q) => $q->whereKey($tag->getKey()))
            ->enabledKinds()
            ->published()
            ->where('author_id', $widz->getKey())
            ->where('visibility', '!=', Post::VISIBILITY_PUBLIC)
            ->selectRaw('visibility, count(*) as liczba')
            ->groupBy('visibility')
            ->toBase()
            ->pluck('liczba', 'visibility')
            ->map(fn ($liczba): int => (int) $liczba)
            ->all();

        return view('pages.tags.show', [
            'tag' => $tag,
            'wlasneNiepubliczne' => $wlasneNiepubliczne,
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
            // Wpis z własną treścią według własnej widoczności (issue #1377),
            // tak jak na liście strony tagu.
            ->zWidocznymPrzepisemAlboWlasnaTrescia(null);
    }
}
