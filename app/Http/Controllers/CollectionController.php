<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\RemoveUnavailableFromCollection;
use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\Actions\UstawSkrotDoZeszytu;
use App\Domain\Collections\CollectionSaveContext;
use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Collections\Odzyskiwanie\OdzyskajUsunietyZeszyt;
use App\Domain\Collections\Odzyskiwanie\UsunZeszyt;
use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Domain\Collections\Wspoldzielenie\ZaproszeniaDoZeszytow;
use App\Domain\Search\SearchQuery;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Requests\Collections\WyjecieZZeszytuRequest;
use App\Http\Requests\Collections\ZapisDoZeszytuRequest;
use App\Http\Requests\Collections\ZapisZeszytuRequest;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use App\Support\FrazaWyszukiwania;
use App\Support\Komunikat;
use App\Support\Odmiana;
use App\Support\PaginationLinks;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Kolekcje — w interfejsie nazywane "Zeszytem", bo tak o tym myślą ludzie.
 */
class CollectionController extends Controller
{
    /**
     * Ile zeszytów na porcję listy — osobno własnych i udostępnionych (#2321).
     *
     * Liczba zeszytów nie ma limitu, a lista `/zeszyt` materializowała
     * wszystkie naraz, z licznikami. Koszt strony rósł liniowo z biblioteką
     * i udostępnieniami. Porcja zamyka go z góry; dalsze zeszyty są pod
     * „Pokaż więcej zeszytów" (AGENTS.md §5: bez infinite scroll).
     */
    public const ZESZYTOW_NA_STRONE = 30;

    public function __construct(
        private readonly SaveRecipeToCollection $save,
        private readonly SavePostToCollection $savePost,
        private readonly WidocznaZawartoscZeszytu $zawartosc = new WidocznaZawartoscZeszytu,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Domyślny zeszyt tworzymy dopiero przy pierwszym zapisie — nie
        // pokazujemy pustego folderu osobie, która nic jeszcze nie zapisała.
        return view('pages.collections.index', [
            'saveContext' => app(CollectionSaveContext::class)->parameters($request->input('save_type'), $request->input('save_id')),
            'saveContent' => app(CollectionSaveContext::class)->content($request->input('save_type'), $request->input('save_id'), $request->user()),
            'collections' => $user->collections()
                ->withCount('members')
                ->withCount([
                    // LICZBY WIDOCZNE (`recipes_count`, `posts_count`) dolicza
                    // `policzWidoczne()` niżej — jednym zapytaniem na rodzaj
                    // dla wszystkich zeszytów naraz, nie podzapytaniem na
                    // każdy zeszyt (#2030). Reguła i powody są tam.
                    //
                    // CAŁKOWITA LICZBA ZACHOWANYCH ZAPISÓW — łącznie z tymi
                    // miękko usuniętymi (`withTrashed()`, tak jak w `show()`) —
                    // po to, żeby policzyć RÓŻNICĘ, nie żeby ją pokazać wprost.
                    'recipes as recipes_total_count' => fn ($q) => $q->withTrashed(),
                    'posts as posts_total_count' => fn ($q) => $q->withTrashed(),
                ])
                ->afterQuery(fn (EloquentCollection $zeszyty) => $this->policzWidoczne($zeszyty, $user))
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->orderBy('id')
                ->simplePaginate(self::ZESZYTOW_NA_STRONE, pageName: 'zeszyty')
                ->withQueryString(),
            // Prawa szyna (issue #205) — patrz `ostatnioZapisane()` niżej.
            'ostatnioZapisane' => $this->ostatnioZapisane($user),
            // „Przepisy udostępnione mi" (#2650) — odnośnik tylko wtedy, gdy
            // jest do czego prowadzić. Sama lista filtruje dalej przez Policy.
            'maUdostepnionePrzepisy' => RecipeShare::query()->where('recipient_id', $user->getKey())->exists(),
            // WSPÓLNE ZESZYTY (#1743, D-302) — OSOBNĄ LISTĄ, nie wmieszane
            // w własne: „mój" i „czyjś, do którego mnie wpuszczono" to dwie
            // różne rzeczy (kto może usunąć, kto zmienia nazwę). Ten sam
            // zakres co lista wyboru przy „Zapisuję", więc zeszyt właściciela
            // zbanowanego albo zablokowanego stąd znika.
            'udostepnione' => Collection::query()
                ->dostepneDoZapisuDla($user)
                ->where('collections.owner_id', '!=', $user->getKey())
                ->with('owner.profile')
                ->withCount([
                    'recipes as recipes_count' => fn ($q) => $q->widoczneDla($user)
                        ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor()),
                    'posts as posts_count' => fn ($q) => $q->widoczneWZeszycieDla($user),
                ])
                ->orderBy('name')
                ->orderBy('collections.id')
                ->simplePaginate(self::ZESZYTOW_NA_STRONE, pageName: 'udostepnione')
                ->withQueryString(),
            'zaproszenia' => app(ZaproszeniaDoZeszytow::class)->oczekujaceDla($user),
            // Skrót (#2542): tylko WŁASNY, istniejący zeszyt; brak skrótu = null.
            'skrot' => $user->ulubiony_zeszyt_id === null
                ? null
                : $user->ulubionyZeszyt()->first(['collections.id', 'collections.name']),
        ] + $this->szukajWZapisach($request));
    }

    /**
     * „Szukaj w moich zeszytach” — po TYTULE albo SKŁADNIKU zapisanego
     * przepisu (issue #779, #2068).
     *
     * Najprostsza wersja, na decyzję właściciela z 25.09.2026: jedno pole
     * na ekranie „Moje”, formularz GET działający bez JavaScriptu, jeden
     * wynik na przepis z listą zeszytów, w których leży — nie kilka kopii.
     *
     * BEZ NOWEGO SILNIKA. Porównanie idzie po tej samej kolumnie
     * `recipes.title_search` (`kuking_normalize(title)`: małe litery, bez
     * polskich znaków) i tą samą normalizacją frazy po stronie PHP co
     * `SearchQuery` — „zurek” znajdzie „Żurek babci”. `LIKE` z ucieczką
     * metaznaków, bo `%` i `_` z frazy mają być dosłownym tekstem (#753).
     *
     * SKŁADNIKI (#2068): ta sama fraza pasuje też do
     * `recipe_ingredients.ingredient_text_search` (indeks trigramowy
     * `recipe_ingredients_text_trgm_idx`, ta sama gałąź co w `SearchQuery`).
     * Składnik to `EXISTS` w `WHERE`, nie `JOIN`, więc przepis z pięcioma
     * pasującymi składnikami nadal jest JEDNYM wierszem, a liczba zapytań
     * nie zależy od liczby wyników. Widoczność się nie zmienia: składnik
     * jest dopasowywany dopiero wśród przepisów, które bramka niżej już
     * przepuściła, więc nie zdradza treści schowanych ani cudzych.
     * Kolumna `w_tytule` mówi karcie, czy trafienie jest w tytule — jeśli
     * nie, karta pisze „Pasuje przez składnik”, żeby nie wyglądało to
     * na pomyłkę (tytuł nie zawiera frazy).
     *
     * WIDOCZNOŚĆ JAK WEWNĄTRZ ZESZYTU: `widoczneDla()` (widoczność, status,
     * blokady w obie strony) i `dostepnyJakoAutor()`. Do zeszytu odkłada się
     * CUDZE przepisy; po zmianie ich widoczności wynik znika, a nie zdradza
     * tytułu. Zeszyty w wyniku i sam zakres szukania to wyłącznie zeszyty
     * zalogowanej osoby (`owner_id`).
     *
     * @return array{szukaj: string, wynikiSzukania: ?\Illuminate\Support\Collection<int, Recipe>, bladSzukania: ?string, wiecejWynikow: bool}
     */
    private function szukajWZapisach(Request $request): array
    {
        $fraza = trim((string) $request->query('szukaj', ''));
        $pusto = ['szukaj' => $fraza, 'wynikiSzukania' => null, 'bladSzukania' => null, 'wiecejWynikow' => false];

        if ($fraza === '') {
            return $pusto;
        }

        if (mb_strlen($fraza) > SearchQuery::MAX_PHRASE_LENGTH) {
            return ['bladSzukania' => 'Skróć tekst w polu „Szukaj w moich zeszytach” do '.SearchQuery::MAX_PHRASE_LENGTH.' znaków i spróbuj ponownie.'] + $pusto;
        }

        // Długość PO normalizacji (#1050): fraza z samych emoji znika
        // w `Str::ascii()` i dawałaby `LIKE '%%'`, czyli wszystko.
        if (mb_strlen(FrazaWyszukiwania::normalizuj($fraza)) < 2) {
            return ['bladSzukania' => 'Wpisz co najmniej dwie litery z tytułu przepisu albo ze składnika.'] + $pusto;
        }

        $user = $request->user();
        $limit = (int) config('kuking.zeszyt.szukaj_limit', 50);
        $wzorzec = '%'.FrazaWyszukiwania::doLike(FrazaWyszukiwania::normalizuj($fraza)).'%';

        $wyniki = Recipe::query()
            ->widoczneDla($user)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->whereHas('collections', fn ($zeszyt) => $zeszyt->where('collections.owner_id', $user->getKey()))
            ->select('recipes.*')
            ->selectRaw('(recipes.title_search LIKE ?) as w_tytule', [$wzorzec])
            ->where(fn ($q) => $q
                ->where('recipes.title_search', 'like', $wzorzec)
                ->orWhereExists(fn ($skladnik) => $skladnik->select(DB::raw('1'))
                    ->from('recipe_ingredients')
                    ->whereColumn('recipe_ingredients.recipe_id', 'recipes.id')
                    ->where('recipe_ingredients.ingredient_text_search', 'like', $wzorzec)))
            ->with(['collections' => fn ($zeszyt) => $zeszyt->where('collections.owner_id', $user->getKey())->orderBy('collections.name')])
            ->orderBy('recipes.title')
            ->orderBy('recipes.id')
            ->limit($limit + 1)
            ->get();

        return [
            'wynikiSzukania' => $wyniki->take($limit)->values(),
            'wiecejWynikow' => $wyniki->count() > $limit,
        ] + $pusto;
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
     * KOSZT NIE ROŚNIE Z ZAWARTOŚCIĄ ZESZYTU: na każdy rodzaj jedno zapytanie
     * o zapisy i jedno o widoczność kandydatów, plus dociągnięcie zdjęć
     * i autorów, niezależnie od tego, czy w zeszytach leży pięć rzeczy, czy
     * pięćset (`SzynaBezWachlarzaZapytanTest`).
     *
     * I NIE ROŚNIE Z HISTORIĄ W ŚRODKU TYCH ZAPYTAŃ (#2030). Wcześniej każde
     * z nich liczyło `max(collection_items.created_at)` i pełną regułę
     * widoczności dla KAŻDEJ rzeczy kiedykolwiek odłożonej, sortowało wszystko
     * i dopiero wtedy brało pięć. Teraz `ostatnioOdlozone()` idzie po zapisach
     * od najnowszego i sprawdza widoczność tylko małej partii kandydatów —
     * wynik i kolejność są te same (dowód: `SzynaOstatnioZapisanychKosztTest`,
     * pomiar: docs/infra/ZESZYTY_KOSZT_2030.md).
     *
     * @return \Illuminate\Support\Collection<int, covariant array{href: string, nazwa: string, podpis: string, media: \App\Models\Media|null, zapisano_at: mixed}>
     */
    private function ostatnioZapisane(User $user): \Illuminate\Support\Collection
    {
        $ile = 5;

        $przepisy = $this->ostatnioOdlozone($user, 'recipe_id', $ile, ['heroMedia', 'author.profile'], fn (array $id) => Recipe::query()
            ->widoczneDla($user)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->whereKey($id)
            ->get())
            ->map(fn (Recipe $przepis) => [
                'href' => $przepis->url(),
                'nazwa' => $przepis->title,
                'podpis' => 'Przepis · '.$przepis->author->displayName(),
                'media' => $przepis->heroMedia,
                'zapisano_at' => $przepis->getAttribute('zapisano_at'),
            ]);

        // Wpisy przez pełną regułę wnętrza zeszytu (#1319): zapowiedź
        // schowanego przepisu nie może zająć miejsca w pięciu pozycjach
        // ani dać odnośnika, który Policy kończy odmową.
        $wpisy = $this->ostatnioOdlozone($user, 'post_id', $ile, ['media', 'author.profile'], fn (array $id) => Post::query()
            ->widoczneWZeszycieDla($user)
            ->whereKey($id)
            ->get())
            ->map(fn (Post $wpis) => $wpis->kind === Post::KIND_QUESTION ? [
                'href' => $wpis->url(),
                // Pytanie ma własny tytuł i często nic poza nim — to on
                // jest nazwą, nie opis i nie „Zdjęcie bez opisu" (#869).
                'nazwa' => (string) $wpis->title,
                'podpis' => 'Pytanie · '.$wpis->author->displayName(),
                'media' => $wpis->media->first(),
                'zapisano_at' => $wpis->getAttribute('zapisano_at'),
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
                'zapisano_at' => $wpis->getAttribute('zapisano_at'),
            ]);

        // Sortowanie po ZNACZNIKU CZASU, nie po tekście z bazy. `timestamptz`
        // wraca z przesunięciem strefy (`+01`/`+02`), a porównanie tekstowe
        // przestawiłoby dwie rzeczy odłożone po obu stronach zmiany czasu.
        return $przepisy->concat($wpisy)
            ->sortByDesc(fn (array $pozycja) => Carbon::parse($pozycja['zapisano_at'])->getTimestamp())
            ->take($ile)
            ->values();
    }

    /**
     * Do `$ile` WIDOCZNYCH rzeczy jednego rodzaju, od odłożonej najpóźniej
     * (#2030) — ta sama lista i kolejność, którą dawało
     * `ORDER BY max(collection_items.created_at) DESC, id DESC LIMIT $ile`.
     *
     * DLACZEGO TO JEST TA SAMA KOLEJNOŚĆ. Zapisy konta czytamy od najnowszego
     * po (`created_at` DESC, id DESC). Pierwsze spotkanie danej rzeczy to jej
     * NAJPÓŹNIEJSZY zapis, czyli dokładnie `max()` po wszystkich zeszytach;
     * dalsze spotkania tej samej rzeczy są starsze i je pomijamy. Kolejność
     * pierwszych spotkań to więc (max DESC, id DESC) — bez agregacji całej
     * historii.
     *
     * WIDOCZNOŚĆ SPRAWDZA `$widoczne` — ta sama reguła co dotąd (blokady,
     * widoczność, status autora, bramka przepisu wpisu), tylko na partii
     * kandydatów zamiast na wszystkim, co kiedykolwiek odłożono. Rzecz
     * niewidoczna odpada i czytamy dalej, więc pięć schowanych zapisów na
     * górze nie zabiera miejsca ani nie skraca listy.
     *
     * PARTIE ROSNĄ (20, 80, 320, 1000…), żeby konto, którego najnowsze zapisy
     * w większości zniknęły z widoku, nie płaciło setek zapytań. Zwykle
     * wystarcza pierwsza partia — liczba zapytań się nie zmienia
     * (`SzynaBezWachlarzaZapytanTest`).
     *
     * Zakres zeszytów przez `whereIn` z podzapytaniem, nie `join`: tabela
     * `collections` ma kolumnę `visibility` (patrz wyżej). Relacje
     * (`$relacje`) dociągamy dopiero do wybranych `$ile` rzeczy, nie do
     * całej partii.
     *
     * @template TModel of Recipe|Post
     *
     * @param  'recipe_id'|'post_id'  $kolumna
     * @param  list<string>  $relacje
     * @param  \Closure(list<string>): EloquentCollection<int, TModel>  $widoczne
     * @return EloquentCollection<int, TModel>
     */
    private function ostatnioOdlozone(User $user, string $kolumna, int $ile, array $relacje, \Closure $widoczne): EloquentCollection
    {
        /** @var EloquentCollection<int, TModel> $znalezione */
        $znalezione = new EloquentCollection;
        $obejrzane = [];
        $kursor = null;
        $partia = 20;

        do {
            $zapisy = DB::table('collection_items')
                ->whereIn('collection_items.collection_id', Collection::query()->where('owner_id', $user->getKey())->select('id'))
                ->whereNotNull('collection_items.'.$kolumna)
                ->when($kursor !== null, fn ($q) => $q->whereRaw(
                    '(collection_items.created_at, collection_items.'.$kolumna.') < (?::timestamptz, ?::uuid)',
                    $kursor,
                ))
                ->orderByDesc('collection_items.created_at')
                ->orderByDesc('collection_items.'.$kolumna)
                // Jeden wiersz ponad partię mówi tylko, czy jest dalszy ciąg —
                // bez niego pełna partia kosztowałaby puste zapytanie.
                ->limit($partia + 1)
                ->get(['collection_items.'.$kolumna.' as id', 'collection_items.created_at']);
            $dalej = $zapisy->count() > $partia;
            $zapisy = $zapisy->take($partia);

            /** @var array<string, string> $kandydaci id => najpóźniejszy zapis */
            $kandydaci = [];

            foreach ($zapisy as $zapis) {
                if (! isset($obejrzane[$zapis->id])) {
                    $obejrzane[$zapis->id] = true;
                    $kandydaci[$zapis->id] = $zapis->created_at;
                }
            }

            if ($kandydaci !== []) {
                $modele = $widoczne(array_map('strval', array_keys($kandydaci)))
                    ->keyBy(fn ($model) => (string) $model->getKey());

                foreach ($kandydaci as $id => $zapisano) {
                    if (isset($modele[$id])) {
                        $znalezione->push($modele[$id]->setAttribute('zapisano_at', $zapisano));

                        if ($znalezione->count() === $ile) {
                            return $znalezione->load($relacje);
                        }
                    }
                }
            }

            $ostatni = $zapisy->last();
            $kursor = $ostatni === null ? null : [$ostatni->created_at, $ostatni->id];
            $partia = min($partia * 4, 1000);
        } while ($dalej);

        return $znalezione->load($relacje);
    }

    /**
     * Liczby na kartach zeszytów: ile WIDOCZNYCH przepisów i wpisów leży
     * w każdym (issue #774, #1319, #2030).
     *
     * LICZBA WIDOCZNA — DOKŁADNIE TA SAMA, KTÓRĄ CZŁOWIEK ZOBACZY PO WEJŚCIU
     * (issue #774). PRZED TAMTĄ ZMIANĄ karta liczyła bez żadnego filtra
     * widoczności ani statusu autora, a `show()` filtrował OBOMA
     * (`widoczneDla()` i `dostepnyJakoAutor()`, audyt W5-08). Dwa ekrany tego
     * samego zeszytu liczyły więc dwie różne rzeczy — i to NIE PO RÓWNO:
     * prywatna treść była wliczona w obie liczby, a treść miękko usunięta
     * (SoftDeletes dodaje globalny zakres) wypadała tylko z karty, nie
     * z wnętrza zeszytu. Jedna reguła zamiast dwóch przypadkowo różnych:
     * karta pokazuje WIDOCZNE, wnętrze dokłada „N nie jest dostępnych" —
     * i te dwie liczby razem dają całość. Wpisy liczy ta sama reguła co
     * wnętrze zeszytu, łącznie z bramką przepisu i statusem jego autora (#1319).
     *
     * JEDNO ZAPYTANIE NA RODZAJ, NIE PODZAPYTANIE NA ZESZYT (#2030). Do tej
     * zmiany te dwie liczby były skorelowanymi podzapytaniami `withCount()`
     * w zapytaniu o listę. Pracy było tyle samo, ale planer mnożył koszt
     * jednego podzapytania przez liczbę zeszytów. Przy 200 zeszytach
     * szacunek przekraczał `jit_inline_above_cost` i PostgreSQL kompilował
     * zapytanie przez JIT z inliningiem i optymalizacją — ok. 0,6–0,7 s
     * na samą kompilację przy ok. 0,1 s właściwej pracy
     * (docs/infra/ZESZYTY_KOSZT_2030.md). Zgrupowane zliczenie po
     * `collection_items` daje te same liczby i jeden, mniejszy szacunek.
     *
     * `join` z `collection_items`, NIE z `collections`: tabela zeszytów ma
     * kolumnę `visibility`, a zakresy widoczności pytają o nią bez nazwy
     * tabeli (patrz `ostatnioZapisane()`). Zliczamy wyłącznie zeszyty
     * bieżącej porcji listy (#2321) — nie całą bibliotekę właściciela.
     *
     * @param  EloquentCollection<int, Collection>  $zeszyty
     */
    private function policzWidoczne(EloquentCollection $zeszyty, User $user): void
    {
        if ($zeszyty->isEmpty()) {
            return;
        }

        $wlasne = $zeszyty->modelKeys();

        $przepisy = Recipe::query()
            ->widoczneDla($user)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->join('collection_items', 'collection_items.recipe_id', '=', 'recipes.id')
            ->whereIn('collection_items.collection_id', $wlasne)
            ->groupBy('collection_items.collection_id')
            ->toBase()
            ->selectRaw('collection_items.collection_id, count(*) as ile')
            ->pluck('ile', 'collection_id');

        $wpisy = Post::query()
            ->widoczneWZeszycieDla($user)
            ->join('collection_items', 'collection_items.post_id', '=', 'posts.id')
            ->whereIn('collection_items.collection_id', $wlasne)
            ->groupBy('collection_items.collection_id')
            ->toBase()
            ->selectRaw('collection_items.collection_id, count(*) as ile')
            ->pluck('ile', 'collection_id');

        foreach ($zeszyty as $zeszyt) {
            $zeszyt->setAttribute('recipes_count', (int) ($przepisy[$zeszyt->getKey()] ?? 0));
            $zeszyt->setAttribute('posts_count', (int) ($wpisy[$zeszyt->getKey()] ?? 0));
        }
    }

    public function show(Request $request, Collection $collection): View|RedirectResponse
    {
        $this->authorize('view', $collection);

        // Filtry widoczności żyją w `WidocznaZawartoscZeszytu` — te same
        // liczą niżej niedostępne zapisy i wyznaczają, co wyjmuje
        // porządkowanie (#773).
        $recipes = $this->zawartosc->przepisy($collection, $request->user())
            ->with(Recipe::RELACJE_KARTY)
            ->paginate(KolejnoscPrzepisow::NA_STRONE);

        // RĘCZNA KOLEJNOŚĆ (#2544): przyciski „Wyżej"/„Niżej" widzi wyłącznie
        // właściciel prywatnego zeszytu bez zaproszonych osób (`reorder`), i to
        // dopiero po świadomym wejściu w tryb układania (`?uloz=1`). Zwykłe
        // przeglądanie wygląda jak dotąd. Odcisk układu to dowód świeżości
        // karty: stara karta nie przesunie niczego, gdy kolejność już się zmieniła.
        $mozeUkladac = $request->user() !== null && Gate::forUser($request->user())->allows('reorder', $collection);
        $ulozenie = [
            'mozeUkladac' => $mozeUkladac,
            'trybUkladania' => $mozeUkladac && $request->boolean('uloz'),
            'jestUlozony' => $mozeUkladac && KolejnoscPrzepisow::jestUlozony($collection),
        ];
        $ulozenie['odciskUkladu'] = $ulozenie['trybUkladania']
            ? KolejnoscPrzepisow::odcisk(KolejnoscPrzepisow::uklad($collection))
            : null;
        if ($ulozenie['trybUkladania']) {
            $recipes->appends('uloz', 1);
        }

        // Granice widoczności: `WidocznaZawartoscZeszytu` (#773, ta sama reguła
        // liczy niżej niedostępne zapisy). Relacje karty, licznik komentarzy
        // i zapisów — jeden kontrakt `Post::scopeDlaKarty()` (#1037).
        $posts = $this->zawartosc->wpisy($collection, $request->user())
            ->dlaKarty($request->user())
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

            return redirect()->route('collections.show', ['collection' => $collection, ...$pages, ...($request->boolean('uloz') ? ['uloz' => 1] : [])]);
        }

        // Wpis z własną treścią zostaje w zeszycie także wtedy, gdy jego
        // przepis stał się niedostępny (#1377) — ale karta nie może wtedy
        // pokazać tytułu, zdjęcia ani odnośnika tego przepisu (#1036).
        Post::ukryjNiedostepnePrzepisy($posts->items(), $request->user());

        // Każdy przycisk przesuwa swoją listę i zachowuje pozycję drugiej.
        PaginationLinks::preserveOtherPage($recipes, $posts);
        PaginationLinks::preserveOtherPage($posts, $recipes);

        $wspoldzielenie = $this->wspoldzielenie($request->user(), $collection, [...$recipes->items(), ...$posts->items()]);
        $collection->wyjmowanieDozwolone = $wspoldzielenie['jestWspolpracownikiem'];

        $niewidoczne = $request->user()?->getKey() === $collection->owner_id
            ? max(0, $collection->recipes()->withTrashed()->count() - $recipes->total())
                + max(0, $collection->posts()->withTrashed()->count() - $posts->total())
            : 0;

        return view('pages.collections.show', $ulozenie + [
            'saveContext' => $request->user()?->getKey() === $collection->owner_id ? app(CollectionSaveContext::class)->parameters($request->input('save_type'), $request->input('save_id')) : [],
            'saveContent' => $request->user()?->getKey() === $collection->owner_id ? app(CollectionSaveContext::class)->content($request->input('save_type'), $request->input('save_id'), $request->user()) : null,
            'collection' => $collection,
            ...$wspoldzielenie,
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
            //
            // TYLKO WŁAŚCICIELOWI (#1297). Liczba ukrytych zapisów to metadana
            // o cudzej, prywatnej aktywności: gość publicznego zeszytu mógłby
            // z wizyty na wizytę śledzić, ile prywatnych rzeczy właściciel
            // odkłada. Obcy widzi wyłącznie to, co może otworzyć — a gdy nie
            // może nic, ten sam pusty stan co w naprawdę pustym zeszycie,
            // żeby sam wygląd strony nie potwierdzał istnienia ukrytych zapisów.
            'niewidoczne' => $niewidoczne,
            // Odcisk dla przycisku „Wyjmij niedostępne zapisy" (#773) — tylko
            // właścicielowi i tylko wtedy, gdy jest co wyjmować.
            'odciskNiedostepnych' => $niewidoczne > 0 ? $this->odciskNiedostepnych($request, $collection) : null,
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

    /**
     * Wspólny zeszyt na ekranie zeszytu (#1743, D-302): kto ma dostęp, kto co
     * dodał, czy notatki są wspólne.
     *
     * KTO DODAŁ — TYLKO DLA OSÓB Z DOSTĘPEM. Obcy oglądający publiczny
     * zeszyt nie dowiaduje się, że ktoś poza właścicielem w nim zapisuje, ani
     * kto. Osoba zablokowana przez oglądającego (albo blokująca go) jest
     * podpisana „inna osoba z dostępem" — blokada działa też tutaj.
     *
     * Jedno zapytanie o autorów na stronę, nie jedno na pozycję.
     *
     * @param  list<Recipe|Post>  $pozycje
     * @return array<string, mixed>
     */
    private function wspoldzielenie(?User $user, Collection $collection, array $pozycje): array
    {
        $jestWlascicielem = $user !== null && $user->getKey() === $collection->owner_id;
        $jestWspolpracownikiem = $user !== null && ! $jestWlascicielem
            && Gate::forUser($user)->allows('removeItem', $collection);
        $dostep = $jestWlascicielem || $jestWspolpracownikiem;

        $czlonkowie = $dostep ? $collection->members()->with('profile')->get() : collect();

        // Ta sama lista służy „Podziel się" (#2000) do pytania „czy zeszyt jest
        // wspólny" — bez tego przycisk pytałby bazę o to samo drugi raz.
        if ($dostep) {
            $collection->setRelation('members', $czlonkowie);
        }

        $idAutorow = collect($pozycje)
            ->map(fn ($p) => $p->pivot?->added_by_id)
            ->unique()
            ->values();

        $wspolny = $dostep && ($czlonkowie->isNotEmpty()
            || $idAutorow->contains(fn ($id) => $id !== null && $id !== $collection->owner_id));

        $podpisy = [];

        if ($wspolny) {
            $autorzy = User::query()->with('profile')->whereKey($idAutorow->filter()->all())->get()->keyBy(fn (User $u) => (string) $u->getKey());

            foreach ($idAutorow as $id) {
                $podpisy[(string) $id] = match (true) {
                    $id === null => 'osoba, która usunęła konto',
                    $id === $user?->getKey() => 'Ty',
                    ! isset($autorzy[(string) $id]) || $user?->hasBlockRelationWith($autorzy[(string) $id]) => 'inna osoba z dostępem',
                    default => $autorzy[(string) $id]->displayName(),
                };
            }
        }

        return [
            'dostepDoNotatek' => $dostep,
            'wspolny' => $wspolny,
            'jestWspolpracownikiem' => $jestWspolpracownikiem,
            'czlonkowie' => $czlonkowie,
            'podpisyDodania' => $podpisy,
        ];
    }

    public function store(ZapisZeszytuRequest $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validated();

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

        return redirect()->route('collections.show', ['collection' => $collection, ...app(CollectionSaveContext::class)->parameters($request->input('save_type'), $request->input('save_id'))])->with(Komunikat::sukces('Zeszyt utworzony.'));
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
     * Te same reguły co `store()` (`ZapisZeszytuRequest`), z jednym
     * wyjątkiem: nazwa własnego, niezmienionego zeszytu nie jest dla niego
     * „zajęta" (`CollectionNameNotTaken::$ignoreCollectionId`).
     *
     * KOMUNIKAT NAZYWA ZAKRES ZMIANY WIDOCZNOŚCI, NIE TYLKO FAKT ZAPISU.
     * „Zeszyt zaktualizowany" nie powiedziałoby człowiekowi, czy publiczny
     * adres, który ktoś mógł już mieć zapisany, dalej działa. Zmiana
     * widoczności jest tu decyzją semantyczną (jak w D-088), więc zasługuje
     * na własne zdanie, nie ogólnikowe potwierdzenie zapisu.
     */
    public function update(ZapisZeszytuRequest $request, Collection $collection): RedirectResponse
    {
        // Policy `update` sprawdza `ZapisZeszytuRequest::authorize()` — przed
        // walidacją; jawne `authorize()` jest idempotentne i zostaje, żeby
        // bramkę widać było w kontrolerze (`AutoryzacjaTrasZWiazaniemModeluTest`).
        $this->authorize('update', $collection);

        $bylaPubliczna = $collection->isPublic();

        $data = $request->validated();

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
            $bylaPubliczna && ! $jestPubliczna => 'Zeszyt jest teraz prywatny. Publiczny dostęp został wyłączony.'.($collection->is_default ? '' : ' Zaproszone osoby zachowują swój dostęp. Oczekujące zaproszenia nie zostały odwołane.'),
            ! $bylaPubliczna && $jestPubliczna => 'Zeszyt jest teraz widoczny dla wszystkich.',
            default => 'Zeszyt zaktualizowany.',
        };

        return redirect()->route('collections.show', $collection)->with(Komunikat::sukces($status));
    }

    public function saveRecipe(ZapisDoZeszytuRequest $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $collection = $request->zeszytDoZapisu();

        // Powrót po wyjęciu albo zwykły zapis — kolejność i uzasadnienie
        // w `SaveRecipeToCollection::zapiszAlboPrzywroc()` (#775, #970).
        try {
            $wynik = $this->save->zapiszAlboPrzywroc(
                $request->user(),
                $model,
                $collection,
                $request->input('note') !== null,
                $request->session()->get('zeszyt_wyjecie'),
                fn () => $request->session()->forget('zeszyt_wyjecie'),
            );
        } catch (BladDlaCzlowieka $e) {
            // Stan zmienił się w trakcie żądania (#1022): treść ukryta,
            // blokada, zeszyt usunięty w drugiej karcie. Zdanie zamiast 500.
            return back()->withErrors(['collection_id' => $e->getMessage()]);
        }

        if ($wynik->zdaniePowrotu !== null) {
            return back()->with(Komunikat::sukces($wynik->zdaniePowrotu));
        }

        $target = $wynik->zeszyt;

        if ($request->boolean('open_collection')) {
            return redirect()->route('collections.show', $target)->with(Komunikat::sukces("Zapisane w zeszycie „{$target->name}”."));
        }

        return back()->with(Komunikat::sukces("Zapisane w zeszycie „{$target->name}”."));
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
     *  3. nie ma `collection_id`, a zeszytów jest więcej → NAJPIERW strona
     *     potwierdzenia (decyzja właściciela z 25.09.2026). Mówi, z ilu
     *     zeszytów zejdzie przepis i ile notatek zniknie, i pozwala zamiast
     *     tego wyjąć go z jednego wybranego zeszytu. Ze wszystkich wyjmujemy
     *     dopiero z `potwierdzam_wszystkie=1`; przycisk „Przywróć do
     *     zeszytu” po akcji zostaje.
     *
     * Potwierdzenie to ZWYKŁA ODPOWIEDŹ NA TO SAMO DELETE, nie osobna trasa
     * ani okienko skryptu: działa bez JavaScriptu i nie da się go obejść
     * starym formularzem z innej karty — reguła stoi po stronie serwera,
     * więc chroni też stronę narysowaną, zanim przepis trafił do drugiego
     * zeszytu.
     */
    public function removeRecipe(WyjecieZZeszytuRequest $request, string $recipe): RedirectResponse|View
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();

        $zeszyt = $request->zeszytDoWyjecia();

        if ($zeszyt === null && ! $request->boolean('potwierdzam_wszystkie')) {
            $zeszytyZPrzepisem = $request->user()->collections()
                ->whereHas('recipes', fn ($q) => $q->whereKey($model->getKey()))
                ->with(['recipes' => fn ($q) => $q->whereKey($model->getKey())])
                ->orderBy('name')
                ->get();

            if ($zeszytyZPrzepisem->count() > 1) {
                $widoczny = Gate::forUser($request->user())->allows('view', $model);

                return view('pages.collections.potwierdz-wyjecie-ze-wszystkich', [
                    'recipe' => $model,
                    // Tytuł i powrót do przepisu tylko wtedy, gdy wolno go
                    // oglądać — przepis mógł w międzyczasie stać się prywatny.
                    'widoczny' => $widoczny,
                    'zeszyty' => $zeszytyZPrzepisem,
                    'notatek' => $zeszytyZPrzepisem
                        ->filter(fn (Collection $c): bool => trim((string) $c->recipes->first()?->pivot->note) !== '')
                        ->count(),
                    'powrot' => $widoczny ? route('recipes.show', $model->slug) : route('collections.index'),
                ]);
            }
        }

        $wynik = $this->save->wyjmij($request->user(), $model, $zeszyt);

        if ($wynik->wyjecie === null) {
            // Nie kłamiemy, że coś wyjęliśmy. Bez drogi powrotu — nie ma dokąd.
            return back()->with(Komunikat::informacja('Tego przepisu nie ma w żadnym z Twoich zeszytów.'));
        }

        // Jedno miejsce w sesji, nadpisywane przy każdym wyjęciu (NIE `flash()`:
        // droga powrotu ma trzy żądania, nie jedno — #775). Kształt zapisu
        // buduje domena; sesję zamyka kontroler.
        $request->session()->put('zeszyt_wyjecie', $wynik->wyjecie);

        return back()
            ->with(Komunikat::sukces($wynik->komunikat))
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
     *
     * `save`, a nie samo `view`: podgląd ukrytego wpisu dla moderatora
     * (#1018) przechodzi `view`, ale jest tylko do odczytu.
     */
    public function savePost(ZapisDoZeszytuRequest $request, Post $post): RedirectResponse
    {
        $this->authorize('save', $post);

        $collection = $request->zeszytDoZapisu();

        // Powrót po wyjęciu albo zwykły zapis — kolejność i uzasadnienie
        // w `SavePostToCollection::zapiszAlboPrzywroc()` (#775, #970).
        try {
            $wynik = $this->savePost->zapiszAlboPrzywroc(
                $request->user(),
                $post,
                $collection,
                $request->input('note') !== null,
                $request->session()->get('zeszyt_wyjecie'),
                fn () => $request->session()->forget('zeszyt_wyjecie'),
            );
        } catch (BladDlaCzlowieka $e) {
            // Stan zmienił się w trakcie żądania (#1022): treść ukryta,
            // blokada, zeszyt usunięty w drugiej karcie. Zdanie zamiast 500.
            return back()->withErrors(['collection_id' => $e->getMessage()]);
        }

        if ($wynik->zdaniePowrotu !== null) {
            return back()->with(Komunikat::sukces($wynik->zdaniePowrotu));
        }

        $target = $wynik->zeszyt;

        if ($request->boolean('open_collection')) {
            return redirect()->route('collections.show', $target)->with(Komunikat::sukces("Zapisane w zeszycie „{$target->name}”."));
        }

        return back()->with(Komunikat::sukces("Zapisane w zeszycie „{$target->name}”."));
    }

    public function removePost(WyjecieZZeszytuRequest $request, Post $post): RedirectResponse
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
        $zeszyt = $request->zeszytDoWyjecia();

        $wynik = $this->savePost->wyjmij($request->user(), $post, $zeszyt);

        if ($wynik->wyjecie === null) {
            return back()->with(Komunikat::informacja('Tego wpisu nie ma w żadnym z Twoich zeszytów.'));
        }

        // Sesja jak przy przepisie (`removeRecipe()`).
        $request->session()->put('zeszyt_wyjecie', $wynik->wyjecie);

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
            ->with(Komunikat::sukces($wynik->komunikat))
            ->with('status_powrot', [
                'akcja' => route('collections.save-post', $post),
                'etykieta' => 'Przywróć do zeszytu',
            ]);
    }

    private function odciskNiedostepnych(Request $request, Collection $collection): ?string
    {
        // Ta sama bramka co przy wykonaniu (`update`): zawieszone konto nie
        // porządkuje zeszytu publicznego, więc nie dostaje martwego przycisku.
        if (! $request->user()?->can('update', $collection)) {
            return null;
        }

        $niedostepne = $this->zawartosc->niedostepne($collection, $request->user());

        if ($niedostepne['przepisy'] === [] && $niedostepne['wpisy'] === []) {
            return null;
        }

        return $this->zawartosc->odcisk($collection, $niedostepne);
    }

    /**
     * Wyjęcie niedostępnych zapisów z tego jednego zeszytu (#773).
     */
    public function removeUnavailable(Request $request, Collection $collection, RemoveUnavailableFromCollection $action): RedirectResponse
    {
        $this->authorize('update', $collection);

        $odcisk = $request->input('zakres');

        try {
            $ile = $action->handle($request->user(), $collection, is_string($odcisk) ? $odcisk : '');
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('collections.show', $collection)->withErrors(['zakres' => $e->getMessage()]);
        }

        return redirect()->route('collections.show', $collection)->with($ile === 0
            ? Komunikat::informacja('W tym zeszycie nie ma już niedostępnych zapisów. Niczego nie wyjęliśmy.')
            : Komunikat::sukces('Wyjęliśmy z tego zeszytu '.$ile.' '
                .Odmiana::rzeczownik($ile, 'niedostępny zapis', 'niedostępne zapisy', 'niedostępnych zapisów')
                .'. Reszta zeszytu została bez zmian.'));
    }

    public function setShortcut(Request $request, Collection $collection, UstawSkrotDoZeszytu $action): RedirectResponse
    {
        $this->authorize('setShortcut', $collection);

        try {
            $action->ustaw($request->user(), $collection);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('collections.index')->with(Komunikat::blad($e->getMessage()));
        }

        return redirect()->route('collections.show', $collection)
            ->with(Komunikat::sukces('Gotowe. Zeszyt „'.$collection->name.'” jest teraz skrótem na górze ekranu „Moje”.'));
    }

    public function clearShortcut(Request $request, Collection $collection, UstawSkrotDoZeszytu $action): RedirectResponse
    {
        $this->authorize('setShortcut', $collection);

        $action->usun($request->user(), $collection);

        return redirect()->route('collections.show', $collection)
            ->with(Komunikat::sukces('Skrót usunięty. Zeszyt i jego zapisy zostały bez zmian.'));
    }

    public function destroy(Request $request, Collection $collection, UsunZeszyt $usun): RedirectResponse
    {
        $this->authorize('delete', $collection);

        // Prywatny, niewspółdzielony zeszyt zostawia kopię odzyskania (#2567).
        // Komunikat mówi tylko to, co prawda: czy i do kiedy zeszyt da się odzyskać.
        $wynik = $usun->handle($request->user(), $collection);

        if ($wynik->juzUsuniety) {
            return redirect()->route('collections.index')->with(Komunikat::informacja('Ten zeszyt został już usunięty.'));
        }

        if (! $wynik->mozeWrocic) {
            return redirect()->route('collections.index')->with(Komunikat::sukces(
                'Zeszyt „'.$wynik->nazwa.'” usunięty. '.$wynik->powodBrakuOdzyskania,
            ));
        }

        $dni = OdzyskajUsunietyZeszyt::dniOkna();

        return redirect()->route('collections.index')->with(Komunikat::sukces(
            'Zeszyt „'.$wynik->nazwa.'” usunięty. Jeśli to pomyłka, przez '.$dni.' '.Odmiana::rzeczownik($dni, 'dzień', 'dni', 'dni')
            .' możesz go odzyskać w „Zeszyt”, w „Usunięte zeszyty”.',
        ));
    }
}
