<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zakupy\ListaZakupow;
use App\Models\Recipe;
use App\Models\ShoppingList;
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
    public function index(Request $request, ListaZakupow $lista): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $wybrana = $lista->znajdzListe($user, $this->idListy($request->query('lista')));
        } catch (ValidationException $e) {
            // Lista usunięta w innej karcie albo stary adres: wracamy na listę
            // domyślną z komunikatem, a nie z pustym ekranem.
            return redirect()->route('shopping.index')->with(Komunikat::blad(
                (string) collect($e->errors())->flatten()->first(),
            ));
        }

        $pozycje = $lista->pozycje($user, $wybrana);
        $cofniecie = $lista->oczekujaceCofniecie($user);
        $zakladki = $lista->podsumowanie($user);

        return view('pages.zakupy.index', [
            ...ListaZakupow::podziel($pozycje),
            'cofniecie' => $cofniecie,
            'cofnijDo' => $cofniecie !== null ? Czas::lokalnie($cofniecie->expires_at)->format('H:i') : null,
            'cofniecieLista' => $cofniecie !== null ? $this->nazwaListyCofniecia($cofniecie, $zakladki) : null,
            'ile' => count($pozycje),
            'ileNaKoncie' => array_sum(array_column($zakladki, 'ile')),
            'maksPozycji' => ListaZakupow::maksPozycji(),
            'maksZnakow' => ListaZakupow::maksZnakow(),
            'zakladki' => $zakladki,
            'wybrana' => $wybrana,
            'nazwaWybranej' => $wybrana->name ?? ShoppingList::NAZWA_DOMYSLNEJ,
            'maksList' => ListaZakupow::maksList(),
            'maksZnakowNazwy' => ListaZakupow::maksZnakowNazwy(),
            'mozeDodacListe' => count($zakladki) < ListaZakupow::maksList(),
        ]);
    }

    public function store(Request $request, ListaZakupow $lista): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate([
            'text' => ['required', 'string'],
            'lista' => ['nullable', 'string'],
        ], [
            'text.required' => 'Wpisz, co trzeba kupić, np. „mleko” albo „2 cebule”.',
            'text.string' => 'Wpisz zwykły tekst, np. „mleko” albo „2 cebule”.',
        ]);

        $wybrana = $lista->znajdzListe($user, $this->idListy($dane['lista'] ?? null));
        $pozycja = $lista->dodajReczna($user, (string) $dane['text'], $wybrana);

        return redirect($this->adres($wybrana?->getKey()).'#dopisz')
            ->with(Komunikat::sukces($wybrana === null
                ? 'Dopisane do listy zakupów: '.$pozycja->text.'.'
                : 'Dopisane do listy „'.$wybrana->name.'”: '.$pozycja->text.'.'));
    }

    /** Zakłada nazwaną listę i od razu ją otwiera — człowiek widzi, gdzie jest. */
    public function storeList(Request $request, ListaZakupow $lista): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate([
            'nazwa' => ['required', 'string'],
        ], [
            'nazwa.required' => 'Wpisz nazwę listy, np. „Święta” albo „Przyjęcie u Kasi”.',
            'nazwa.string' => 'Wpisz nazwę listy zwykłym tekstem, np. „Święta”.',
        ]);

        $nowa = $lista->utworzListe($user, (string) $dane['nazwa']);

        return redirect($this->adres($nowa->getKey()).'#dopisz')
            ->with(Komunikat::sukces('Założona lista zakupów „'.$nowa->name.'”. Dopisujesz teraz do niej.'));
    }

    public function renameList(Request $request, string $lista, ListaZakupow $domena): RedirectResponse
    {
        $model = ShoppingList::query()->find($lista);

        // Lista usunięta w innej karcie: komunikat, nie goła strona 404.
        if ($model === null) {
            return redirect()->route('shopping.index')->with(Komunikat::blad('Tej listy zakupów już nie ma. Nic nie zmieniliśmy.'));
        }

        $this->authorize('update', $model);

        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate([
            'nowa_nazwa' => ['required', 'string'],
        ], [
            'nowa_nazwa.required' => 'Wpisz nową nazwę listy, np. „Święta”.',
            'nowa_nazwa.string' => 'Wpisz nazwę listy zwykłym tekstem, np. „Święta”.',
        ]);

        $zmieniona = $domena->zmienNazweListy($user, $model, (string) $dane['nowa_nazwa']);

        return redirect($this->adres($zmieniona->getKey()))
            ->with(Komunikat::sukces('Zmieniona nazwa listy: „'.$zmieniona->name.'”. Pozycje zostały bez zmian.'));
    }

    public function destroyList(Request $request, string $lista, ListaZakupow $domena): RedirectResponse
    {
        $model = ShoppingList::query()->find($lista);

        // Powtórzone kliknięcie albo lista usunięta w innej karcie: komunikat, nie 404.
        if ($model === null) {
            return redirect()->route('shopping.index')->with(Komunikat::informacja('Tej listy już nie ma. Nic nie trzeba robić.'));
        }

        $this->authorize('delete', $model);

        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate([
            'potwierdzam' => ['nullable', 'boolean'],
            'widziana_liczba' => ['nullable', 'integer', 'min:0'],
        ]);

        $nazwa = $model->name;
        $ile = $domena->usunListe(
            $user,
            $model,
            (bool) ($dane['potwierdzam'] ?? false),
            isset($dane['widziana_liczba']) ? (int) $dane['widziana_liczba'] : null,
        );

        if ($ile < 0) {
            return redirect()->route('shopping.index')->with(Komunikat::informacja('Tej listy już nie ma. Nic nie trzeba robić.'));
        }

        return redirect()->route('shopping.index')->with(Komunikat::sukces(
            'Usunięta lista zakupów „'.$nazwa.'”'.($ile > 0
                ? ' razem z jej pozycjami ('.$ile.').'
                : '.'),
        ));
    }

    /**
     * Ekran pytania „dodać jeszcze raz?” — do niego trafia „Dodaj składniki”,
     * gdy składniki tego przepisu już są na liście. GET, więc odświeżenie
     * niczego nie dopisuje.
     */
    public function confirmRecipe(Request $request, string $recipe, ListaZakupow $domena): View|RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->first();
        if ($model === null) {
            return StaryAdresPrzepisu::przekieruj($request, $recipe, 'shopping.recipe.confirm', 'view');
        }
        $recipe = $model;
        $this->authorize('view', $recipe);

        /** @var User $user */
        $user = $request->user();

        $docelowa = $domena->znajdzListe($user, $this->idListy($request->query('lista')));
        $naLiscie = fn () => ShoppingListItem::query()
            ->where('user_id', $user->getKey())
            ->where('recipe_id', $recipe->getKey())
            ->when(
                $docelowa === null,
                fn ($q) => $q->whereNull('list_id'),
                fn ($q) => $q->where('list_id', $docelowa?->getKey()),
            );

        $pierwsze = $naLiscie()->min('created_at');

        // Nikt nie ma o co pytać — wracamy do przepisu.
        if ($pierwsze === null) {
            return redirect()->route('recipes.show', $recipe->slug);
        }

        return view('pages.zakupy.potwierdz', [
            'recipe' => $recipe,
            'kiedy' => Czas::data(Carbon::parse($pierwsze), 'j F'),
            'ile' => $naLiscie()->count(),
            'docelowa' => $docelowa,
            'nazwaListy' => $docelowa->name ?? ShoppingList::NAZWA_DOMYSLNEJ,
            'maInneListy' => $domena->listy($user)->isNotEmpty(),
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
            'lista' => ['nullable', 'string'],
        ]);

        try {
            $wybrana = $lista->znajdzListe($user, $this->idListy($dane['lista'] ?? null));
            $wynik = $lista->dodajSkladniki($user, $recipe, (bool) ($dane['potwierdzam'] ?? false), $wybrana);
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
                ...($wybrana !== null ? ['lista' => $wybrana->getKey()] : []),
            ]);
        }

        if ($wynik['wynik'] === ListaZakupow::WYNIK_BRAK_SKLADNIKOW) {
            return $this->wroc($request, $recipe)->with(Komunikat::informacja(
                'Ten przepis nie ma jeszcze składników, więc nie ma czego dodać do listy zakupów.',
            ));
        }

        $ile = $wynik['dodano'];

        return redirect($this->adres($wybrana?->getKey()))->with(Komunikat::sukces(
            ($wybrana === null ? 'Dodane do listy zakupów: ' : 'Dodane do listy „'.$wybrana->name.'”: ').$ile.' '.Odmiana::rzeczownik($ile, 'składnik', 'składniki', 'składników')
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

        return redirect($this->adres($pozycja->list_id).'#pozycja-'.$pozycja->getKey());
    }

    public function destroy(Request $request, ShoppingListItem $pozycja, ListaZakupow $lista): RedirectResponse
    {
        $this->authorize('delete', $pozycja);

        /** @var User $user */
        $user = $request->user();

        $lista->usunPozycje($user, $pozycja);

        return redirect($this->adres($pozycja->list_id))
            ->with(Komunikat::sukces('Usunięte z listy zakupów: '.$pozycja->text.'.'));
    }

    public function clear(Request $request, ListaZakupow $lista): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate(['lista' => ['nullable', 'string']]);
        $wybrana = $lista->znajdzListe($user, $this->idListy($dane['lista'] ?? null));

        $ile = $lista->wyczyscOdhaczone($user, $wybrana);

        return redirect($this->adres($wybrana?->getKey()))->with($ile > 0
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

        return redirect($this->adres($wynik['lista_id']))->with(Komunikat::sukces(
            'Przywrócone pozycje: '.$ile.'.'.$odhaczone,
        ));
    }

    /** Adres ekranu listy: domyślna bez parametru, nazwana z jawnym identyfikatorem. */
    private function adres(?string $idListy): string
    {
        return $idListy === null ? route('shopping.index') : route('shopping.index', ['lista' => $idListy]);
    }

    private function idListy(mixed $wartosc): ?string
    {
        return is_string($wartosc) && $wartosc !== '' ? $wartosc : null;
    }

    /**
     * Nazwa listy, z której zniknęły pozycje czekające na cofnięcie (wszystkie
     * pochodzą z jednej listy — usuwa się z listy otwartej na ekranie).
     *
     * @param  list<array{lista: ?ShoppingList, nazwa: string, ile: int, do_kupienia: int}>  $zakladki
     */
    private function nazwaListyCofniecia(ShoppingListUndo $cofniecie, array $zakladki): string
    {
        $idListy = $cofniecie->items[0]['list_id'] ?? null;

        foreach ($zakladki as $z) {
            if ($z['lista']?->getKey() === $idListy) {
                return $z['nazwa'];
            }
        }

        return ShoppingList::NAZWA_DOMYSLNEJ;
    }

    /** Z planera wracamy do planera, ze strony przepisu — na nią. */
    private function wroc(Request $request, Recipe $recipe): RedirectResponse
    {
        return $request->boolean('z_planera')
            ? redirect()->route('planer.show')
            : redirect()->route('recipes.show', $recipe->slug);
    }
}
