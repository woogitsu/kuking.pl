<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\CollectionSaveContext;
use App\Domain\Collections\ZapisyWpisu;
use App\Exceptions\BladDlaCzlowieka;
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
                ->withCount([
                    // LICZBA WIDOCZNA — DOKŁADNIE TA SAMA, KTÓRĄ CZŁOWIEK
                    // ZOBACZY PO WEJŚCIU (issue #774).
                    //
                    // PRZED TĄ ZMIANĄ ta karta liczyła bez żadnego filtra
                    // widoczności ani statusu autora, a `show()` niżej filtrował
                    // OBOMA (`widoczneDla()` i `dostepnyJakoAutor()`, audyt
                    // W5-08). Dwa ekrany tego samego zeszytu liczyły więc dwie
                    // różne rzeczy — i to NIE PO RÓWNO: prywatna treść była
                    // wliczona w obie liczby, a treść miękko usunięta (SoftDeletes
                    // dodaje globalny zakres) wypadała tylko z tej karty, nie
                    // z wnętrza zeszytu. Jedna reguła zamiast dwóch przypadkowo
                    // różnych: karta pokazuje WIDOCZNE, wnętrze dokłada „N nie
                    // jest dostępnych" — i te dwie liczby razem dają całość.
                    'recipes as recipes_count' => fn ($q) => $q->widoczneDla($user)
                        ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor()),
                    'posts as posts_count' => fn ($q) => $q->widoczneDla($user)
                        ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor()),
                    // CAŁKOWITA LICZBA ZACHOWANYCH ZAPISÓW — łącznie z tymi
                    // miękko usuniętymi (`withTrashed()`, tak jak w `show()`) —
                    // po to, żeby policzyć RÓŻNICĘ, nie żeby ją pokazać wprost.
                    'recipes as recipes_total_count' => fn ($q) => $q->withTrashed(),
                    'posts as posts_total_count' => fn ($q) => $q->withTrashed(),
                ])
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
            ->map(fn (Post $wpis) => $wpis->kind === Post::KIND_QUESTION ? [
                'href' => $wpis->url(),
                // Pytanie ma własny tytuł i często nic poza nim — to on
                // jest nazwą, nie opis i nie „Zdjęcie bez opisu" (#869).
                'nazwa' => (string) $wpis->title,
                'podpis' => 'Pytanie · '.$wpis->author->displayName(),
                'media' => $wpis->media->first(),
                'zapisano_at' => $wpis->zapisano_at,
            ] : [
                'href' => $wpis->url(),
                // Danie nie ma tytułu. Pierwsze słowa są tym, po czym człowiek
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
            ->with(Recipe::RELACJE_KARTY)
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

        $data = $this->validateCollectionData($request, $user->getKey());

        $this->authorize('create', [Collection::class, $data['visibility']]);

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

    /**
     * Formularz zmiany nazwy, opisu i widoczności — `CollectionPolicy::update()`
     * istniało od dawna, nie istniała droga do niego (issue #777). Do tej
     * zmiany jedynym sposobem cofnięcia publicznego udostępnienia było
     * USUNIĘCIE całego zeszytu razem z jego zawartością.
     */
    public function edit(Request $request, Collection $collection): View
    {
        $this->authorize('update', $collection);

        return view('pages.collections.edit', ['collection' => $collection]);
    }

    /**
     * Te same reguły co `store()` (`validateCollectionData()`), z jednym
     * wyjątkiem: nazwa własnego, niezmienionego zeszytu nie jest dla niego
     * „zajęta" (`CollectionNameNotTaken::$ignoreCollectionId`).
     *
     * KOMUNIKAT NAZYWA ZAKRES ZMIANY WIDOCZNOŚCI, NIE TYLKO FAKT ZAPISU.
     * „Zeszyt zaktualizowany" nie powiedziałoby człowiekowi, czy publiczny
     * adres, który ktoś mógł już mieć zapisany, dalej działa. Zmiana
     * widoczności jest tu decyzją semantyczną (jak w D-088), więc zasługuje
     * na własne zdanie, nie ogólnikowe potwierdzenie zapisu.
     */
    public function update(Request $request, Collection $collection): RedirectResponse
    {
        $this->authorize('update', $collection);

        $bylaPubliczna = $collection->isPublic();

        $data = $this->validateCollectionData($request, $collection->owner_id, $collection->getKey());

        try {
            // `DB::transaction()` — ten sam powód co łapanie w `store()`, plus
            // jeden: na PostgreSQL odrzucony UPDATE psuje całą bieżącą
            // transakcję. Własna (w testach: savepoint) wycofuje tylko jego,
            // więc reszta żądania dalej może rozmawiać z bazą (issue #1339).
            DB::transaction(fn () => $collection->update($data));
        } catch (UniqueConstraintViolationException) {
            // Druga karta zajęła tę nazwę między walidacją a zapisem.
            return back()
                ->withInput()
                ->withErrors(['name' => 'Masz już zeszyt o tej nazwie. Wybierz inną.']);
        }

        $jestPubliczna = $collection->isPublic();

        $status = match (true) {
            $bylaPubliczna && ! $jestPubliczna => 'Zeszyt jest teraz widoczny tylko dla Ciebie. Dawny bezpośredni adres przestał działać dla innych.',
            ! $bylaPubliczna && $jestPubliczna => 'Zeszyt jest teraz widoczny dla wszystkich.',
            default => 'Zeszyt zaktualizowany.',
        };

        return redirect()->route('collections.show', $collection)->with('status', $status);
    }

    /**
     * Wspólne reguły `store()` i `update()`. `$ignoreCollectionId` przepuszcza
     * niezmienioną nazwę własnego zeszytu przy zapisie formularza edycji.
     *
     * @return array{name: string, description: ?string, visibility: string}
     */
    private function validateCollectionData(Request $request, string $ownerId, ?string $ignoreCollectionId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                new CollectionNameNotTaken($ownerId, $ignoreCollectionId),
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
    }

    public function saveRecipe(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $collection = $this->selectedCollection($request);

        // DROGA POWROTU MA WRACAĆ, A NIE ZAPISYWAĆ OD NOWA (issue #775).
        //
        // Przycisk „Zapisz ponownie" pod komunikatem wysyła TEN SAM adres co
        // zwykły zapis i nic poza tokenem. Gdyby zadziałał jak zwykły zapis,
        // przepis wróciłby do JEDNEGO zeszytu (domyślnego), z pustą notatką
        // i dzisiejszą datą — czyli „powrót" po cichu gubiłby to, przed czym
        // ma chronić. Dlatego najpierw sprawdzamy, czy to nie jest powrót po
        // wyjęciu, które sami przed chwilą zrobiliśmy.
        if ($collection === null && $request->input('note') === null) {
            $powrot = $this->przywrocPoWyjeciu($request, 'przepis', (string) $model->getKey());

            if ($powrot !== null) {
                return back()->with('status', $powrot);
            }
        }

        try {
            $target = $this->save->handle($request->user(), $model, $collection);
        } catch (BladDlaCzlowieka $e) {
            // Stan zmienił się w trakcie żądania (#1022): treść ukryta,
            // blokada, zeszyt usunięty w drugiej karcie. Zdanie zamiast 500.
            return back()->withErrors(['collection_id' => $e->getMessage()]);
        }

        if ($request->boolean('open_collection')) {
            return redirect()->route('collections.show', $target)->with('status', "Zapisane w zeszycie „{$target->name}”.");
        }

        return back()->with('status', "Zapisane w zeszycie „{$target->name}”.");
    }

    /**
     * „Usuń z zeszytu" przy przepisie (issue #775).
     *
     * ZAKRES JEST TERAZ WIDOCZNY, A NIE DOMYŚLNY. Wcześniej ta metoda kasowała
     * przepis ze WSZYSTKICH zeszytów tej osoby, a komunikat brzmiał „Usunięte
     * z zeszytu." — w liczbie pojedynczej, o operacji na wszystkich. Razem
     * z wierszami ginęły notatki własne z `collection_items.note` i nie było
     * ich skąd odtworzyć.
     *
     * Kolejność jest taka:
     *  1. jest `collection_id` → wyjmujemy z TEGO zeszytu i tylko z tego;
     *  2. nie ma `collection_id`, a przepis leży w jednym zeszycie → nie ma
     *     czego ujawniać, bo zakres i tak jest jednoznaczny;
     *  3. nie ma `collection_id`, a zeszytów jest więcej → wyjmujemy ze
     *     wszystkich (tak działał ten ekran i nie zmieniamy tego pod ludźmi),
     *     ale komunikat MÓWI, ile ich było, i daje przycisk powrotu.
     *
     * Potwierdzenia PRZED akcją nie ma świadomie — to ten sam wybór co przy
     * wpisie: wyjęcie jest teraz odwracalne co do notatki, więc tańszy jest
     * przycisk powrotu PO akcji niż pytanie „czy na pewno" przed każdą.
     */
    public function removeRecipe(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();

        $zeszyt = $this->wybranyZeszytDoWyjecia($request);

        $zdjete = $this->save->remove($request->user(), $model, $zeszyt);

        if ($zdjete === []) {
            // Nie kłamiemy, że coś wyjęliśmy. Bez drogi powrotu — nie ma dokąd.
            return back()->with('status', 'Tego przepisu nie ma w żadnym z Twoich zeszytów.');
        }

        $this->zapamietajWyjecie($request, 'przepis', (string) $model->getKey(), $zdjete);

        return back()
            ->with('status', $this->komunikatPoWyjeciu('Przepis', $request->user(), $zdjete))
            ->with('status_powrot', [
                'akcja' => route('collections.save', $model->slug),
                'etykieta' => 'Przywróć do zeszytu',
            ]);
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

        // Powrót po wyjęciu — uzasadnienie przy `saveRecipe()`.
        if ($collection === null && $request->input('note') === null) {
            $powrot = $this->przywrocPoWyjeciu($request, 'wpis', (string) $post->getKey());

            if ($powrot !== null) {
                return back()->with('status', $powrot);
            }
        }

        try {
            $target = $this->savePost->handle($request->user(), $post, $collection);
        } catch (BladDlaCzlowieka $e) {
            // Stan zmienił się w trakcie żądania (#1022): treść ukryta,
            // blokada, zeszyt usunięty w drugiej karcie. Zdanie zamiast 500.
            return back()->withErrors(['collection_id' => $e->getMessage()]);
        }

        if ($request->boolean('open_collection')) {
            return redirect()->route('collections.show', $target)->with('status', "Zapisane w zeszycie „{$target->name}”.");
        }

        return back()->with('status', "Zapisane w zeszycie „{$target->name}”.");
    }

    /**
     * JEDNA REGUŁA WŁASNEGO ZESZYTU DLA ZAPISU I DLA WYJĘCIA (issue #775).
     *
     * `selectedCollection()` i `wybranyZeszytDoWyjecia()` różnią się tylko
     * zdaniami w błędach — reguła jest ta sama i ma być ta sama: format UUID
     * przed zapytaniem (`bail`) i własność przypięta do `owner_id`. Dwie
     * kopie tej listy rozjechałyby się przy pierwszej poprawce jednej z nich,
     * a wyjęcie z cudzego zeszytu jest dokładnie tą granicą, której pilnuje
     * AGENTS.md §7.
     *
     * Jest też powód mierzalny: `scripts/kontrole-negatywne-alfa08.py` mutuje
     * tę listę i wymaga, żeby stała w kodzie DOKŁADNIE RAZ. Przy dwóch kopiach
     * kontrola ujemna odmawia pracy, zanim cokolwiek zmutuje — czyli przestaje
     * cokolwiek dowodzić. Jedna kopia daje jej jedno miejsce, a mutacja osłabia
     * wtedy obie drogi naraz.
     *
     * @return array<string, list<mixed>>
     */
    private function regulyWlasnegoZeszytu(Request $request): array
    {
        return [
            'collection_id' => [
                'bail', 'nullable', 'uuid',
                Rule::exists('collections', 'id')->where('owner_id', $request->user()->getKey()),
            ],
        ];
    }

    /**
     * Nieistniejący i cudzy zeszyt dają ten sam komunikat (issue #473).
     * „bail” zatrzymuje walidację przed zapytaniem do kolumny UUID, gdy
     * wejście nie ma poprawnego formatu. Brak wyboru oznacza zeszyt domyślny.
     */
    private function selectedCollection(Request $request): ?Collection
    {
        $data = $request->validate($this->regulyWlasnegoZeszytu($request), [
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
        // ZAKRES: z tego zeszytu, gdy wiadomo z którego (issue #775).
        // Bez `collection_id` zostaje stare zachowanie — wszystkie zeszyty
        // tej osoby — ale komunikat niżej mówi wprost, ile ich było.
        $zeszyt = $this->wybranyZeszytDoWyjecia($request);

        $zdjete = $this->savePost->remove($request->user(), $post, $zeszyt);

        if ($zdjete === []) {
            return back()->with('status', 'Tego wpisu nie ma w żadnym z Twoich zeszytów.');
        }

        $this->zapamietajWyjecie($request, 'wpis', (string) $post->getKey(), $zdjete);

        // KOMUNIKAT MÓWI, CO SIĘ STAŁO, I DAJE DROGĘ POWROTU (audyt L1).
        //
        // „Usunięte z zeszytu." nie mówiło, CO zostało usunięte ani czy
        // zniknęło z jednego zeszytu, czy ze wszystkich. Teraz mówi jedno
        // i drugie — z nazwą zeszytu, gdy był jeden, i z liczbą, gdy było
        // ich więcej. Zamiast pytania „czy na pewno" PRZED akcją (wyjęcie
        // jest odwracalne co do notatki) idzie przycisk powrotu PO niej;
        // rysuje go `components/layout.blade.php` w tym samym obszarze
        // `aria-live`, co komunikat.
        return back()
            ->with('status', $this->komunikatPoWyjeciu('Wpis', $request->user(), $zdjete))
            ->with('status_powrot', [
                'akcja' => route('collections.save-post', $post),
                'etykieta' => 'Przywróć do zeszytu',
            ]);
    }

    /**
     * Zeszyt wskazany przy WYJMOWANIU — albo `null`, czyli „wszystkie moje".
     *
     * Osobno od `selectedCollection()`, bo zdania w błędach są inne: tam
     * mowa o zapisaniu, tu o wyjęciu. Reguła jest ta sama i celowo: cudzy
     * i nieistniejący zeszyt dają jeden komunikat (issue #473), a zakres
     * i tak przypina `owner_id`, więc identyfikator w adresie niczego nie
     * otwiera (AGENTS.md §7).
     */
    private function wybranyZeszytDoWyjecia(Request $request): ?Collection
    {
        $data = $request->validate($this->regulyWlasnegoZeszytu($request), [
            'collection_id.uuid' => 'Odśwież stronę i ponownie wskaż zeszyt, z którego wyjmujemy.',
            'collection_id.exists' => 'Odśwież stronę i ponownie wskaż zeszyt, z którego wyjmujemy.',
        ]);

        return isset($data['collection_id'])
            ? $request->user()->collections()->find($data['collection_id'])
            : null;
    }

    /**
     * Zdanie po wyjęciu — MÓWI ZAKRES, bo zakres jest tu całą sprawą.
     *
     * Jeden zeszyt → z nazwy, bo nazwa jest krótsza i pewniejsza niż liczba.
     * Więcej niż jeden → wprost „ze wszystkich Twoich zeszytów" z liczbą,
     * żeby nikt nie odkrył zakresu dopiero po fakcie, w innym zeszycie.
     *
     * „Nie usunęliśmy go z serwisu" zostaje w obu wariantach: to jedyne
     * zdanie, które rozróżnia wyjęcie z zeszytu od skasowania treści.
     *
     * @param  list<array{collection_id: string, note: ?string, created_at: ?string}>  $zdjete
     */
    private function komunikatPoWyjeciu(string $co, User $user, array $zdjete): string
    {
        if (count($zdjete) === 1) {
            $nazwa = $user->collections()->whereKey($zdjete[0]['collection_id'])->value('name');

            return $nazwa === null
                ? "{$co} wyjęty z zeszytu. Nie usunęliśmy go z serwisu — możesz go przywrócić."
                : "{$co} wyjęty z zeszytu „{$nazwa}”. Nie usunęliśmy go z serwisu — możesz go przywrócić.";
        }

        $ile = count($zdjete);

        return "{$co} wyjęty z zeszytu — zniknął ze wszystkich Twoich zeszytów, było ich {$ile}. "
            .'Nie usunęliśmy go z serwisu — możesz go przywrócić razem z notatkami.';
    }

    /**
     * Zapamiętanie wyjęcia na potrzeby drogi powrotu.
     *
     * NIE `flash()`, i to jest sedno. Flash żyje jedno żądanie, a droga
     * powrotu ma trzy: DELETE (tu), GET z przyciskiem, POST po kliknięciu.
     * Na flashu przycisk by się narysował i nie miał czego przywrócić.
     *
     * Jedno miejsce, nadpisywane przy każdym wyjęciu — bo i przycisk powrotu
     * jest jeden, ostatni. Notatki idą do sesji, a nie do adresu: to treść
     * pisana przez człowieka i nie ma czego szukać w logach serwera.
     *
     * @param  list<array{collection_id: string, note: ?string, created_at: ?string}>  $zdjete
     */
    private function zapamietajWyjecie(Request $request, string $typ, string $id, array $zdjete): void
    {
        $request->session()->put('zeszyt_wyjecie', [
            'typ' => $typ,
            'id' => $id,
            'pozycje' => $zdjete,
        ]);
    }

    /**
     * Powrót po wyjęciu — albo `null`, gdy nie ma czego przywracać.
     *
     * Zwraca gotowe zdanie do `status`, żeby wywołujący nie musiał drugi raz
     * liczyć wierszy.
     */
    private function przywrocPoWyjeciu(Request $request, string $typ, string $id): ?string
    {
        $wyjecie = $request->session()->get('zeszyt_wyjecie');

        if (! is_array($wyjecie)
            || ($wyjecie['typ'] ?? null) !== $typ
            || ($wyjecie['id'] ?? null) !== $id
            || ! is_array($wyjecie['pozycje'] ?? null)
            || $wyjecie['pozycje'] === []) {
            return null;
        }

        $user = $request->user();

        $wrocilo = $typ === 'przepis'
            ? $this->save->restore($user, Recipe::findOrFail($id), $wyjecie['pozycje'])
            : $this->savePost->restore($user, Post::findOrFail($id), $wyjecie['pozycje']);

        // Jednorazowa droga powrotu: drugie kliknięcie nie ma już nic do roboty.
        $request->session()->forget('zeszyt_wyjecie');

        if ($wrocilo === 0) {
            // Zeszyt zniknął albo rzecz wróciła tam inną drogą — nie udajemy,
            // że przywróciliśmy coś, czego nie ruszyliśmy.
            return null;
        }

        $co = $typ === 'przepis' ? 'Przepis' : 'Wpis';

        return $wrocilo === 1
            ? "{$co} wrócił do zeszytu razem z notatką."
            : "{$co} wrócił do wszystkich {$wrocilo} zeszytów razem z notatkami.";
    }

    public function destroy(Request $request, Collection $collection): RedirectResponse
    {
        $this->authorize('delete', $collection);

        $collection->delete();

        return redirect()->route('collections.index')->with('status', 'Zeszyt usunięty.');
    }
}
