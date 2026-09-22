<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\CollectionSaveContext;
use App\Domain\Collections\ZapisyWpisu;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Rules\CollectionNameNotTaken;
use App\Support\PaginationLinks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kolekcje — w interfejsie nazywane "Zeszytem", bo tak o tym myślą ludzie.
 */
class CollectionController extends Controller
{
    public function __construct(
        private readonly SaveRecipeToCollection $save,
        private readonly SavePostToCollection $savePost,
        private readonly ZapisyWpisu $zapisy = new ZapisyWpisu,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Domyślny zeszyt tworzymy dopiero przy pierwszym zapisie — nie
        // pokazujemy pustego folderu osobie, która nic jeszcze nie zapisała.
        return view('pages.collections.index', [
            'saveContext' => app(CollectionSaveContext::class)->parameters($request),
            'saveContent' => app(CollectionSaveContext::class)->content($request),
            'collections' => $user->collections()
                ->withCount(['recipes', 'posts'])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            // Prawa szyna (issue #205) — patrz `ostatnioZapisane()` niżej.
            'ostatnioZapisane' => $this->ostatnioZapisane($user),
        ]);
    }

    /**
     * Pięć rzeczy odłożonych ostatnio — prawa szyna ekranu „Moje" (issue #205).
     *
     * PO CO TO JEST
     * Główna kolumna wypisuje ZESZYTY, a człowiek wchodzi tu najczęściej po
     * jedną konkretną rzecz („gdzie jest to, co zapisałam wczoraj"). Bez tej
     * listy trzeba pamiętać, do którego zeszytu to poszło, wejść i przewinąć.
     * Prawa trzecia ekranu stała przy tym pusta.
     *
     * KOLEJNOŚĆ TO CZAS ODŁOŻENIA, NIE CZAS PUBLIKACJI. Zapisany wczoraj
     * przepis sprzed trzech lat ma stać na górze, bo to WCZORAJ jest tym,
     * co człowiek pamięta. Stąd `max(collection_items.created_at)` z pivotu,
     * a nie `published_at` — i `max()`, bo ta sama rzecz może leżeć
     * w kilku zeszytach naraz.
     *
     * PODZAPYTANIE, NIE `join` — I TO NIE JEST KWESTIA GUSTU.
     * `Recipe::scopeWidoczneDla()` i `Post::scopeWidoczneDla()` pytają
     * o `where('visibility', …)` bez nazwy tabeli. Tabela `collections` ma
     * kolumnę `visibility`, więc dołączenie jej przez `join` robi z tego
     * „column reference visibility is ambiguous" — czyli błąd bazy na
     * ekranie, nie cichą pomyłkę. Skorelowane podzapytanie w `select`
     * zostawia zewnętrzne zapytanie nietknięte.
     *
     * WIDOCZNOŚĆ: oba zapytania idą przez `widoczneDla($user)` ORAZ
     * `dostepnyJakoAutor()`. To są dwie różne granice i obie są obowiązkowe
     * (ustalenie audytowe W5-08) — dokładnie ta sama para, którą ma szyna
     * „Mój zeszyt" na Starcie i lista w `show()` niżej. Do zeszytu odkłada
     * się CUDZE treści, a ich autor może potem zmienić widoczność, cofnąć
     * obserwowanie albo zostać zbanowany.
     *
     * KOSZT NIE ROŚNIE Z ZAWARTOŚCIĄ ZESZYTU: dwa zapytania po `limit(5)`
     * plus dociągnięcie zdjęć i autorów, niezależnie od tego, czy w zeszytach
     * leży pięć rzeczy, czy pięćset (`SzynaBezWachlarzaZapytanTest`).
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function ostatnioZapisane(User $user): \Illuminate\Support\Collection
    {
        $ile = 5;

        $zapisano = fn (string $kolumna, string $tabela) => DB::table('collection_items')
            ->join('collections', 'collections.id', '=', 'collection_items.collection_id')
            ->whereColumn('collection_items.'.$kolumna, $tabela.'.id')
            ->where('collections.owner_id', $user->getKey())
            ->selectRaw('max(collection_items.created_at)');

        $przepisy = Recipe::query()
            ->widoczneDla($user)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->whereHas('collections', fn ($q) => $q->where('collections.owner_id', $user->getKey()))
            ->addSelect(['zapisano_at' => $zapisano('recipe_id', 'recipes')])
            ->with(['heroMedia', 'author.profile'])
            ->orderByDesc('zapisano_at')
            ->orderByDesc('recipes.id')
            ->limit($ile)
            ->get()
            ->map(fn (Recipe $przepis) => [
                'href' => $przepis->url(),
                'nazwa' => $przepis->title,
                'podpis' => 'Przepis · '.$przepis->author->displayName(),
                'media' => $przepis->heroMedia,
                'zapisano_at' => $przepis->zapisano_at,
            ]);

        $wpisy = Post::query()
            ->widoczneDla($user)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->whereHas('collections', fn ($q) => $q->where('collections.owner_id', $user->getKey()))
            ->addSelect(['zapisano_at' => $zapisano('post_id', 'posts')])
            ->with(['media', 'author.profile'])
            ->orderByDesc('zapisano_at')
            ->orderByDesc('posts.id')
            ->limit($ile)
            ->get()
            ->map(fn (Post $wpis) => [
                'href' => $wpis->url(),
                // Wpis nie ma tytułu. Pierwsze słowa są tym, po czym człowiek
                // go rozpozna; wpis bez opisu dostaje uczciwe „Zdjęcie bez
                // opisu", a nie pustą linijkę udającą nazwę.
                'nazwa' => $wpis->body !== null && trim($wpis->body) !== ''
                    ? Str::limit(trim($wpis->body), 60)
                    : 'Zdjęcie bez opisu',
                'podpis' => 'Wpis · '.$wpis->author->displayName(),
                'media' => $wpis->media->first(),
                'zapisano_at' => $wpis->zapisano_at,
            ]);

        // Sortowanie po ZNACZNIKU CZASU, nie po tekście z bazy. `timestamptz`
        // wraca z przesunięciem strefy (`+01`/`+02`), a porównanie tekstowe
        // przestawiłoby dwie rzeczy odłożone po obu stronach zmiany czasu.
        return $przepisy->concat($wpisy)
            ->sortByDesc(fn (array $pozycja) => Carbon::parse($pozycja['zapisano_at'])->getTimestamp())
            ->take($ile)
            ->values();
    }

    public function show(Request $request, Collection $collection): View|RedirectResponse
    {
        $this->authorize('view', $collection);

        $recipes = $collection->recipes()
            ->widoczneDla($request->user())
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->with(['author.profile', 'heroMedia'])
            ->paginate(12);

        $posts = $collection->posts()
            ->widoczneDla($request->user())
            // BRAMKA PRZEPISU, OSOBNA OD `widoczneDla()` (#368). Tamten
            // zakres pyta o WPIS, a wpis zapowiadający przepis ma
            // `visibility = 'public'` na stałe (`WpisWskazujacyPrzepis::dopisz()`)
            // — to nie jest jego widoczność, tylko brak własnego zawężenia,
            // bo bramką ma być PRZEPIS. Bez tego warunku zeszyt rysował
            // `x-post-card` z tytułem, zdjęciem głównym i odnośnikiem, w
            // którym slug niesie ten sam tytuł.
            //
            // W ZESZYCIE TEN WYCIEK DOJRZEWA W CZASIE i to jest jego różnica
            // wobec reszty rodziny. Zapowiedź zostaje tu wskazana na stałe,
            // więc gdy autor zawęzi przepis albo zdejmie go moderacja,
            // treść nie znika sama — a osoba, która ją zapisała, nie ma
            // powodu jej wyjmować, bo w chwili zapisu widziała przepis
            // całkowicie legalnie.
            ->zWidocznymPrzepisem($request->user())
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            // TRZECIA GRANICA: AUTOR PRZEPISU, A NIE AUTOR WPISU (W5-08).
            //
            // Warunek linijkę wyżej pyta o autora WPISU. Wpis zapowiadający
            // przepis może jednak należeć do kogo innego niż przepis: A odkłada
            // sobie do zeszytu zapowiedź przepisu B. Gdy B zostanie zbanowany
            // albo oznaczony do usunięcia, jego przepis daje 403 pod własnym
            // adresem i znika z listy przepisów tego zeszytu (warunek wyżej przy
            // `$recipes`) — ale wpis A dalej stał tu z tytułem, zdjęciem głównym
            // i odnośnikiem, bo `zWidocznymPrzepisem()` liczy widoczność
            // i publikację przepisu, a statusu konta jego autora celowo nie zna
            // (patrz `User::scopeDostepnyJakoAutor()`).
            //
            // Gałąź `recipe_id IS NULL` przepuszcza zwykłe wpisy bez przepisu —
            // bez niej zeszyt straciłby całą zawartość. Ten sam idiom liczy
            // `App\Domain\Tags\PodpowiedziTagow`.
            ->where(fn ($w) => $w->whereNull('posts.recipe_id')
                ->orWhereHas('recipe.author', fn ($autor) => $autor->dostepnyJakoAutor()))
            // `recipe:…` + `recipe.heroMedia` — jak w czterech strumieniach
            // (issue #368). Zeszyt rysuje tę samą kartę `x-post-card`, która
            // czyta z przepisu tytuł, odnośnik, `visibility` na plakietkę
            // i zdjęcie główne; bez doładowania każdy taki wpis to dwa osobne
            // zapytania na stronę.
            ->with([
                'author.profile.avatar',
                'media',
                'recipe:id,title,slug,visibility,hero_media_id',
                'recipe.heroMedia',
            ])
            ->withVisibleCommentCount($request->user())
            // Liczba zapisów i stan „mam to w zeszycie" — TYM SAMYM
            // zapytaniem (issue #275, D-081). Reguły siedzą
            // w `ZapisyWpisu`; tutaj dokładamy tylko kolumnę do SELECT-a.
            ->tap(fn ($q) => $this->zapisy->dolicz($q, $request->user()))
            ->paginate(
                (int) config('kuking.collections.saved_posts_page_size'),
                ['*'],
                'wpisy',
            );

        // Jak przy relacjach (#748): pusta dalsza strona nie jest pustą listą.
        // Każdą listę docinamy do jej własnego zakresu po filtrach widoczności.
        if ($recipes->currentPage() > $recipes->lastPage() || $posts->currentPage() > $posts->lastPage()) {
            $pages = [];
            foreach ([$recipes, $posts] as $paginator) {
                $page = min($paginator->currentPage(), $paginator->lastPage());
                if ($page > 1) {
                    $pages[$paginator->getPageName()] = $page;
                }
            }
            $request->session()->reflash();

            return redirect()->route('collections.show', ['collection' => $collection, ...$pages]);
        }

        // Każdy przycisk przesuwa swoją listę i zachowuje pozycję drugiej.
        PaginationLinks::preserveOtherPage($recipes, $posts);
        PaginationLinks::preserveOtherPage($posts, $recipes);

        return view('pages.collections.show', [
            'saveContext' => $request->user()?->getKey() === $collection->owner_id ? app(CollectionSaveContext::class)->parameters($request) : [],
            'saveContent' => $request->user()?->getKey() === $collection->owner_id ? app(CollectionSaveContext::class)->content($request) : null,
            'collection' => $collection,
            // Policy wyżej pilnuje dostępu do SAMEGO zeszytu i nic nie mówi
            // o tym, co jest w środku. W środku są przepisy wielu różnych
            // autorów, każdy z własną widocznością i własnymi blokadami —
            // więc bez tego filtra publiczny zeszyt publikował cudze (albo
            // własne) treści prywatne, a przepis osoby zablokowanej wracał do
            // oglądającego przez cudzy pojemnik.
            // `dostepnyJakoAutor()` OBOK `widoczneDla()` — to są dwie różne
            // granice (audyt W5-08). `widoczneDla` liczy blokady i widoczność
            // wpisaną przez autora; nie wie nic o tym, że autor został
            // zbanowany albo kasuje konto. Bez tego przepis dawał 403 przy
            // wejściu wprost, a w cudzym zeszycie stał dalej z tytułem,
            // nazwiskiem autora i miniaturą.
            'recipes' => $recipes,
            // Wpisy przechodzą przez ten sam filtr widoczności co przepisy —
            // zeszyt jest cudzym pojemnikiem i nie może pokazywać treści,
            // do której oglądający nie ma prawa.
            //
            // PAGINACJA, NIE `->get()` (audyt zewnętrzny T20).
            //
            // Zeszyt rośnie z użyciem serwisu: każde „Zapisuję" na cudzej
            // karcie wpisu (`post-card.blade.php`) dokłada tu jedną pozycję,
            // bez górnej granicy — dokładnie ten sam kształt problemu co
            // wpisy, komentarze czy powiadomienia, nie jak lista jednostek
            // miary. `recipes()` wyżej paginuje od początku; ten `->get()`
            // był jedynym miejscem w tej metodzie, które tego nie robiło —
            // zmierzone (`ZeszytZapisanychWpisowWydajnoscTest`): przy 30
            // zapisanych wpisach strona ładowała wszystkie 30 naraz.
            //
            // Osobna nazwa strony (`wpisy`, nie domyślne `page`) — inaczej
            // przycisk „Pokaż więcej" pod tą listą przesuwałby PRZY OKAZJI
            // też stronę `recipes()` obok, bo oba paginatory czytałyby ten
            // sam parametr z adresu.
            'posts' => $posts,
            // ILE POZYCJI SCHOWAŁ FILTR — I DLACZEGO TO W OGÓLE POKAZUJEMY.
            //
            // Wpis zapisany, gdy autor pokazywał go obserwującym, znika
            // z widoku po tym, jak przestaniesz go obserwować. Ciche zniknięcie
            // wygląda jak utrata danych („miałam to tu wczoraj"), a pokazanie
            // treści łamie widoczność. Zostaje trzecia droga: powiedzieć, ile
            // pozycji tu jest, nie mówiąc jakich.
            // Paginatory policzyły już wszystkie widoczne pozycje. Liczymy
            // także przepisy i treści usunięte miękko: ich zapisy nadal istnieją.
            // withTrashed dotyczy wyłącznie COUNT, nigdy listy ani treści.
            'niewidoczne' => max(0, $collection->recipes()->withTrashed()->count() - $recipes->total())
                + max(0, $collection->posts()->withTrashed()->count() - $posts->total()),
            // PRAWA SZYNA (issue #205): pozostałe zeszyty tej samej osoby.
            //
            // Zeszyt jest jednym z kilku pojemników i wejście do drugiego
            // wymagało do tej pory cofnięcia się na „Moje". To jest jedyna
            // czynność, którą naprawdę robi się Z TEGO ekranu — dlatego
            // szyna dostaje ją, a nie kartę „po co jest zeszyt".
            //
            // Widzowi spoza konta pokazujemy WYŁĄCZNIE zeszyty publiczne:
            // prywatny zeszyt nie ma prawa ujawnić nawet nazwy. Warunek jest
            // ten sam, który sprawdza `CollectionPolicy::view()` przy wejściu
            // na adres — powtórzony tutaj, bo Policy pilnuje wejścia,
            // a nie zapytania budującego listę.
            'inneZeszyty' => $collection->owner === null ? collect() : $collection->owner->collections()
                ->whereKeyNot($collection->getKey())
                ->when(
                    $request->user()?->getKey() !== $collection->owner_id,
                    fn ($query) => $query->where('visibility', 'public'),
                )
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->limit(5)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                new CollectionNameNotTaken($user->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['required', 'in:public,private'],
        ], [
            'name.required' => 'Podaj nazwę zeszytu — na przykład „Na święta”.',
            'name.min' => 'Nazwa zeszytu musi mieć co najmniej 2 znaki. Dopisz kilka liter.',
            // Bez tego wypadał szablon ogólny: „Pole «nazwa zeszytu» jest za
            // długie — może mieć najwyżej 120 znaków." Mówił, co jest źle,
            // ale nie mówił, co zrobić.
            'name.max' => 'Ta nazwa jest za długa. Zmieść się w 120 znakach — wystarczy krótka nazwa, na przykład „Na święta”.',
            'description.max' => 'Ten opis jest za długi. Zmieść się w 500 znakach.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — dwie opcje z ekranu, wprost.
            'visibility.in' => 'Zaznacz, kto ma widzieć ten zeszyt: wszyscy czy tylko Ty.',
            /*
             * `required` BEZ WŁASNEGO ZDANIA wypadał jako szablon ogólny:
             * „Pole «widoczność» jest wymagane. Uzupełnij je, żeby wysłać
             * formularz." Na ekranie nie ma niczego o nazwie „widoczność" —
             * jest pytanie „Kto ma widzieć ten zeszyt?" i dwa przyciski
             * wyboru. Dla człowieka brak zaznaczenia i zaznaczenie czegoś
             * spoza listy to ta sama sytuacja, więc zdanie jest to samo.
             */
            'visibility.required' => 'Zaznacz, kto ma widzieć ten zeszyt: wszyscy czy tylko Ty.',
        ]);

        try {
            $collection = $user->collections()->create($data);
        } catch (UniqueConstraintViolationException) {
            // Walidacja wyżej sprawdza to samo, ale między jej SELECT-em
            // a tym INSERT-em jest okno — a podwójne kliknięcie „Załóż zeszyt”
            // to w grupie 50+ norma, nie wyjątek. Bez tego łapania drugie
            // żądanie kończy się błędem 500 zamiast zdaniem po polsku.
            return back()
                ->withInput()
                ->withErrors(['name' => 'Masz już zeszyt o tej nazwie. Wybierz inną.']);
        }

        return redirect()->route('collections.show', ['collection' => $collection, ...app(CollectionSaveContext::class)->parameters($request)])->with('status', 'Zeszyt utworzony.');
    }

    public function saveRecipe(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $collection = $this->selectedCollection($request);

        $target = $this->save->handle($request->user(), $model, $collection);

        if ($request->boolean('open_collection')) {
            return redirect()->route('collections.show', $target)->with('status', "Zapisane w zeszycie „{$target->name}”.");
        }

        return back()->with('status', "Zapisane w zeszycie „{$target->name}”.");
    }

    public function removeRecipe(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();

        $this->save->remove($request->user(), $model);

        return back()->with('status', 'Usunięte z zeszytu.');
    }

    /**
     * „Zapisuję" na karcie wpisu (UI kit v2, ekran 01).
     *
     * Policy `view` PRZED zapisem, nie po. Bez tego dałoby się odłożyć
     * do zeszytu cudzy wpis prywatny, znając sam jego identyfikator —
     * a UUID w adresie to nie autoryzacja (AGENTS.md §7).
     */
    public function savePost(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('view', $post);

        $collection = $this->selectedCollection($request);

        $target = $this->savePost->handle($request->user(), $post, $collection);

        if ($request->boolean('open_collection')) {
            return redirect()->route('collections.show', $target)->with('status', "Zapisane w zeszycie „{$target->name}”.");
        }

        return back()->with('status', "Zapisane w zeszycie „{$target->name}”.");
    }

    /**
     * Nieistniejący i cudzy zeszyt dają ten sam komunikat (issue #473).
     * „bail” zatrzymuje walidację przed zapytaniem do kolumny UUID, gdy
     * wejście nie ma poprawnego formatu. Brak wyboru oznacza zeszyt domyślny.
     */
    private function selectedCollection(Request $request): ?Collection
    {
        $data = $request->validate([
            'collection_id' => [
                'bail', 'nullable', 'uuid',
                Rule::exists('collections', 'id')->where('owner_id', $request->user()->getKey()),
            ],
        ], [
            'collection_id.uuid' => 'Odśwież stronę i ponownie wybierz zeszyt do zapisania.',
            'collection_id.exists' => 'Odśwież stronę i ponownie wybierz zeszyt do zapisania.',
        ]);

        return isset($data['collection_id'])
            ? $request->user()->collections()->findOrFail($data['collection_id'])
            : null;
    }

    public function removePost(Request $request, Post $post): RedirectResponse
    {
        // Bez `authorize`: usuwamy z WŁASNEGO zeszytu i tylko z własnego
        // (`remove` chodzi po kolekcjach tej osoby). Wpis, którego już nie
        // wolno oglądać, tym bardziej musi dać się stamtąd wyjąć — inaczej
        // zostawałby w zeszycie na zawsze.
        //
        // TO NIE JEST OBEJŚCIE REGUŁY „UUID W ADRESIE TO NIE AUTORYZACJA"
        // (AGENTS.md §7), tylko granica OSTRZEJSZA niż Policy. Policy
        // odpowiada na pytanie „czy wolno Ci ruszyć TEN wpis"; tutaj pytanie
        // brzmi inaczej: „z czyjego zeszytu wyjmujemy". Zakres akcji jest
        // przypięty do `$request->user()`, więc identyfikator w adresie nie
        // daje dostępu do niczyjego cudzego zeszytu — obca osoba, która
        // wyśle tu UUID wpisu leżącego w zeszycie kogoś innego, nie ruszy
        // tamtego wiersza (`ZeszytPrzyjmujeWpisyTest`:
        // „obca osoba nie wyjmie wpisu z cudzego zeszytu"). Gość nie dochodzi
        // tu wcale — trasa stoi za `auth` (`routes/web.php`).
        $this->savePost->remove($request->user(), $post);

        // KOMUNIKAT MÓWI, CO SIĘ STAŁO, I DAJE DROGĘ POWROTU (audyt L1).
        //
        // „Usunięte z zeszytu." nie mówiło, CO zostało usunięte ani czy
        // zniknęło z jednego zeszytu, czy ze wszystkich — a wyjmujemy ze
        // wszystkich zeszytów tej osoby, więc trzeba to napisać wprost.
        // Zamiast pytania „czy na pewno" PRZED akcją (wyjęcie jest
        // odwracalne) idzie przycisk powrotu PO niej; rysuje go
        // `components/layout.blade.php` w tym samym obszarze `aria-live`,
        // co komunikat.
        return back()
            ->with('status', 'Wpis wyjęty z zeszytu. Nie usunęliśmy go z serwisu — możesz go zapisać ponownie.')
            ->with('status_powrot', [
                'akcja' => route('collections.save-post', $post),
                'etykieta' => 'Zapisz ponownie',
            ]);
    }

    public function destroy(Request $request, Collection $collection): RedirectResponse
    {
        $this->authorize('delete', $collection);

        $collection->delete();

        return redirect()->route('collections.index')->with('status', 'Zeszyt usunięty.');
    }
}
