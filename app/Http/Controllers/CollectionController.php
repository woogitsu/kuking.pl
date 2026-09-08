<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Rules\CollectionNameNotTaken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
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
