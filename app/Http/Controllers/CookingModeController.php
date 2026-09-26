<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tryb gotowania (issue #24).
 *
 * Telefon leży na blacie, ręce są mokre albo umączone, ekran gaśnie —
 * to jest zupełnie inna sytuacja niż czytanie przepisu na kanapie.
 * Ten kontroler pokazuje DOKŁADNIE JEDEN krok naraz, bardzo dużym tekstem,
 * i pamięta, które kroki są już zrobione.
 *
 * DECYZJA: GDZIE TRZYMAMY „ZROBIONE” KROKI
 *
 * Issue wymaga, żeby odhaczenie przetrwało PRZYPADKOWE wyjście z trybu
 * (zamknięcie karty, zablokowanie telefonu w trakcie gotowania) — ale
 * nie wymaga synchronizacji między urządzeniami ani trwałości „na zawsze”.
 * Zamiast nowej tabeli w bazie (migracja + rollback + docs/DATABASE.md dla
 * stanu, który jest z natury tymczasowy i personalny) używamy sesji
 * Laravela: `SESSION_DRIVER=database` w tym projekcie, więc to i tak
 * trafia do bazy, ale bez nowego schematu, bez migracji i bez ryzyka dla
 * prawdziwych danych przepisu. Sesja żyje dłużej niż zwykle
 * (`SESSION_LIFETIME=10080` = tydzień), co w praktyce pokrywa każde
 * „przypadkowe wyjście” z kryteriów akceptacji.
 *
 * CZEGO NIE WYBRANO: osobnej tabeli `cooking_progress` (autor, przepis,
 * krok, zrobiono). Zostawiam to jako naturalne rozszerzenie V2, jeśli
 * ktoś zgłosi realną potrzebę „zacząłem na telefonie, kończę na tablecie”
 * — dziś tego nikt nie prosił, a AGENTS.md każe nie dokładać schematu bez
 * zmierzonej potrzeby.
 *
 * DECYZJA: WIDOCZNOŚĆ PRZEZ `view`, NIE PRZEZ `cook`
 *
 * `RecipePolicy::cook()` dodatkowo wymaga `$user->isActive()` — to warunek
 * NA ZAPISANIE „Ugotowałem”, nie na SAMO PATRZENIE na kroki. Ktoś zawieszony
 * ma dziś prawo czytać przepis (zob. komentarz w RecipePolicy o zawieszeniu
 * jako karze wyłącznie na publikowanie) — tryb gotowania to wciąż czytanie,
 * więc pyta o dokładnie tę samą Policy co `/przepisy/{recipe}`, nie tworzy
 * nowego warunku widoczności obok istniejącego.
 *
 * DECYZJA: NAWIGACJA GET, ZAPIS POST
 *
 * „Poprzedni krok” / „Następny krok” to zwykłe linki `?krok=N` — issue wprost
 * nazywa to najtańszym możliwym rozwiązaniem i jest w porządku, bo to czysta
 * nawigacja, bez skutku ubocznego. Odznaczanie kroku jako zrobionego ZMIENIA
 * stan (sesję), więc to POST z CSRF — tak samo jak każdy inny zapis w tym
 * serwisie (zapisz do zeszytu, „Ugotowałem”).
 */
class CookingModeController extends Controller
{
    /** Klucz sesji z listą ID kroków oznaczonych jako zrobione, per przepis. */
    private function sessionKey(Recipe $recipe): string
    {
        return 'gotowanie.'.$recipe->getKey().'.zrobione';
    }

    public function show(Request $request, string $recipe): View|RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();

        // UUID/slug w adresie to nie autoryzacja (AGENTS.md §7) — to samo
        // pytanie co na stronie przepisu, patrz komentarz nad klasą.
        $this->authorize('view', $model);

        $model->load(['steps.media', 'ingredients.unit']);
        $steps = $model->steps;

        if ($steps->isEmpty()) {
            // Bez kroków nie ma czego pokazywać krok-po-kroku — zamiast
            // pustego ekranu z przyciskami donikąd, wracamy tam, skąd
            // dało się w ogóle trafić w ten tryb.
            return redirect()->route('recipes.show', $model->slug)
                ->with('status', 'Ten przepis nie ma jeszcze opisanych kroków, więc nie da się go gotować krok po kroku.');
        }

        $total = $steps->count();
        $krok = $this->wyczyscKrok($request->query('krok'), $total);
        $aktualny = $steps->get($krok - 1);

        $zrobione = $request->session()->get($this->sessionKey($model), []);

        return view('pages.recipes.cooking', [
            'recipe' => $model,
            'steps' => $steps,
            'krok' => $krok,
            'total' => $total,
            'aktualnyKrok' => $aktualny,
            'krokZrobiony' => in_array($aktualny->getKey(), $zrobione, true),
            'hasProgress' => $steps->contains(fn ($step) => in_array($step->getKey(), $zrobione, true)),
        ]);
    }

    public function restart(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);
        $request->session()->forget($this->sessionKey($model));

        return redirect()->route('cooking.show', $model->slug)
            ->with('status', 'Odhaczenia usunięte. Możesz zacząć od pierwszego kroku.');
    }

    public function zaznacz(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $steps = $model->steps()->get();
        $total = $steps->count();

        $data = $request->validate([
            // Odhaczamy PO TOŻSAMOŚCI kroku, nie po jego numerze (issue #756).
            // Numer to tylko miejsce na liście w chwili, gdy ktoś otworzył
            // stronę — jeśli autor w międzyczasie przestawił kroki, „krok 2”
            // z tego formularza to już inna czynność niż ta, którą gotujący
            // widział na ekranie. `krok` zostaje wyłącznie po to, żeby przy
            // odmowie wrócić tam, gdzie człowiek był. Brak albo nieznane
            // `krok_id` NIE jest błędem walidacji: widok gotowania nie
            // pokazuje błędów pól, więc odmowa idzie komunikatem niżej.
            'krok_id' => ['nullable', 'string'],
            'krok' => ['nullable', 'integer', 'min:1'],
            // Checkbox: pole obecne w żądaniu tylko, gdy formularz je wysłał
            // z wartością „1” (zaznacz) albo „0” (cofnij) — patrz widok,
            // gdzie to jest ukryty input, nie prawdziwy checkbox (jeden
            // klik = jedna zmiana stanu, bez JavaScriptu).
            'zrobiono' => ['required', 'boolean'],
        ], [
            'krok.integer' => 'Numer kroku jest nieprawidłowy — odśwież stronę przepisu i spróbuj jeszcze raz.',
        ]);

        // Szukamy kroku wśród kroków TEGO przepisu — identyfikator kroku
        // z innego przepisu (albo usuniętego) po prostu tu nie pasuje.
        $pozycja = $steps->search(fn ($step) => $step->getKey() === ($data['krok_id'] ?? null));

        if ($pozycja === false) {
            // Krok, który gotujący widział, zniknął z przepisu (autor go
            // usunął) albo formularz nie powiedział, o który krok chodzi
            // (strona otwarta przed tą zmianą). Nie zgadujemy po numerze
            // ani po podobnym tekście — mówimy wprost, co zrobić.
            return redirect()->route('cooking.show', [$model->slug, 'krok' => $this->wyczyscKrok($data['krok'] ?? 1, $total)])
                ->with('status', 'Przepis zmienił się, odkąd otworzono ten krok, więc nic nie zostało oznaczone. Przeczytaj krok widoczny teraz na ekranie i oznacz go jeszcze raz, jeśli jest zrobiony.');
        }

        $krok = $pozycja + 1;
        $aktualny = $steps->get($pozycja);

        $klucz = $this->sessionKey($model);
        $zrobione = $request->session()->get($klucz, []);

        if ($data['zrobiono']) {
            $zrobione[] = $aktualny->getKey();
            $zrobione = array_values(array_unique($zrobione));
        } else {
            $zrobione = array_values(array_filter($zrobione, fn ($id) => $id !== $aktualny->getKey()));
        }

        $request->session()->put($klucz, $zrobione);

        return redirect()->route('cooking.show', [$model->slug, 'krok' => $krok]);
    }

    /**
     * Numer kroku z adresu bywa czymkolwiek — pusty, ujemny, tekst, liczba
     * większa niż liczba kroków (ktoś ręcznie zmienił `?krok=`). Zamiast 404
     * na coś tak nieszkodliwego jak zły numer strony, po cichu przycinamy
     * do najbliższego istniejącego kroku — dokładnie tak, jak zrobiłby to
     * człowiek, który się pomylił.
     */
    private function wyczyscKrok(mixed $surowy, int $total): int
    {
        $krok = filter_var($surowy, FILTER_VALIDATE_INT) ?: 1;

        return max(1, min($krok, $total));
    }
}
