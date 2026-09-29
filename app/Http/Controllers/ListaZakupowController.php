<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zakupy\ListaZakupow;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\Czas;
use App\Support\Komunikat;
use App\Support\Odmiana;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Lista zakupów (#27, etap 2, D-333). Prywatna: każda trasa działa wyłącznie
 * na liście zalogowanej osoby; zmiana i usunięcie pozycji po identyfikatorze
 * przechodzą przez `ShoppingListItemPolicy`, a „Dodaj składniki” — przez
 * `RecipePolicy::view` (UUID ani adres przepisu nie są autoryzacją).
 *
 * Cała lista działa zwykłymi formularzami, bez skryptu.
 */
class ListaZakupowController extends Controller
{
    public function index(Request $request, ListaZakupow $lista): View
    {
        /** @var User $user */
        $user = $request->user();

        $pozycje = $lista->pozycje($user);

        return view('pages.zakupy.index', [
            ...ListaZakupow::podziel($pozycje),
            'ile' => count($pozycje),
            'maksPozycji' => ListaZakupow::maksPozycji(),
            'maksZnakow' => ListaZakupow::maksZnakow(),
        ]);
    }

    public function store(Request $request, ListaZakupow $lista): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate([
            'text' => ['required', 'string'],
        ], [
            'text.required' => 'Wpisz, co trzeba kupić, np. „mleko” albo „2 cebule”.',
            'text.string' => 'Wpisz zwykły tekst, np. „mleko” albo „2 cebule”.',
        ]);

        $pozycja = $lista->dodajReczna($user, (string) $dane['text']);

        return redirect(route('shopping.index').'#dopisz')
            ->with(Komunikat::sukces('Dopisane do listy zakupów: '.$pozycja->text.'.'));
    }

    /**
     * Ekran pytania „dodać jeszcze raz?” — do niego trafia „Dodaj składniki”,
     * gdy składniki tego przepisu już są na liście. GET, więc odświeżenie
     * niczego nie dopisuje.
     */
    public function confirmRecipe(Request $request, Recipe $recipe): View|RedirectResponse
    {
        $this->authorize('view', $recipe);

        /** @var User $user */
        $user = $request->user();

        $pierwsze = ShoppingListItem::query()
            ->where('user_id', $user->getKey())
            ->where('recipe_id', $recipe->getKey())
            ->min('created_at');

        // Nikt nie ma o co pytać — wracamy do przepisu.
        if ($pierwsze === null) {
            return redirect()->route('recipes.show', $recipe->slug);
        }

        return view('pages.zakupy.potwierdz', [
            'recipe' => $recipe,
            'kiedy' => Czas::data(Carbon::parse($pierwsze), 'j F'),
            'ile' => ShoppingListItem::query()
                ->where('user_id', $user->getKey())
                ->where('recipe_id', $recipe->getKey())
                ->count(),
        ]);
    }

    public function storeRecipe(Request $request, Recipe $recipe, ListaZakupow $lista): RedirectResponse
    {
        // Bramka stoi TU, a nie tylko w akcji domenowej (skan tras widzi
        // `authorize()` w metodzie kontrolera); akcja pyta o to samo jeszcze raz.
        $this->authorize('view', $recipe);

        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate([
            'potwierdzam' => ['nullable', 'boolean'],
            'z_planera' => ['nullable', 'boolean'],
        ]);

        $wynik = $lista->dodajSkladniki($user, $recipe, (bool) ($dane['potwierdzam'] ?? false));

        if ($wynik['wynik'] === ListaZakupow::WYNIK_JUZ_JEST) {
            return redirect()->route('shopping.recipe.confirm', [
                'recipe' => $recipe->slug,
                ...($request->boolean('z_planera') ? ['z_planera' => 1] : []),
            ]);
        }

        if ($wynik['wynik'] === ListaZakupow::WYNIK_BRAK_SKLADNIKOW) {
            return $this->wroc($request, $recipe)->with(Komunikat::informacja(
                'Ten przepis nie ma jeszcze składników, więc nie ma czego dodać do listy zakupów.',
            ));
        }

        $ile = $wynik['dodano'];

        return redirect()->route('shopping.index')->with(Komunikat::sukces(
            'Dodane do listy zakupów: '.$ile.' '.Odmiana::rzeczownik($ile, 'składnik', 'składniki', 'składników')
            .' z przepisu „'.$recipe->title.'”.',
        ));
    }

    public function toggle(Request $request, ShoppingListItem $pozycja, ListaZakupow $lista): RedirectResponse
    {
        $this->authorize('update', $pozycja);

        $dane = $request->validate([
            'odhaczona' => ['required', 'boolean'],
        ], [
            'odhaczona.required' => 'Nie wiemy, co zrobić z tą pozycją. Wróć do listy zakupów i spróbuj jeszcze raz.',
            'odhaczona.boolean' => 'Nie wiemy, co zrobić z tą pozycją. Wróć do listy zakupów i spróbuj jeszcze raz.',
        ]);

        $lista->ustawOdhaczenie($pozycja, (bool) $dane['odhaczona']);

        return redirect(route('shopping.index').'#pozycja-'.$pozycja->getKey());
    }

    public function destroy(Request $request, ShoppingListItem $pozycja): RedirectResponse
    {
        $this->authorize('delete', $pozycja);

        $pozycja->delete();

        return redirect()->route('shopping.index')
            ->with(Komunikat::sukces('Usunięte z listy zakupów: '.$pozycja->text.'.'));
    }

    public function clear(Request $request, ListaZakupow $lista): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ile = $lista->wyczyscOdhaczone($user);

        return redirect()->route('shopping.index')->with($ile > 0
            ? Komunikat::sukces('Usunięte odhaczone pozycje: '.$ile.'.')
            : Komunikat::informacja('Nie ma odhaczonych pozycji do usunięcia.'));
    }

    /** Z planera wracamy do planera, ze strony przepisu — na nią. */
    private function wroc(Request $request, Recipe $recipe): RedirectResponse
    {
        return $request->boolean('z_planera')
            ? redirect()->route('planer.show')
            : redirect()->route('recipes.show', $recipe->slug);
    }
}
