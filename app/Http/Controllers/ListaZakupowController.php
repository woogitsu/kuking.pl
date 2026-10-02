<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zakupy\ListaZakupow;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListUndo;
use App\Models\User;
use App\Support\Czas;
use App\Support\Komunikat;
use App\Support\Odmiana;
use App\Support\StaryAdresPrzepisu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
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
        $cofniecie = $lista->oczekujaceCofniecie($user);

        return view('pages.zakupy.index', [
            ...ListaZakupow::podziel($pozycje),
            'cofniecie' => $cofniecie,
            'cofnijDo' => $cofniecie !== null ? Czas::lokalnie($cofniecie->expires_at)->format('H:i') : null,
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
    public function confirmRecipe(Request $request, string $recipe): View|RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->first();
        if ($model === null) {
            return StaryAdresPrzepisu::przekieruj($request, $recipe, 'shopping.recipe.confirm', 'view');
        }
        $recipe = $model;
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

        try {
            $wynik = $lista->dodajSkladniki($user, $recipe, (bool) ($dane['potwierdzam'] ?? false));
        } catch (ValidationException $e) {
            // Strona przepisu, planer i ekran „dodać jeszcze raz?” nie mają
            // pola `text` ani podsumowania błędów przy tym przycisku — błąd
            // z limitu ginąłby po przekierowaniu „wstecz”, a przycisk
            // wyglądałby na martwy. Odmowa idzie na listę zakupów, gdzie
            // jest „Wyczyść odhaczone”, jako widoczny komunikat.
            return redirect()->route('shopping.index')->with(Komunikat::blad(
                (string) collect($e->errors())->flatten()->first(),
            ));
        }

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

    /**
     * Ekran „Wybierz składniki do zakupów” (#2462): przepis z polami wyboru
     * przy każdej linii. Zwykły GET — nic nie dopisuje, a odświeżenie ani
     * wejście tu niczego nie zmienia. Zapis idzie dopiero z „Dodaj wybrane”.
     */
    public function pickRecipe(Request $request, Recipe $recipe, ListaZakupow $lista): View
    {
        $this->authorize('view', $recipe);

        /** @var User $user */
        $user = $request->user();

        return view('pages.zakupy.wybierz', [
            'recipe' => $recipe,
            ...$lista->doWyboru($recipe),
            'zPlanera' => $request->boolean('z_planera'),
            'wczesniej' => session()->has('zakupy_wybor_juz_jest')
                ? $lista->pierwszeDodanie($user, $recipe)
                : null,
            'maksPozycji' => ListaZakupow::maksPozycji(),
        ]);
    }

    public function storePicked(Request $request, Recipe $recipe, ListaZakupow $lista): RedirectResponse
    {
        // Bramka stoi TU (skan tras widzi `authorize()` w metodzie kontrolera);
        // akcja domenowa pyta o to samo jeszcze raz.
        $this->authorize('view', $recipe);

        /** @var User $user */
        $user = $request->user();

        $zPlanera = $request->boolean('z_planera');
        $wracaDoWyboru = route('shopping.recipe.pick', [$recipe, ...($zPlanera ? ['z_planera' => 1] : [])]);

        try {
            $dane = $request->validate([
                'skladniki' => ['required', 'array', 'min:1', 'max:200'],
                'skladniki.*' => ['required', 'string', 'uuid'],
                'odcisk' => ['required', 'string', 'size:64'],
                'potwierdzam' => ['nullable', 'boolean'],
                'z_planera' => ['nullable', 'boolean'],
            ], [
                'skladniki.required' => 'Zaznacz co najmniej jeden składnik i naciśnij „Dodaj wybrane”. Nic nie zostało dodane.',
                'skladniki.array' => 'Zaznacz składniki z listy i naciśnij „Dodaj wybrane”. Nic nie zostało dodane.',
                'skladniki.min' => 'Zaznacz co najmniej jeden składnik i naciśnij „Dodaj wybrane”. Nic nie zostało dodane.',
                'skladniki.max' => 'Zaznaczono za dużo składników naraz. Zaznacz mniej i naciśnij „Dodaj wybrane”.',
                'skladniki.*.required' => 'Nie rozpoznajemy części zaznaczonych składników. Odśwież stronę i zaznacz je jeszcze raz.',
                'skladniki.*.string' => 'Nie rozpoznajemy części zaznaczonych składników. Odśwież stronę i zaznacz je jeszcze raz.',
                'skladniki.*.uuid' => 'Nie rozpoznajemy części zaznaczonych składników. Odśwież stronę i zaznacz je jeszcze raz.',
                'odcisk.required' => 'Ta strona jest nieaktualna. Odśwież ją i zaznacz składniki jeszcze raz.',
                'odcisk.string' => 'Ta strona jest nieaktualna. Odśwież ją i zaznacz składniki jeszcze raz.',
                'odcisk.size' => 'Ta strona jest nieaktualna. Odśwież ją i zaznacz składniki jeszcze raz.',
            ]);

            $wynik = $lista->dodajSkladniki(
                $user, $recipe, (bool) ($dane['potwierdzam'] ?? false), array_values($dane['skladniki']), $dane['odcisk'],
            );
        } catch (ValidationException $e) {
            // Limit z listy zakupów mówi o polu `text`, którego ten ekran nie
            // ma — błąd stoi przy wyborze, a zaznaczenie wraca z żądaniem.
            $komunikaty = $e->errors();
            if (isset($komunikaty['text'])) {
                $e = ValidationException::withMessages(['skladniki' => (string) $komunikaty['text'][0]]);
            }

            throw $e->redirectTo($wracaDoWyboru);
        }

        $zaznaczone = $request->only('skladniki', 'odcisk');

        if ($wynik['wynik'] === ListaZakupow::WYNIK_JUZ_JEST) {
            // Ostrzeżenie zachowuje dokładnie ten sam podzbiór: ekran wyboru
            // wraca z zaznaczeniem i przyciskiem „Dodaj wybrane jeszcze raz”.
            return redirect($wracaDoWyboru)->withInput($zaznaczone)->with('zakupy_wybor_juz_jest', true);
        }

        if ($wynik['wynik'] === ListaZakupow::WYNIK_ZMIENIONY) {
            return redirect($wracaDoWyboru)->withInput($zaznaczone)->with(Komunikat::blad(
                'Składniki tego przepisu zmieniły się, odkąd otworzono tę stronę, więc nic nie zostało dodane. Poniżej jest aktualna lista — sprawdź zaznaczenie i naciśnij „Dodaj wybrane” jeszcze raz.',
            ));
        }

        if ($wynik['wynik'] === ListaZakupow::WYNIK_BRAK_SKLADNIKOW) {
            return $this->wroc($request, $recipe)->with(Komunikat::informacja(
                'Ten przepis nie ma jeszcze składników, więc nie ma czego dodać do listy zakupów.',
            ));
        }

        $ile = $wynik['dodano'];

        return redirect()->route('shopping.index')->with(Komunikat::sukces(
            'Dodane do listy zakupów: '.$ile.' '.Odmiana::rzeczownik($ile, 'wybrany składnik', 'wybrane składniki', 'wybranych składników')
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

    /**
     * Ekran „Popraw” (#2443): zwykły formularz z obecnym tekstem pozycji.
     * Niesie znacznik tekstu, który człowiek widzi — z niego akcja pozna, że
     * ktoś w innym oknie zmienił go wcześniej.
     */
    public function edit(ShoppingListItem $pozycja): View
    {
        $this->authorize('update', $pozycja);

        return view('pages.zakupy.popraw', [
            'pozycja' => $pozycja,
            'znacznik' => ListaZakupow::znacznikTekstu($pozycja),
            'maksZnakow' => ListaZakupow::maksZnakow(),
        ]);
    }

    public function update(Request $request, ShoppingListItem $pozycja, ListaZakupow $lista): RedirectResponse
    {
        $this->authorize('update', $pozycja);

        /** @var User $user */
        $user = $request->user();

        $wracaDoFormularza = route('shopping.edit', $pozycja);

        try {
            $dane = $request->validate([
                'text' => ['required', 'string'],
                'stan' => ['required', 'string', 'max:64'],
            ], [
                'text.required' => 'Wpisz, co trzeba kupić, np. „mleko” albo „2 cebule”.',
                'text.string' => 'Wpisz zwykły tekst, np. „mleko” albo „2 cebule”.',
                'stan.required' => 'Ta strona jest nieaktualna. Wróć do listy zakupów i otwórz poprawianie jeszcze raz.',
                'stan.string' => 'Ta strona jest nieaktualna. Wróć do listy zakupów i otwórz poprawianie jeszcze raz.',
                'stan.max' => 'Ta strona jest nieaktualna. Wróć do listy zakupów i otwórz poprawianie jeszcze raz.',
            ]);

            $wynik = $lista->popraw($user, (string) $pozycja->getKey(), $dane['text'], $dane['stan']);
        } catch (ValidationException $e) {
            throw $e->redirectTo($wracaDoFormularza);
        }

        $naListe = route('shopping.index').'#pozycja-'.$pozycja->getKey();

        return match ($wynik) {
            ListaZakupow::POPRAWKA_ZASTOSOWANA => redirect($naListe)
                ->with(Komunikat::sukces('Pozycja poprawiona. Zostaje na swoim miejscu, z tym samym odhaczeniem.')),
            ListaZakupow::POPRAWKA_BEZ_ZMIAN => redirect($naListe)
                ->with(Komunikat::informacja('Ta pozycja ma już taki tekst. Nic nie zostało zmienione.')),
            // Wpisany tekst wraca do pola, a nad nim stoi aktualny tekst z listy.
            ListaZakupow::POPRAWKA_KONFLIKT => redirect($wracaDoFormularza)->withInput($request->only('text'))
                ->with(Komunikat::blad('Tekst tej pozycji zmienił się w innym oknie, więc nic nie zapisaliśmy. Poniżej widzisz aktualny tekst, a Twoja poprawka została w polu — jeśli nadal ją chcesz, kliknij „Zapisz” jeszcze raz.')),
            default => redirect()->route('shopping.index')
                ->with(Komunikat::blad('Tej pozycji już nie ma na liście — mogła zostać usunięta w innym oknie. Jeśli jej brakuje, dopisz ją jeszcze raz.')),
        };
    }

    public function destroy(Request $request, ShoppingListItem $pozycja, ListaZakupow $lista): RedirectResponse
    {
        $this->authorize('delete', $pozycja);

        /** @var User $user */
        $user = $request->user();

        $lista->usunPozycje($user, $pozycja);

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

    /**
     * „Cofnij usunięcie” — bez identyfikatora w adresie: dotyczy wyłącznie
     * ostatniej operacji zalogowanej osoby (jej migawki), więc nie ma czyjego
     * identyfikatora podstawić. Powtórzone żądanie dostaje „nie ma czego cofać”.
     */
    public function undo(Request $request, ListaZakupow $lista): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $wynik = $lista->cofnijUsuniecie($user);
        } catch (ValidationException $e) {
            return redirect()->route('shopping.index')->with(Komunikat::blad(
                (string) collect($e->errors())->flatten()->first(),
            ));
        }

        if ($wynik['wynik'] === ListaZakupow::COFNIECIE_BRAK) {
            return redirect()->route('shopping.index')->with(Komunikat::informacja(
                'Nie ma już czego cofać: usunięte pozycje zostały przywrócone albo minął czas na cofnięcie ('
                .ListaZakupow::minutCofniecia().' minut). Jeśli ich brakuje, dopisz je jeszcze raz.',
            ));
        }

        $ile = $wynik['przywrocono'];
        $odhaczone = $wynik['zakres'] === ShoppingListUndo::SCOPE_CHECKED
            ? ' Wróciły jako odhaczone, w sekcji „Odhaczone”.'
            : '';

        return redirect()->route('shopping.index')->with(Komunikat::sukces(
            'Przywrócone pozycje: '.$ile.'.'.$odhaczone,
        ));
    }

    /** Z planera wracamy do planera, ze strony przepisu — na nią. */
    private function wroc(Request $request, Recipe $recipe): RedirectResponse
    {
        return $request->boolean('z_planera')
            ? redirect()->route('planer.show')
            : redirect()->route('recipes.show', $recipe->slug);
    }
}
