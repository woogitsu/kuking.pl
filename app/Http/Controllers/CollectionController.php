<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Rules\CollectionNameNotTaken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Kolekcje — w interfejsie nazywane "Zeszytem", bo tak o tym myślą ludzie.
 */
class CollectionController extends Controller
{
    public function __construct(
        private readonly SaveRecipeToCollection $save,
        private readonly SavePostToCollection $savePost,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Domyślny zeszyt tworzymy dopiero przy pierwszym zapisie — nie
        // pokazujemy pustego folderu osobie, która nic jeszcze nie zapisała.
        return view('pages.collections.index', [
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
     * Pięć rzeczy odłożonych ostatnio — prawa szyna ekranu „Zeszyt" (issue #205).
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

    public function show(Request $request, Collection $collection): View
    {
        $this->authorize('view', $collection);

        return view('pages.collections.show', [
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
            'recipes' => $collection->recipes()
                ->widoczneDla($request->user())
                ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
                ->with(['author.profile', 'heroMedia'])
                ->paginate(12),
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
            'posts' => $collection->posts()
                ->widoczneDla($request->user())
                ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
                ->with(['author.profile.avatar', 'media'])
                ->withCount(['comments' => fn ($q) => $q->widoczneDla($request->user())])
                ->paginate(
                    (int) config('kuking.collections.saved_posts_page_size'),
                    ['*'],
                    'wpisy',
                ),
            // ILE POZYCJI SCHOWAŁ FILTR — I DLACZEGO TO W OGÓLE POKAZUJEMY.
            //
            // Wpis zapisany, gdy autor pokazywał go obserwującym, znika
            // z widoku po tym, jak przestaniesz go obserwować. Ciche zniknięcie
            // wygląda jak utrata danych („miałam to tu wczoraj"), a pokazanie
            // treści łamie widoczność. Zostaje trzecia droga: powiedzieć, ile
            // pozycji tu jest, nie mówiąc jakich.
            'niewidoczne' => max(
                0,
                $collection->posts()->count()
                    - $collection->posts()->widoczneDla($request->user())->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())->count(),
            ),
            // PRAWA SZYNA (issue #205): pozostałe zeszyty tej samej osoby.
            //
            // Zeszyt jest jednym z kilku pojemników i wejście do drugiego
            // wymagało do tej pory cofnięcia się na „Zeszyt". To jest jedyna
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
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — dwie opcje z ekranu, wprost.
            'visibility.in' => 'Zaznacz, kto ma widzieć ten zeszyt: wszyscy czy tylko Ty.',
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

        return redirect()->route('collections.show', $collection)->with('status', 'Zeszyt utworzony.');
    }

    public function saveRecipe(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $collection = null;

        if ($request->filled('collection_id')) {
            $collection = $request->user()->collections()->findOrFail($request->input('collection_id'));
        }

        $target = $this->save->handle($request->user(), $model, $collection);

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

        $collection = null;

        if ($request->filled('collection_id')) {
            $collection = $request->user()->collections()->findOrFail($request->input('collection_id'));
        }

        $target = $this->savePost->handle($request->user(), $post, $collection);

        return back()->with('status', "Zapisane w zeszycie „{$target->name}”.");
    }

    public function removePost(Request $request, Post $post): RedirectResponse
    {
        // Bez `authorize`: usuwamy z WŁASNEGO zeszytu i tylko z własnego
        // (`remove` chodzi po kolekcjach tej osoby). Wpis, którego już nie
        // wolno oglądać, tym bardziej musi dać się stamtąd wyjąć — inaczej
        // zostawałby w zeszycie na zawsze.
        $this->savePost->remove($request->user(), $post);

        return back()->with('status', 'Usunięte z zeszytu.');
    }

    public function destroy(Request $request, Collection $collection): RedirectResponse
    {
        $this->authorize('delete', $collection);

        $collection->delete();

        return redirect()->route('collections.index')->with('status', 'Zeszyt usunięty.');
    }
}
