<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\DziennikWgladu;
use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Models\CookingProgress;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Komunikat;
use Illuminate\Http\JsonResponse;
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
 * OPCJONALNA SYNCHRONIZACJA MIĘDZY URZĄDZENIAMI (#2016, V2)
 *
 * Sesja zostaje DOMYŚLNĄ drogą — dla gości i dla każdej osoby, która niczego
 * nie włączyła. Zalogowana, aktywna osoba może dla KONKRETNEGO przepisu
 * świadomie włączyć zapamiętywanie postępu na koncie (tabela
 * `cooking_progress`, `PostepGotowania`): odhaczone kroki żyją wtedy w bazie
 * doby od ostatniej zmiany i widzi je każde urządzenie tego konta. Obecność
 * wiersza jest zgodą; wyłączenie kasuje wiersz i zostawia odhaczenia w sesji
 * tego urządzenia. Bez JavaScriptu wszystko działa formularzami (odhaczenie
 * od razu ląduje na koncie); skrypt tylko okresowo pyta, czy inne urządzenie
 * nie zmieniło postępu, i pokazuje odnośnik do odświeżenia — bez skryptu
 * ten pas zostaje ukryty (D-053: żadnego martwego przycisku).
 *
 * Każde wejście: `RecipePolicy::view` (konto, które straciło dostęp do
 * przepisu, nie odtworzy postępu) plus `CookingProgressPolicy` (włączyć może
 * tylko aktywne konto; cudzego wiersza nie ma jak wskazać — wiersz wybiera
 * para „zalogowana osoba + przepis”, nigdy identyfikator z żądania).
 * ETAP 2: to samo konto zapamiętuje też składniki „przygotowane” (#2069,
 * `zapiszSkladniki`) i wybraną liczbę porcji (`zapiszPorcje`). Minutniki
 * zostają w przeglądarce — bez stałego odpytywania nie da się ich uczciwie
 * zsynchronizować (patrz docs/DATABASE.md).
 * Konflikt dwóch urządzeń: zapis to idempotentne ustawienie jednego kroku,
 * na ten sam krok wygrywa ostatni; formularz niesie widzianą rewizję i przy
 * rozbieżności osoba dostaje komunikat.
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
    public function __construct(private readonly PostepGotowania $postep) {}

    /**
     * Aktywny (niewygasły) zapamiętany postęp tej osoby dla przepisu.
     * Tylko dla aktywnego konta — zawieszone czyta przepis, ale niczego
     * nie zapisuje (jak przy „Ugotowałem”).
     */
    private function postepKonta(?User $osoba, Recipe $recipe): ?CookingProgress
    {
        return $osoba?->isActive() ? $this->postep->aktywny($osoba, $recipe) : null;
    }

    /** @return list<string> */
    private function idKrokow(Recipe $recipe): array
    {
        return $recipe->steps()->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

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

        // Tryb gotowania pokazuje cały przepis — wgląd z urzędu jak na stronie
        // przepisu (D-333, `DziennikWgladu::przepis()`).
        app(DziennikWgladu::class)->przepis($model, $request->user(), $request->ip());

        $model->load(['steps.media', 'ingredients.unit']);
        $osoba = $request->user();
        $postepKonta = $this->postepKonta($osoba, $model);

        // Porcje: jawne `?porcje=` w adresie ma pierwszeństwo; bez niego, przy
        // włączonej synchronizacji, bierzemy te zapisane na koncie (#2016).
        $zKonta = $postepKonta !== null && ! $request->query->has('porcje') ? $this->postep->porcje($postepKonta) : null;
        $wyborPorcji = WyborPorcji::dla($model, $zKonta ?? $request->query('porcje'));
        $parametrPorcji = $wyborPorcji->przeliczone() ? $wyborPorcji->doAdresu((float) $wyborPorcji->wybrane) : null;
        $steps = $model->steps;

        if ($steps->isEmpty()) {
            // Bez kroków nie ma czego pokazywać krok-po-kroku — zamiast
            // pustego ekranu z przyciskami donikąd, wracamy tam, skąd
            // dało się w ogóle trafić w ten tryb.
            return redirect()->route('recipes.show', array_filter([
                'recipe' => $model->slug, 'porcje' => $parametrPorcji,
            ], fn ($wartosc) => $wartosc !== null))
                ->with(Komunikat::blad('Ten przepis nie ma jeszcze opisanych kroków, więc nie da się go gotować krok po kroku.'));
        }

        $total = $steps->count();
        $krok = $this->wyczyscKrok($request->query('krok'), $total);
        $aktualny = $steps->get($krok - 1);

        $zrobione = $postepKonta !== null
            ? $this->postep->zrobione($postepKonta, $steps->pluck('id')->map(fn ($id): string => (string) $id)->all())
            : $request->session()->get($this->sessionKey($model), []);

        return view('pages.recipes.cooking', [
            'synchronizacja' => [
                'wlaczona' => $postepKonta !== null,
                'mozna_wlaczyc' => $postepKonta === null && $osoba !== null && $osoba->can('create', CookingProgress::class),
                'rewizja' => $postepKonta?->revision,
                'wygasa' => $postepKonta?->expires_at,
                'godziny' => (int) config('kuking.cooking_progress.retention_hours', 24),
                'przygotowane' => $postepKonta !== null
                    ? $this->postep->przygotowane($postepKonta, $model->ingredients->pluck('id')->map(fn ($id): string => (string) $id)->all())
                    : [],
            ],
            'recipe' => $model,
            'steps' => $steps,
            'krok' => $krok,
            'total' => $total,
            'aktualnyKrok' => $aktualny,
            'krokZrobiony' => in_array($aktualny->getKey(), $zrobione, true),
            'hasProgress' => $steps->contains(fn ($step) => in_array($step->getKey(), $zrobione, true)),
            'wyborPorcji' => $wyborPorcji,
            'parametrPorcji' => $parametrPorcji,
        ]);
    }

    public function restart(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);
        $request->session()->forget($this->sessionKey($model));

        $postepKonta = $this->postepKonta($request->user(), $model);
        if ($postepKonta !== null) {
            $this->postep->wyczysc($postepKonta);
        }

        return redirect()->route('cooking.show', array_filter([
            'recipe' => $model->slug, 'porcje' => $this->parametrPorcji($model, $request->input('porcje')),
        ], fn ($wartosc) => $wartosc !== null))
            ->with(Komunikat::sukces('Odhaczenia usunięte. Możesz zacząć od pierwszego kroku.'));
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
            // Rewizja zapamiętanego postępu, którą widziała strona (#2016);
            // brak (sesja, stara strona) nie jest błędem.
            'rewizja' => ['nullable', 'integer', 'min:1'],
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
            return redirect()->route('cooking.show', array_filter([
                'recipe' => $model->slug,
                'krok' => $this->wyczyscKrok($data['krok'] ?? 1, $total),
                'porcje' => $this->parametrPorcji($model, $request->input('porcje')),
            ], fn ($wartosc) => $wartosc !== null))
                ->with(Komunikat::blad('Przepis zmienił się, odkąd otworzono ten krok, więc nic nie zostało oznaczone. Przeczytaj krok widoczny teraz na ekranie i oznacz go jeszcze raz, jeśli jest zrobiony.'));
        }

        $krok = $pozycja + 1;
        $aktualny = $steps->get($pozycja);

        $postepKonta = $this->postepKonta($request->user(), $model);
        if ($postepKonta !== null) {
            $idKrokow = $steps->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $widzianaRewizja = isset($data['rewizja']) ? (int) $data['rewizja'] : null;

            // PODWÓJNE KLIKNIĘCIE TO NIE INNE URZĄDZENIE (audyt BP-05). Drugie
            // wysłanie tego samego formularza niesie rewizję sprzed pierwszego,
            // które podbiło ją o jeden. Jeśli od wyświetlenia strony przybyła
            // DOKŁADNIE jedna zmiana, a krok jest już w stanie, o który prosi
            // formularz, to tą zmianą było pierwsze kliknięcie z tej samej
            // strony — nie ma o czym ostrzegać ani czego chronić, a porcje
            // z formularza są tymi samymi, które poszły z pierwszym.
            $juzWZadanymStanie = in_array((string) $aktualny->getKey(), $this->postep->zrobione($postepKonta, $idKrokow), true)
                === (bool) $data['zrobiono'];
            $powtorzenie = $widzianaRewizja !== null && $widzianaRewizja + 1 === $postepKonta->revision && $juzWZadanymStanie;
            $rozbieznaRewizja = $widzianaRewizja !== null && $widzianaRewizja !== $postepKonta->revision && ! $powtorzenie;

            $po = $this->postep->ustaw(
                $postepKonta,
                $aktualny->getKey(),
                (bool) $data['zrobiono'],
                $idKrokow,
            );

            // Rozbieżna rewizja: formularz niesie liczbę porcji z chwili
            // wyświetlenia strony, a inne urządzenie mogło ją od tamtej pory
            // zmienić. Zapis tej liczby cofnąłby cudzą zmianę (zgubiona
            // aktualizacja), a zostawienie jej w adresie przeczyłoby
            // komunikatowi „widzisz aktualny stan” — więc ani jednego, ani
            // drugiego: obowiązuje liczba z konta.
            if (! $rozbieznaRewizja) {
                $this->zapamietajPorcjeZFormularza($request, $model, $postepKonta);
            }

            $przekierowanie = redirect()->route('cooking.show', array_filter([
                'recipe' => $model->slug, 'krok' => $krok,
                'porcje' => $rozbieznaRewizja && $po !== null ? null : $this->parametrPorcji($model, $request->input('porcje')),
            ], fn ($wartosc) => $wartosc !== null));

            if ($po === null) {
                // Zapamiętywanie wygasło między wyświetleniem strony a kliknięciem.
                return $przekierowanie->with(Komunikat::blad('Zapamiętywanie postępu na koncie wygasło, więc to kliknięcie nie zostało zapisane. Włącz zapamiętywanie jeszcze raz albo oznacz krok ponownie.'));
            }

            return $rozbieznaRewizja
                ? $przekierowanie->with(Komunikat::informacja('Postęp tego przepisu zmienił się na innym urządzeniu. Widzisz teraz jego aktualny stan, a Twoje kliknięcie zostało zapisane.'))
                : $przekierowanie;
        }

        $klucz = $this->sessionKey($model);
        $zrobione = $request->session()->get($klucz, []);

        if ($data['zrobiono']) {
            $zrobione[] = $aktualny->getKey();
            $zrobione = array_values(array_unique($zrobione));
        } else {
            $zrobione = array_values(array_filter($zrobione, fn ($id) => $id !== $aktualny->getKey()));
        }

        $request->session()->put($klucz, $zrobione);

        return redirect()->route('cooking.show', array_filter([
            'recipe' => $model->slug, 'krok' => $krok,
            'porcje' => $this->parametrPorcji($model, $request->input('porcje')),
        ], fn ($wartosc) => $wartosc !== null));
    }

    /**
     * Włączenie zapamiętywania postępu na koncie (#2016). Świadome, osobno
     * dla każdego przepisu; bierze to, co już odhaczono w sesji tego urządzenia.
     */
    public function wlaczSynchronizacje(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);
        $this->authorize('create', CookingProgress::class);

        $osoba = $request->user();
        $this->postep->wlacz(
            $osoba,
            $model,
            $this->idKrokow($model),
            (array) $request->session()->get($this->sessionKey($model), []),
            $this->porcjeDoZapisu($model, $request->input('porcje')),
        );
        $request->session()->forget($this->sessionKey($model));

        return $this->wrocDoGotowania($request, $model)
            ->with(Komunikat::sukces('Postęp tego przepisu jest teraz zapamiętywany na Twoim koncie — zobaczysz go na każdym urządzeniu, na którym się zalogujesz. Wygasa po '.(int) config('kuking.cooking_progress.retention_hours', 24).' godzinach od ostatniej zmiany.'));
    }

    /** Wyłączenie: kasuje zapamiętany postęp z konta, odhaczenia zostają w sesji tego urządzenia. */
    public function wylaczSynchronizacje(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $osoba = $request->user();
        $ids = $this->postep->wylacz($osoba, $model, $this->idKrokow($model));
        $request->session()->put($this->sessionKey($model), $ids);

        return $this->wrocDoGotowania($request, $model)
            ->with(Komunikat::sukces('Zapamiętywanie na koncie wyłączone, a zapisany tam postęp usunięty. Na tym urządzeniu odhaczenia zostają do końca sesji.'));
    }

    /**
     * Zapis składników „przygotowanych” na koncie (#2016, etap 2). Formularz
     * niesie pełną listę zaznaczonych (`zaznaczone[]`) i tę, którą strona
     * pokazała (`bylo[]`) — zapisujemy RÓŻNICĘ, więc zmiana z drugiego
     * urządzenia nie ginie. Działa bez JavaScriptu (zwykły POST).
     */
    public function zapiszSkladniki(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $osoba = $request->user();
        $postepKonta = $this->postepKonta($osoba, $model);

        if ($postepKonta === null) {
            return $this->wrocDoGotowania($request, $model)
                ->with(Komunikat::blad('Zapamiętywanie postępu na koncie jest wyłączone albo wygasło, więc zaznaczenie składników nie zostało zapisane. Włącz zapamiętywanie jeszcze raz.'));
        }

        $this->authorize('update', $postepKonta);

        $data = $request->validate([
            'zaznaczone' => ['nullable', 'array', 'max:300'],
            'zaznaczone.*' => ['string', 'max:64'],
            'bylo' => ['nullable', 'array', 'max:300'],
            'bylo.*' => ['string', 'max:64'],
            'rewizja' => ['nullable', 'integer', 'min:1'],
        ]);

        $zaznaczone = array_values(array_unique($data['zaznaczone'] ?? []));
        $bylo = array_values(array_unique($data['bylo'] ?? []));
        $widzianaRewizja = isset($data['rewizja']) ? (int) $data['rewizja'] : null;
        $rozbieznaRewizja = $widzianaRewizja !== null && $widzianaRewizja !== $postepKonta->revision;

        $po = $this->postep->ustawSkladniki(
            $postepKonta,
            array_values(array_diff($zaznaczone, $bylo)),
            array_values(array_diff($bylo, $zaznaczone)),
            $model->ingredients()->pluck('id')->map(fn ($id): string => (string) $id)->all(),
        );

        if ($po === null) {
            return $this->wrocDoGotowania($request, $model)
                ->with(Komunikat::blad('Zapamiętywanie postępu na koncie wygasło, więc zaznaczenie składników nie zostało zapisane. Włącz zapamiętywanie jeszcze raz.'));
        }

        return $this->wrocDoGotowania($request, $model)
            ->with('skladniki_otwarte', true)
            ->with($rozbieznaRewizja
                ? Komunikat::informacja('Składniki tego przepisu zmieniły się na innym urządzeniu. Widzisz teraz ich aktualny stan, a Twoje zaznaczenie zostało dołączone.')
                : Komunikat::sukces('Zaznaczenie składników zapisane na Twoim koncie.'));
    }

    /**
     * Zmiana liczby porcji zapamiętanej na koncie (#2016, etap 2). Przyciski
     * „Mniej”, „Więcej” i „Porcje z przepisu” to zwykły formularz. Wartość
     * przechodzi przez `WyborPorcji`, więc nie da się zapisać liczby spoza
     * zakresu ani porcji dla przepisu, który ich nie podaje.
     */
    public function zapiszPorcje(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $postepKonta = $this->postepKonta($request->user(), $model);

        if ($postepKonta === null) {
            return $this->wrocDoGotowania($request, $model)
                ->with(Komunikat::blad('Zapamiętywanie postępu na koncie jest wyłączone albo wygasło, więc liczba porcji nie została zapisana. Włącz zapamiętywanie jeszcze raz.'));
        }

        $this->authorize('update', $postepKonta);

        $surowe = $request->input('wybor');
        $porcje = null;

        if ($surowe !== 'przepis') {
            $wybor = WyborPorcji::dla($model, $surowe);

            // Puste albo brakujące pole to nie „z przepisu” (to robi wyłącznie
            // wartość „przepis”), tylko błąd — inaczej pusty POST kasowałby wybór.
            if ($surowe === null || $surowe === '' || $wybor->odrzucone || ! $wybor->dostepny()) {
                return $this->wrocDoGotowania($request, $model)
                    ->with(Komunikat::blad('Nie umiem użyć tej liczby porcji. Użyj przycisków „Mniej porcji” i „Więcej porcji” albo wróć do porcji z przepisu.'));
            }

            $porcje = $wybor->przeliczone() ? (float) $wybor->wybrane : null;
        }

        $po = $this->postep->ustawPorcje($postepKonta, $porcje);

        if ($po === null) {
            return $this->wrocDoGotowania($request, $model)
                ->with(Komunikat::blad('Zapamiętywanie postępu na koncie wygasło, więc liczba porcji nie została zapisana. Włącz zapamiętywanie jeszcze raz.'));
        }

        // Adres NIE niesie starej liczby porcji — obowiązuje ta z konta.
        return redirect()->route('cooking.show', array_filter([
            'recipe' => $model->slug,
            'krok' => $this->wyczyscKrok($request->input('krok'), max(1, $model->steps()->count())),
            'porcje' => $porcje === null ? null : WyborPorcji::dla($model, $porcje)->doAdresu($porcje),
        ], fn ($wartosc) => $wartosc !== null));
    }

    /**
     * Krótki odczyt dla skryptu, który pyta, czy inne urządzenie zmieniło
     * postęp (#2016). Tylko numer rewizji — bez listy kroków i bez niczego,
     * co pokazałoby czyjś postęp komuś innemu: wiersz wybiera para „ta osoba +
     * ten przepis”, więc obca osoba dostaje po prostu `aktywna: false`.
     */
    public function postepZapamietany(Request $request, string $recipe): JsonResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $postepKonta = $this->postepKonta($request->user(), $model);

        return response()
            ->json(['aktywna' => $postepKonta !== null, 'rewizja' => $postepKonta?->revision])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Porcje z formularza jako liczba do zapisu na koncie; null = z przepisu albo nieużyteczne. */
    private function porcjeDoZapisu(Recipe $recipe, mixed $surowe): ?float
    {
        $wybor = WyborPorcji::dla($recipe, $surowe);

        return $wybor->przeliczone() ? (float) $wybor->wybrane : null;
    }

    /**
     * Krok odhaczony z jawnie wybraną liczbą porcji zapamiętuje ją na koncie,
     * żeby drugie urządzenie otworzyło przepis na tyle samo porcji.
     */
    private function zapamietajPorcjeZFormularza(Request $request, Recipe $recipe, CookingProgress $postepKonta): void
    {
        if (! $request->filled('porcje')) {
            return;
        }

        $porcje = $this->porcjeDoZapisu($recipe, $request->input('porcje'));

        if ($porcje !== null && $porcje !== $this->postep->porcje($postepKonta)) {
            $this->postep->ustawPorcje($postepKonta, $porcje);
        }
    }

    private function wrocDoGotowania(Request $request, Recipe $model): RedirectResponse
    {
        return redirect()->route('cooking.show', array_filter([
            'recipe' => $model->slug,
            'krok' => $this->wyczyscKrok($request->input('krok'), max(1, $model->steps()->count())),
            'porcje' => $this->parametrPorcji($model, $request->input('porcje')),
        ], fn ($wartosc) => $wartosc !== null));
    }

    private function parametrPorcji(Recipe $recipe, mixed $surowe): ?string
    {
        $wybor = WyborPorcji::dla($recipe, $surowe);

        return $wybor->przeliczone() ? $wybor->doAdresu((float) $wybor->wybrane) : null;
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
