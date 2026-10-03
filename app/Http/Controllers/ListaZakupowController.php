<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Porcje\WyborPorcji;
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

    /**
     * „Wydrukuj do kupienia” (#2495): kartka z samymi nieodhaczonymi pozycjami
     * WŁASNEJ listy, w kolejności listy i z dosłownym tekstem (bez parsowania
     * i łączenia linii). GET, czyste odczytanie — nie odhacza, nie usuwa i nie
     * zapisuje kopii na serwerze. Pochodzenie pozycji, adresy przepisów i dane
     * konta nie trafiają na kartkę, więc niedostępny przepis niczego nie zdradza;
     * własny tekst pozycji drukuje się zawsze.
     *
     * Nazwane listy (#2528): kartka dotyczy listy otwartej na ekranie
     * (`?lista=`), bez parametru — listy domyślnej.
     */
    public function druk(Request $request, ListaZakupow $lista): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $wybrana = $lista->znajdzListe($user, $this->idListy($request->query('lista')));
        } catch (ValidationException $e) {
            return redirect()->route('shopping.index')->with(Komunikat::blad(
                (string) collect($e->errors())->flatten()->first(),
            ));
        }

        $doKupienia = ListaZakupow::podziel($lista->pozycje($user, $wybrana))['do_kupienia'];

        return view('pages.zakupy.do-druku', [
            'nazwaListy' => $wybrana?->name,
            'naListe' => $this->adres($wybrana?->getKey()),
            'parametryListy' => $wybrana !== null ? ['lista' => $wybrana->getKey()] : [],
            'pozycje' => array_map(fn (array $wiersz): string => $wiersz['pozycja']->text, $doKupienia),
            'dataOdczytu' => Czas::lokalnie(Carbon::now())->translatedFormat('j F Y, H:i'),
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
            // Przeliczone porcje (#2489) przechodzą przez ostrzeżenie bez zmian.
            'porcje' => $this->porcjeZAdresu($request, $recipe),
            'odcisk' => $this->odciskZAdresu($request),
            'recipe' => $recipe,
            'kiedy' => Czas::data(Carbon::parse($pierwsze), 'j F'),
            'ile' => $naLiscie()->count(),
            'docelowa' => $docelowa,
            'nazwaListy' => $docelowa->name ?? ShoppingList::NAZWA_DOMYSLNEJ,
            'maInneListy' => $domena->listy($user)->isNotEmpty(),
        ]);
    }

    /**
     * Podgląd „Dodaj składniki na wybraną liczbę porcji” (#2489). GET, bez
     * zapisu: pokazuje linie autora obok tego, co trafi na listę, i które
     * linie zostają oryginalne. Zatwierdzenie to POST `storeRecipe` z odciskiem.
     */
    public function previewScaled(Request $request, string $recipe, ListaZakupow $lista): View|RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->first();
        if ($model === null) {
            return StaryAdresPrzepisu::przekieruj($request, $recipe, 'shopping.recipe.scaled', 'view');
        }
        $this->authorize('view', $model);

        $wybor = WyborPorcji::dla($model, $request->query('porcje'));

        // Bez przeliczenia (brak podstawy porcji, zła albo równa autorowi liczba)
        // nie ma co podglądać: wracamy do przepisu z wyjaśnieniem.
        if (! $wybor->przeliczone() || $wybor->odrzucone) {
            return redirect()->route('recipes.show', $model->slug)->with(Komunikat::informacja(
                'Wybierz na stronie przepisu inną liczbę porcji niż w przepisie, a potem „Dodaj składniki na wybraną liczbę porcji”. Przycisk „Dodaj składniki do listy zakupów” dodaje ilości autora.',
            ));
        }

        $podglad = $lista->podgladPorcji($model, $wybor);

        return view('pages.zakupy.porcje-podglad', [
            'recipe' => $model,
            'wybor' => $wybor,
            'porcje' => (string) $wybor->doAdresu((float) $wybor->wybrane),
            // Lista docelowa (#2528): wybór sprzed błędu albo sprzed zmiany
            // przepisu. Pole porównuje go tylko z WŁASNYMI listami osoby.
            'wybranaLista' => $this->idListy(old('lista', $request->query('lista'))),
            ...$podglad,
        ]);
    }

    private function porcjeZAdresu(Request $request, Recipe $recipe): ?string
    {
        $wybor = WyborPorcji::dla($recipe, $request->query('porcje'));

        return $wybor->przeliczone() && ! $wybor->odrzucone ? (string) $wybor->doAdresu((float) $wybor->wybrane) : null;
    }

    private function odciskZAdresu(Request $request): ?string
    {
        $odcisk = $request->query('odcisk');

        return is_string($odcisk) && preg_match('/^[0-9a-f]{64}$/', $odcisk) === 1 ? $odcisk : null;
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
            'porcje' => ['nullable', 'string', 'max:12'],
            'odcisk' => ['nullable', 'string', 'size:64'],
            'lista' => ['nullable', 'string'],
            'ilosci' => ['nullable', 'string', 'in:przeliczone,autora'],
        ], [
            'porcje.max' => 'Nie rozpoznajemy tej liczby porcji. Wybierz ją jeszcze raz na stronie przepisu.',
            'odcisk.size' => 'Podgląd jest nieaktualny. Otwórz podgląd jeszcze raz.',
            'ilosci.in' => 'Nie wiemy, które ilości dodać. Otwórz podgląd jeszcze raz i naciśnij jeden z przycisków.',
        ]);

        // Podgląd porcji (#2489) ma jeden formularz z wyborem listy (#2528)
        // i dwa przyciski: „Dodaj ilości autora” wysyła `ilosci=autora`, więc
        // liczba porcji z podglądu zostaje pominięta i kopiujemy ilości autora.
        $podglad = filled($dane['porcje'] ?? null) ? (string) $dane['porcje'] : null;
        if (($dane['ilosci'] ?? null) === 'autora') {
            $dane['porcje'] = null;
        }

        // Przeliczone porcje (#2489): liczba musi przejść ten sam `WyborPorcji`
        // co strona przepisu; zła wartość NIE zapisuje po cichu innych ilości.
        $porcje = null;
        if (filled($dane['porcje'] ?? null)) {
            $wybor = WyborPorcji::dla($recipe, $dane['porcje']);
            if (! $wybor->przeliczone() || $wybor->odrzucone) {
                return redirect()->route('recipes.show', $recipe->slug)->with(Komunikat::blad(
                    'Nie rozpoznajemy tej liczby porcji, więc niczego nie dodaliśmy. Wybierz liczbę porcji jeszcze raz na stronie przepisu.',
                ));
            }
            $porcje = (float) $wybor->wybrane;
        }
        $odcisk = $porcje !== null ? ($dane['odcisk'] ?? null) : null;

        try {
            $wybrana = $lista->znajdzListe($user, $this->idListy($dane['lista'] ?? null));
            $wynik = $lista->dodajSkladniki($user, $recipe, (bool) ($dane['potwierdzam'] ?? false), $porcje, $odcisk, lista: $wybrana);
        } catch (ValidationException $e) {
            // Z podglądu porcji (#2489) błąd listy docelowej (#2528) wraca na
            // podgląd: przy polu wyboru, z tą samą liczbą porcji i wpisanym wyborem.
            if ($podglad !== null && isset($e->errors()['lista'])) {
                throw $e->redirectTo(route('shopping.recipe.scaled', ['recipe' => $recipe->slug, 'porcje' => $podglad]));
            }

            // Strona przepisu, planer i ekran „dodać jeszcze raz?” nie mają
            // pola `text` ani podsumowania błędów przy tym przycisku — błąd
            // z limitu ginąłby po przekierowaniu „wstecz”, a przycisk
            // wyglądałby na martwy. Odmowa idzie na listę zakupów, gdzie
            // jest „Wyczyść odhaczone”, jako widoczny komunikat.
            return redirect()->route('shopping.index')->with(Komunikat::blad(
                (string) collect($e->errors())->flatten()->first(),
            ));
        }

        if ($wynik['wynik'] === ListaZakupow::WYNIK_ZMIENIONY) {
            return redirect()->route('shopping.recipe.scaled', [
                'recipe' => $recipe->slug,
                'porcje' => $dane['porcje'] ?? null,
                // Wybrana lista (#2528) zostaje zaznaczona na świeżym podglądzie.
                ...($wybrana !== null ? ['lista' => $wybrana->getKey()] : []),
            ])->with(Komunikat::informacja(
                'Składniki przepisu zmieniły się od chwili podglądu, więc niczego nie dodaliśmy. Sprawdź aktualny podgląd i zatwierdź jeszcze raz.',
            ));
        }

        if ($wynik['wynik'] === ListaZakupow::WYNIK_JUZ_JEST) {
            return redirect()->route('shopping.recipe.confirm', [
                'recipe' => $recipe->slug,
                ...($request->boolean('z_planera') ? ['z_planera' => 1] : []),
                // Zaakceptowana liczba porcji i podgląd przechodzą przez ostrzeżenie (#2489).
                ...($porcje !== null ? ['porcje' => $dane['porcje'], 'odcisk' => $odcisk] : []),
                ...($wybrana !== null ? ['lista' => $wybrana->getKey()] : []),
            ]);
        }

        if ($wynik['wynik'] === ListaZakupow::WYNIK_BRAK_SKLADNIKOW) {
            return $this->wroc($request, $recipe)->with(Komunikat::informacja(
                'Ten przepis nie ma jeszcze składników, więc nie ma czego dodać do listy zakupów.',
            ));
        }

        $ile = $wynik['dodano'];
        $komunikat = ($wybrana === null ? 'Dodane do listy zakupów: ' : 'Dodane do listy „'.$wybrana->name.'”: ').$ile.' '.Odmiana::rzeczownik($ile, 'składnik', 'składniki', 'składników')
            .' z przepisu „'.$recipe->title.'”.';

        if ($porcje !== null) {
            $komunikat .= ' Ilości przeliczone na '.WyborPorcji::etykieta($porcje).': '.$wynik['przeliczono'].'.';
            if ($wynik['bez_przeliczenia'] > 0) {
                $komunikat .= ' Bez przeliczenia, takie jak napisał autor: '.$wynik['bez_przeliczenia'].' — sprawdź je samodzielnie.';
            }
        }

        return redirect($this->adres($wybrana?->getKey()))->with(Komunikat::sukces($komunikat));
    }

    /**
     * Ekran „Wybierz składniki do zakupów” (#2462): przepis z polami wyboru
     * przy każdej linii. Zwykły GET — nic nie dopisuje, a odświeżenie ani
     * wejście tu niczego nie zmienia. Zapis idzie dopiero z „Dodaj wybrane”.
     *
     * Nazwane listy (#2528): `?lista=` to lista wybrana przed ostrzeżeniem
     * o duplikacie albo przed zmianą przepisu — pole wyboru wraca z nią,
     * a ostrzeżenie liczy duplikat w obrębie TEJ listy. Lista usunięta
     * w innej karcie nie blokuje ekranu: pole wraca do listy domyślnej.
     */
    public function pickRecipe(Request $request, Recipe $recipe, ListaZakupow $lista): View
    {
        $this->authorize('view', $recipe);

        /** @var User $user */
        $user = $request->user();

        try {
            $docelowa = $lista->znajdzListe($user, $this->idListy($request->query('lista')));
        } catch (ValidationException) {
            $docelowa = null;
        }

        $wczesniej = session()->has('zakupy_wybor_juz_jest')
            ? $lista->pierwszeDodanie($user, $recipe, $docelowa)
            : null;

        return view('pages.zakupy.wybierz', [
            'recipe' => $recipe,
            ...$lista->doWyboru($recipe),
            'zPlanera' => $request->boolean('z_planera'),
            'wczesniej' => $wczesniej,
            'docelowa' => $docelowa,
            'wybranaLista' => $this->idListy(old('lista', $docelowa?->getKey())),
            'nazwaListy' => $docelowa->name ?? ShoppingList::NAZWA_DOMYSLNEJ,
            'maInneListy' => $lista->listy($user)->isNotEmpty(),
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
                'potwierdzona_lista' => ['nullable', 'string'],
                'z_planera' => ['nullable', 'boolean'],
                'lista' => ['nullable', 'string'],
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

            // Lista docelowa (#2528) — jak w „Dodaj składniki”: pusta = domyślna,
            // cudza = odmowa, usunięta = błąd przy polu z zachowanym zaznaczeniem.
            $idListy = $this->idListy($dane['lista'] ?? null);
            $wybrana = $lista->znajdzListe($user, $idListy);
            if ($wybrana !== null) {
                $wracaDoWyboru = route('shopping.recipe.pick', [$recipe, ...($zPlanera ? ['z_planera' => 1] : []), 'lista' => $wybrana->getKey()]);
            }

            // „Dodaj wybrane jeszcze raz” potwierdza duplikat na liście, o którą
            // pytało ostrzeżenie. Inna lista wybrana po ostrzeżeniu — nowe pytanie.
            $potwierdzone = (bool) ($dane['potwierdzam'] ?? false)
                && $this->idListy($dane['potwierdzona_lista'] ?? null) === $idListy;

            $wynik = $lista->dodajSkladniki(
                $user, $recipe, $potwierdzone, odcisk: $dane['odcisk'], wybraneId: array_values($dane['skladniki']), lista: $wybrana,
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

        $zaznaczone = $request->only('skladniki', 'odcisk', 'lista');

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

        return redirect($this->adres($wybrana?->getKey()))->with(Komunikat::sukces(
            ($wybrana === null ? 'Dodane do listy zakupów: ' : 'Dodane do listy „'.$wybrana->name.'”: ')
            .$ile.' '.Odmiana::rzeczownik($ile, 'wybrany składnik', 'wybrane składniki', 'wybranych składników')
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

        $naListe = $this->adres($pozycja->list_id).'#pozycja-'.$pozycja->getKey();

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
