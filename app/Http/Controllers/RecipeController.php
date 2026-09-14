<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\CoMoznaDopisac;
use App\Domain\Recipes\StepTimer;
use App\Domain\Recipes\TekstNaWiersze;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\Unit;
use App\Models\User;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityTekstuPrzepisu;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Przepisy.
 *
 * DODAWANIE I DOPISYWANIE TO OD #364 DWIE RÓŻNE RZECZY
 *
 * Do 11 września 2026 były jedną: `/dodaj/przepis` pokazywał przy ośmiu
 * składnikach i trzech krokach około 89 kontrolek, bo kazał rozstrzygnąć
 * strukturę przepisu, zanim pozwolił cokolwiek napisać. Właściciel wkleił
 * wtedy listę składników do pola „Krótko o przepisie" — nie z niezrozumienia
 * podpisu, tylko dlatego, że formularz nie słuchał. Rozdzielenie wygląda tak:
 *
 *  1. `/dodaj/przepis` — DODAWANIE. Sześć rzeczy: zdjęcie, tytuł, składniki
 *     (jedno pole, jeden na wiersz, NIEOBOWIĄZKOWE), przygotowanie (jedno
 *     pole, pusta linia = nowy krok), kto ma widzieć, Opublikuj. Zwykły POST,
 *     bez JavaScriptu.
 *  2. `/przepisy/{slug}/szczegoly` — DOPISYWANIE, kreator w trzech krokach
 *     (komponent Livewire `recipe-wizard`). Wymaga JavaScriptu.
 *  3. `/przepisy/{slug}/edycja` — to samo dopisywanie na jednej stronie,
 *     zwykłym POST-em. Droga bez skryptu, więc kreator nie jest jedyna.
 *
 * BAZA SIĘ NIE ZMIENIŁA. Oba pola tekstowe z punktu 1 serwer rozbija
 * z powrotem na `recipe_ingredients` i `recipe_steps`
 * (`App\Domain\Recipes\TekstNaWiersze`), więc przeliczanie porcji
 * i szukanie po składnikach działają dalej.
 *
 * Wszystkie drogi kończą się w tej samej akcji domenowej `PublishRecipe`,
 * więc reguły („szkic da się zapisać z samym tytułem”, „publikacja wymaga
 * kroku, ale NIE wymaga składnika” — zgoda właściciela z #364) są jedne,
 * nie trzy.
 */
class RecipeController extends Controller
{
    public function __construct(
        private readonly PublishRecipe $publishRecipe,
        private readonly StoreUploadedImage $storeImage,
        private readonly PublishComment $publishComment,
    ) {}

    /**
     * Ekran dodawania przepisu — sześć rzeczy i koniec (issue #364).
     *
     * `?szkic={uuid}` prowadzi do KREATORA, nie tutaj, i to jest świadome:
     * szkic bywa już rozpisany na grupy składników, uwagi i minutniki,
     * a ekran dodawania takich pól nie ma. Wciągnięcie go w dwa pola
     * tekstowe skasowałoby po cichu to, co człowiek już wpisał — czyli
     * dokładnie to, czego AGENTS.md §5 zabrania. Tak linkuje `/dodaj`
     * („Dokończ: …") i tak ma zostać.
     */
    public function create(Request $request): View
    {
        $draftId = $request->query('szkic');

        // Str::isUuid, bo kolumna id jest typu uuid — byle jaki tekst
        // w adresie wywaliłby zapytanie, a nie dał czytelnego 404.
        if (is_string($draftId) && Str::isUuid($draftId)) {
            $draft = Recipe::where('status', Recipe::STATUS_DRAFT)->findOrFail($draftId);
            $this->authorize('update', $draft);

            return $this->wizard($request, $draft);
        }

        return view('pages.recipes.create', [
            'kluczWyslania' => $this->kluczDlaFormularza(),
        ]);
    }

    /**
     * Klucz wysłania dla świeżo renderowanego formularza przepisu.
     *
     * `old()` pierwsze: po nieudanej walidacji (za długi tytuł, za duże
     * zdjęcie) formularz wystawia się od nowa i klucz musi zostać ten sam —
     * inaczej ochrona znika po pierwszym błędzie, czyli dokładnie tam, gdzie
     * człowiek klika „Opublikuj" drugi raz.
     */
    private function kluczDlaFormularza(): ?string
    {
        // Wyłącznik awaryjny mechanizmu — `config/kuking.php`, sekcja
        // `formularze` (tam stoi całe uzasadnienie i skutek wyłączenia).
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $stary = old('klucz_wyslania');

        return is_string($stary) && Str::isUuid($stary) ? $stary : (string) Str::uuid7();
    }

    /**
     * Klucz wysłania z żądania. Wartość niebędąca UUID-em schodzi do `null`,
     * czyli do „zapisz normalnie" — zawodzimy otwarcie, nie zamknięcie
     * (ADR §4.3).
     */
    private function kluczZZadania(Request $request): ?string
    {
        // Wyłącznik awaryjny — TA SAMA bramka, co przy renderowaniu
        // formularza. Bez niej wyłącznik działa tylko w połowie: karta
        // otwarta PRZED przełączeniem nadal niesie klucz w DOM-ie i odsyła
        // go, więc częściowy indeks dalej obowiązuje.
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $klucz = $request->input('klucz_wyslania');

        return is_string($klucz) && Str::isUuid($klucz) ? $klucz : null;
    }

    /**
     * „Dopisz szczegóły" w trzech krokach — kreator na ISTNIEJĄCYM przepisie.
     *
     * UUID w adresie to nie autoryzacja: wejście idzie przez Policy, tak samo
     * jak edycja na jednej stronie.
     */
    public function details(Request $request, Recipe $recipe): View
    {
        $this->authorize('update', $recipe);

        return $this->wizard($request, $recipe);
    }

    /**
     * Wszystkie szczegóły na jednej stronie — droga bez JavaScriptu.
     *
     * Adres `/dodaj/przepis/jedna-strona` zostaje, bo ludzie mają go
     * w zakładkach i w historii przeglądarki, a formularz dalej publikuje
     * przepis zwykłym POST-em. Nie jest już jednak DOMYŚLNĄ drogą dodawania
     * i nie linkuje do niego ani `/dodaj`, ani ekran dodawania.
     */
    public function createSimple(): View
    {
        return view('pages.recipes.szczegoly', [
            'units' => Unit::orderBy('name')->get(),
            'recipe' => null,
            'kluczWyslania' => $this->kluczDlaFormularza(),
        ]);
    }

    private function wizard(Request $request, Recipe $recipe): View
    {
        return view('pages.recipes.wizard', [
            'draft' => $recipe,
            'drafts' => $request->user()
                ->recipes()
                ->where('status', Recipe::STATUS_DRAFT)
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        try {
            $heroMediaId = null;

            if ($request->hasFile('hero_photo')) {
                $heroMediaId = $this->storeImage->handle($user, $request->file('hero_photo'))->getKey();
            }

            $scanMediaId = null;

            if ($request->hasFile('source_scan')) {
                $scanMediaId = $this->storeImage->handle($user, $request->file('source_scan'))->getKey();
            }

            $recipe = $this->publishRecipe->handle(
                author: $user,
                attributes: [
                    ...$data['recipe'],
                    'hero_media_id' => $heroMediaId,
                    'source_scan_media_id' => $scanMediaId,
                ],
                ingredients: $data['ingredients'],
                steps: $this->withStepPhotos($request, $user, $data['steps']),
                publish: $request->input('action') !== 'draft',
                ip: $request->ip(),
                kluczWyslania: $this->kluczZZadania($request),
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        /*
         * DRUGIE KLIKNIĘCIE „OPUBLIKUJ" — to jest TEN SAM przepis, nie nowy.
         *
         * `wasRecentlyCreated` jest fałszem, gdy `PublishRecipe` odbiło się
         * o `recipes_one_per_klucz_wyslania` i oddało przepis z pierwszego
         * wysłania. Komunikat mówi to wprost, bo człowiek, który kliknął
         * dwa razy, niepokoi się właśnie o to, czy nie ma teraz dwóch
         * przepisów — i wcześniej naprawdę miał.
         */
        if (! $recipe->wasRecentlyCreated) {
            return redirect()
                ->route($recipe->isPublished() ? 'recipes.show' : 'recipes.edit', $recipe)
                ->with('status', 'Ten przepis już zapisaliśmy — to jest on. Drugie kliknięcie nie założyło drugiego przepisu.');
        }

        if (! $recipe->isPublished()) {
            return redirect()->route('recipes.edit', $recipe)->with('status', 'Szkic zapisany. Możesz wrócić do niego, kiedy chcesz.');
        }

        /*
         * KOMUNIKAT MÓWI O „DOPISZ SZCZEGÓŁY" TYLKO WTEDY, GDY JEST CO
         * DOPISAĆ (D-053 — bez martwego przycisku).
         *
         * Przepis dodany z ekranu sześciu rzeczy ma pustych kilkanaście pól
         * i zaproszenie jest wtedy prawdziwe. Przepis wysłany z pełnego
         * formularza, w którym wypełniono wszystko, nie ma już czego dopisać
         * — i zdanie kierujące go do formularza bez ani jednego pustego pola
         * byłoby tym samym, co przycisk, który po kliknięciu nic nie robi.
         */
        $potwierdzenie = match ($recipe->visibility) {
            'private' => 'Przepis zapisany. Widzisz go tylko Ty.',
            'followers' => 'Przepis opublikowany dla osób, które Cię obserwują.',
            default => 'Przepis opublikowany. Teraz ktoś może z niego ugotować.',
        };

        if (CoMoznaDopisac::jest($recipe)) {
            $potwierdzenie .= ' Możesz jeszcze dopisać szczegóły — wybierz „Dopisz szczegóły”.';
        }

        return redirect()->route('recipes.show', $recipe)->with('status', $potwierdzenie);
    }

    public function edit(Request $request, Recipe $recipe): View
    {
        $this->authorize('update', $recipe);

        return view('pages.recipes.szczegoly', [
            'units' => Unit::orderBy('name')->get(),
            // `steps.media`, bo formularz pokazuje zdjęcie, które krok już ma
            // — bez tego byłoby to jedno zapytanie na wiersz (N+1), czyli
            // dwadzieścia zapytań przy przepisie o dwudziestu krokach.
            'recipe' => $recipe->load(['ingredients', 'steps.media', 'heroMedia']),
        ]);
    }

    public function update(Request $request, Recipe $recipe): RedirectResponse
    {
        $this->authorize('update', $recipe);

        $data = $this->validated($request);
        $user = $request->user();

        try {
            $heroMediaId = $recipe->hero_media_id;

            if ($request->hasFile('hero_photo')) {
                $heroMediaId = $this->storeImage->handle($user, $request->file('hero_photo'))->getKey();
            }

            // Zdjęcia, których ten formularz nie przesłał, MUSZĄ zostać
            // przepisane ręcznie. PublishRecipe zapisuje dokładnie to, co
            // dostanie — pominięcie source_scan_media_id skasowałoby
            // zdjęcie kartki z zeszytu przy pierwszej edycji tytułu.
            $scanMediaId = $recipe->source_scan_media_id;

            if ($request->hasFile('source_scan')) {
                $scanMediaId = $this->storeImage->handle($user, $request->file('source_scan'))->getKey();
            }

            $recipe = $this->publishRecipe->handle(
                author: $user,
                attributes: [
                    ...$data['recipe'],
                    // Pochodzenie przepisu jest od #364 NIEOBOWIĄZKOWE, więc
                    // żądanie bez tego pola nie może po cichu przestawić
                    // „rodzinny" na „mój własny". Brak pola = bez zmiany.
                    'source_type' => $data['recipe']['source_type'] ?? $recipe->source_type,
                    'hero_media_id' => $heroMediaId,
                    'source_scan_media_id' => $scanMediaId,
                ],
                ingredients: $data['ingredients'],
                steps: $this->withStepPhotos($request, $user, $data['steps']),
                publish: $request->input('action') !== 'draft',
                existing: $recipe,
                ip: $request->ip(),
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        return redirect()->route($recipe->isPublished() ? 'recipes.show' : 'recipes.edit', $recipe)
            ->with('status', $recipe->isPublished() ? 'Przepis zapisany.' : 'Szkic zapisany.');
    }

    public function show(Request $request, string $recipe): View|RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->first();

        // Stary adres przepisu musi działać po zmianie tytułu — ktoś mógł
        // go zapisać w zakładkach albo wysłać rodzinie.
        if ($model === null) {
            $redirect = DB::table('recipe_slug_redirects')->where('slug', $recipe)->first();

            if ($redirect === null) {
                abort(404);
            }

            $target = Recipe::findOrFail($redirect->recipe_id);

            // TA SAMA BRAMKA CO POD NOWYM ADRESEM.
            //
            // Autoryzacja stała wcześniej TYLKO w gałęzi „przepis znaleziony
            // pod tym slugiem", więc przekierowanie odsyłało 301 bez pytania
            // o Policy. Slug powstaje z tytułu, więc sam nagłówek `Location`
            // oddawał tytuł przepisu prywatnego albo autora zbanowanego —
            // czyli treść była mniej dostępna przez drzwi frontowe (403) niż
            // przez okno. Zmierzone: `301 → /przepisy/nalewka-na-ziolach-babci-wandy`
            // dla przepisu `visibility = private` oglądanego przez obcego.
            //
            // 404, a nie 403: pod NOWYM adresem odmowa to 403 i tak zostaje,
            // ale tu odpowiadamy dokładnie tym samym, co na slug nieistniejący.
            // 403 potwierdzałoby, że ten stary adres jest znanym
            // przekierowaniem, czyli że przepis o takim tytule istnieje.
            if (! Gate::forUser($request->user())->allows('view', $target)) {
                abort(404);
            }

            return redirect()->route('recipes.show', $target, 301);
        }

        $this->authorize('view', $model);

        $model->load([
            'author.profile.avatar',
            'heroMedia',
            'sourceScan',
            'ingredients.unit',
            'steps.media',
            // Komentarze NIE SĄ tu ładowane (patrz niżej): rosną z popularnością
            // treści bez górnej granicy, więc idą osobnym, paginowanym
            // zapytaniem. `->load()` wciągał je wszystkie naraz.
        ]);

        // Komentarze filtrowane przez blokady (issue #41) — bez tego
        // zablokowana osoba nadal była widoczna pod cudzymi treściami — i od
        // dziś PAGINOWANE. Odpowiedzi jednego wątku dociągamy w całości: mają
        // tylko jeden poziom (`comment-thread.blade.php`) i są ograniczone
        // liczbą osób, które weszły w JEDNĄ rozmowę, a nie popularnością
        // całego przepisu.
        $komentarze = $model->comments()
            ->widoczneDla($request->user())
            ->with([
                'author.profile.avatar',
                'replies' => fn ($query) => $query->widoczneDla($request->user()),
                'replies.author.profile.avatar',
            ])
            ->paginate((int) config('kuking.comments.page_size'), ['*'], 'komentarze');

        // Widoczne dla widza (audyt A4) — bez tego galeria „Komu wyszło"
        // pokazywała każde wykonanie, nie pytając, czy widz zablokował
        // osobę, która ugotowała, albo czy ta osoba zablokowała widza.
        //
        // PAGINOWANE, nie `->limit(12)->get()` (do 7 września 2026). Limit
        // bez paginacji wygląda niewinnie — strona się nie wykłada, zapytanie
        // zostaje jedno — ale dla przepisu z więcej niż 12 wykonaniami
        // wykonania 13. i dalsze są NIEOSIĄGALNE z tego ekranu w ogóle:
        // żadnego przycisku, żadnego adresu, żadnego śladu, że istnieją.
        // Ten sam kształt błędu co T20/N03 dla komentarzy
        // (`KomentarzeStronamiTest`), tylko że tam był chociaż widoczny ślad
        // (nagłówek liczący WSZYSTKIE), a tu i ślad był ucięty — znaczek nad
        // hero liczył uczciwie 30, ale strona nie dawała żadnej drogi do
        // wykonań 13–30. Zmierzone w `KomuWyszloWydajnoscTest`.
        //
        // Nazwa strony `wykonania`, nie `komentarze` — obie paginacje żyją
        // na tym samym ekranie i muszą mieć niezależne parametry adresu.
        $cookedEvents = $model->cookedEvents()
            ->widoczneDla($request->user())
            ->with(['user.profile.avatar', 'media'])
            ->paginate(12, ['*'], 'wykonania')
            ->withQueryString();

        return view('pages.recipes.show', [
            'recipe' => $model,
            'komentarze' => $komentarze,
            // Liczba WSZYSTKICH wątków, nie tylko tych na stronie — inaczej
            // nagłówek „Komentarze (12)" kłamałby pod treścią, która ma ich sto.
            'komentarzyRazem' => $komentarze->total(),
            'cookedEvents' => $cookedEvents,
            // LICZNIK LICZY DOKŁADNIE TO, CO POKAZUJE GALERIA WYŻEJ.
            //
            // `$cookedEvents->total()`, nie osobne `->count()` — do 7 września
            // 2026 stało tu gołe zapytanie, dziesięć linijek pod galerią, która
            // filtr `widoczneDla()` miała od audytu A4. Reguła była więc
            // w warstwie LISTY i nie było jej w warstwie LICZBY. Zmierzone
            // przy dwóch wykonaniach, z których jedno należało do osoby
            // zablokowanej przez widza: galeria pokazywała jedną kartę,
            // a znaczek nad nią „Ugotowane 2 ×" i JSON-LD
            // `"userInteractionCount":2`. Czyli sama strona meldowała widzowi,
            // że osoba, którą zablokował, ugotowała ten przepis — ten sam
            // „oracle istnienia" co zamknięte W7-05 i co licznik obserwujących
            // na profilu. Czytanie z paginatora usuwa też drugie, zbędne
            // zapytanie `count()` — ten sam `total()`, który i tak liczy
            // Laravel budując stronę.
            //
            // Skutek świadomy: liczba jest per widz, tak jak per widz jest już
            // galeria. Dla gościa `widoczneDla(null)` nie filtruje niczego,
            // więc dane dla wyszukiwarek zostają bez zmian.
            'cookedCount' => $cookedEvents->total(),
            // C4: „10 z 12 osób zrobi to ponownie" (SOUL 4.2). Ta odpowiedź
            // była zbierana od początku i wyrzucana — nigdzie nie agregowana.
            // To jedyna miara jakości przepisu, na jaką się zgodziliśmy:
            // gwiazdek nie ma i nie będzie, bo są abstrakcją, a zdanie
            // „dziesięć z dwunastu osób zrobi to ponownie" rozumie każdy.
            // Czy oglądający obserwuje autora — jedno zapytanie, żeby przycisk
            // „Obserwuj" na stronie przepisu pokazywał prawdziwy stan
            // (UI kit v2, ekran 02).
            'obserwuje' => $request->user() !== null
                && $request->user()->getKey() !== $model->author_id
                && $request->user()->isFollowing($model->author),
            // Oba liczniki opinii — ta sama granica co przy `cookedCount`
            // wyżej. Bez niej znaczek pisał „3 z 4 osób zrobi to ponownie"
            // przy trzech widocznych wykonaniach (zmierzone), czyli zdradzał
            // istnienie czwartego i JESZCZE jego odpowiedź.
            'zrobiaPonownie' => $model->cookedEvents()
                ->widoczneDla($request->user())
                ->where('would_make_again', true)
                ->count(),
            'oceniloWykonanie' => $model->cookedEvents()
                ->widoczneDla($request->user())
                ->whereNotNull('would_make_again')
                ->count(),
            'isSaved' => $request->user() !== null && $request->user()
                ->collections()
                ->whereHas('recipes', fn ($query) => $query->whereKey($model->getKey()))
                ->exists(),
        ]);
    }

    public function comment(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'parent_id' => ['nullable', 'uuid'],
        ], [
            'body.required' => 'Napisz coś, zanim wyślesz komentarz.',
            // TEN SAM KOMUNIKAT CO POD WPISEM. Bez tej linii zostawał
            // domyślny tekst frameworka („Pole «treść» jest za długie — może
            // mieć najwyżej 4000 znaków"), czyli inny głos i inne słowo na
            // to samo pole na sąsiednim ekranie.
            'body.max' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.',
        ]);

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $model,
                body: $data['body'],
                // `?? null`, bo `validate()` NIE zwraca klucza, którego
                // w żądaniu nie było — a `parent_id` jest `nullable`.
                // Komentarz wysłany bez tego pola (czyli każdy spoza naszego
                // formularza, który zawsze wysyła puste) kończył się błędem
                // „Undefined array key", czyli 500 zamiast komentarza.
                //
                // `widoczneDla()` — audyt W7-06. Bez tego można było podać
                // UUID komentarza ukrytego przez blokadę i podpiąć się pod
                // cudzy wątek. Akcja domenowa sprawdza to drugi raz, bo
                // kontrolerów jest kilka.
                parent: ($data['parent_id'] ?? null) === null
                    ? null
                    : $model->comments()
                        ->widoczneDla($request->user())
                        ->whereKey($data['parent_id'])
                        ->first(),
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('status', 'Komentarz dodany.');
    }

    public function destroy(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('delete', $model);

        $model->delete();

        return redirect()->route('home')->with('status', 'Przepis usunięty.');
    }

    /**
     * @return array{recipe: array<string, mixed>, ingredients: list<array<string, mixed>>, steps: array<array-key, array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:'.LimityTekstuPrzepisu::POLA['title']],
            'summary' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['summary']],
            'servings' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'visibility' => ['required', 'in:public,followers,private'],
            /*
             * `nullable`, nie `required` (issue #364). Ekran dodawania nie
             * pyta „ten przepis jest…" — to jedno z dziewięciu kółek wyboru,
             * które z niego wyleciały. Brak pola znaczy „mój własny"
             * (`PublishRecipe` stawia `Recipe::SOURCE_OWN`), a formularz
             * szczegółów pyta dalej i dalej przysyła wartość.
             */
            'source_type' => ['nullable', 'in:own,family,adaptation,external'],
            'source_person' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['source_person']],
            'source_note' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['source_note']],
            'source_url' => ['nullable', 'url', 'max:'.LimityTekstuPrzepisu::POLA['source_url']],
            'family_since_year' => ['nullable', 'integer', 'min:1850', 'max:2100'],
            'hero_photo' => ['nullable', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'source_scan' => ['nullable', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'ingredients' => ['nullable', 'array', 'max:'.Recipe::MAX_INGREDIENTS],
            'ingredients.*.text' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['ingredients.*.text']],
            'ingredients.*.group_name' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['ingredients.*.group_name']],
            'ingredients.*.note' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['ingredients.*.note']],
            // „Bez ilości” — sól do smaku, mleko ile weźmie (issue #44).
            // Pole wysyła zwykły checkbox, więc przychodzi jako "1" albo
            // nie przychodzi wcale.
            'ingredients.*.no_amount' => ['nullable', 'boolean'],
            'steps' => ['nullable', 'array', 'max:'.Recipe::MAX_STEPS],
            // TOŻSAMOŚĆ KROKU, przenoszona przez POST w ukrytym polu.
            //
            // `uuid`, bo kolumna `recipe_steps.id` jest typu uuid — byle jaki
            // tekst wywaliłby zapytanie zamiast dać komunikat. To pole NIE
            // JEST autoryzacją: `PublishRecipe` dopasowuje je wyłącznie do
            // kroków tego przepisu, więc cudzy identyfikator nic nie daje.
            'steps.*.id' => ['nullable', 'uuid'],
            'steps.*.instruction' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['steps.*.instruction']],
            // Człowiek wpisuje MINUTY, bo tak myśli o gotowaniu. Sekundy
            // (`recipe_steps.timer_seconds`, `data-timer-sekundy` w trybie
            // gotowania) liczy `StepTimer` w warstwie domenowej — tu stoi
            // tylko ta sama granica, żeby błąd trafił PRZY POLU, a nie
            // wyjątkiem nad całym formularzem.
            'steps.*.timer_minutes' => ['nullable', 'integer', 'min:0', 'max:'.StepTimer::MAX_MINUTES],
            // Zdjęcie kroku idzie DOKŁADNIE tą samą drogą co każde inne
            // zdjęcie w tym serwisie: `ObslugiwaneZdjecie` w walidacji,
            // `StoreUploadedImage` w zapisie, ten sam limit rozmiaru.
            'steps.*.photo' => ['nullable', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'steps.*.remove_photo' => ['nullable', 'boolean'],

            /*
             * DWA POLA Z EKRANU DODAWANIA (issue #364).
             *
             * Wchodzą TYM SAMYM POST-em co tablice `ingredients` i `steps`
             * z formularza szczegółów i nie kłócą się z nimi: rozstrzyga to,
             * które pole W OGÓLE PRZYSZŁO w żądaniu (niżej). Dzięki temu
             * jedna trasa `recipes.store` obsługuje oba ekrany i obie kończą
             * w tej samej akcji domenowej.
             *
             * Granice są wysokie celowo. Nie są miarą tego, „ile przepis
             * powinien mieć" — od tego są `Recipe::MAX_INGREDIENTS`
             * i `MAX_STEPS`, sprawdzane po rozbiciu na wiersze i mówiące
             * wprost, ile wierszy jest za dużo. Te dwie liczby mają tylko
             * odciąć wklejenie całej książki kucharskiej, zanim zacznie
             * chodzić parser.
             */
            'skladniki_tekst' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['skladniki_tekst']],
            'przygotowanie_tekst' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['przygotowanie_tekst']],
        ], [
            'title.required' => 'Podaj nazwę przepisu — na przykład „Rosół babci Zofii”.',
            'title.min' => 'Nazwa przepisu musi mieć co najmniej 3 znaki. Dopisz kilka liter.',
            'title.max' => 'Nazwa przepisu jest za długa. Skróć ją do 180 znaków.',
            'summary.max' => 'Krótki opis jest za długi. Zostaw najwyżej 2000 znaków — resztę wpisz w historii przepisu.',
            'servings.numeric' => 'Liczba porcji musi być liczbą. Wpisz na przykład 4.',
            'servings.min' => 'Liczba porcji musi być większa od zera. Wpisz na przykład 4.',
            'servings.max' => 'Ta liczba porcji jest nierealna. Wpisz najwyżej 999.',
            'prep_minutes.integer' => 'Czas przygotowania podaj w pełnych minutach, na przykład 20.',
            'prep_minutes.min' => 'Czas przygotowania nie może być ujemny. Wpisz na przykład 20.',
            'prep_minutes.max' => 'Czas przygotowania jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
            'cook_minutes.integer' => 'Czas gotowania podaj w pełnych minutach, na przykład 90.',
            'cook_minutes.min' => 'Czas gotowania nie może być ujemny. Wpisz na przykład 90.',
            'cook_minutes.max' => 'Czas gotowania jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest nieprawidłowa"
            // (issue #86) — a te dwa pola akurat renderują się jako <select>,
            // więc zdanie jest tym samym, co widać na ekranie.
            'difficulty.in' => 'Wybierz poziom trudności: łatwy, średni albo trudny.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten przepis.',
            'visibility.in' => 'Zaznacz, kto ma widzieć ten przepis: wszyscy, obserwujący czy tylko Ty.',
            'source_type.required' => 'Zaznacz, skąd jest ten przepis.',
            'source_type.in' => 'Zaznacz, skąd jest ten przepis: Twój własny, rodzinny, adaptacja czy z zewnątrz.',
            'source_person.max' => 'To pole jest za długie. Zostaw najwyżej 120 znaków — wystarczy krótka wzmianka, na przykład „od mamy”.',
            'source_note.max' => 'Historia przepisu jest za długa. Zostaw najwyżej 2000 znaków.',
            'source_url.url' => 'Ten adres strony wygląda na niepełny. Wklej go jeszcze raz z paska przeglądarki — powinien zaczynać się od https://',
            // Te trzy komunikaty są celowo IDENTYCZNE jak w komponencie
            // `recipe-wizard` (droga z JavaScriptem) — to jest ten sam
            // formularz na jednej stronie, więc ma mówić to samo (issue #86,
            // przykład z treści zgłoszenia: „The family since year field
            // must be at least 1850").
            'family_since_year.integer' => 'Rok wpisz czterema cyframi, na przykład 1974.',
            'family_since_year.min' => 'Ten rok jest za wczesny. Wpisz rok od 1850.',
            'family_since_year.max' => 'Ten rok jest za późny. Wpisz rok do 2100.',
            'hero_photo.image' => 'Zdjęcie główne musi być plikiem JPG, PNG lub WebP.',
            // Wcześniej brakowało tych komunikatów — za duży plik pokazywał
            // domyślny, angielski błąd Laravela (narusza AGENTS.md).
            'hero_photo.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'source_scan.max' => LimityZdjec::komunikatZaDuzyPlik(),
            // Komunikaty minutnika są WSPÓLNE z warstwą domenową
            // (`StepTimer::KOMUNIKAT_*`), a nie przepisane drugi raz. Ta sama
            // wartość odrzucona przez formularz i przez akcję domenową musi
            // mówić to samo zdanie — inaczej człowiek widzi dwa różne
            // tłumaczenia jednej reguły, zależnie od tego, którą drogą szedł.
            'steps.*.timer_minutes.integer' => StepTimer::KOMUNIKAT_NIE_LICZBA,
            'steps.*.timer_minutes.min' => StepTimer::KOMUNIKAT_UJEMNY,
            'steps.*.timer_minutes.max' => StepTimer::KOMUNIKAT_ZA_DUZO,
            'steps.*.photo.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'skladniki_tekst.max' => 'Lista składników jest bardzo długa. Zostaw najwyżej 30 000 znaków — resztę dopisz po opublikowaniu.',
            'przygotowanie_tekst.max' => 'Opis przygotowania jest bardzo długi. Zostaw najwyżej 120 000 znaków — resztę dopisz po opublikowaniu.',
        ]);

        // BUDŻET ZDJĘĆ KROKÓW — sprawdzany PRZED wgraniem czegokolwiek.
        //
        // Bez tego nadmiarowe zdjęcia albo znikałyby bez słowa (PHP obcina
        // części żądania po `max_file_uploads`), albo całe żądanie odpadałoby
        // na `post_max_size` razem z tokenem CSRF i całym wpisanym tekstem
        // (audyt A31). Limit MUSI więc powiedzieć, co zrobić, i musi to
        // powiedzieć, zanim zaczniemy cokolwiek zapisywać.
        $zdjeciaKrokow = 0;

        foreach (array_keys($data['steps'] ?? []) as $index) {
            if ($request->hasFile("steps.{$index}.photo")) {
                $zdjeciaKrokow++;
            }
        }

        if ($zdjeciaKrokow > LimityZdjec::maksZdjecKrokowNaZapis()) {
            throw ValidationException::withMessages([
                'steps' => LimityZdjec::komunikatZaDuzoZdjecKrokow(),
            ]);
        }

        /*
         * SKŁADNIKI I KROKI — Z JEDNEGO POLA TEKSTOWEGO ALBO Z WIERSZY.
         *
         * Rozstrzyga OBECNOŚĆ pola w żądaniu, nie jego pustość. Ekran
         * dodawania wysyła `skladniki_tekst` zawsze, także pusty — i pusty
         * ma znaczyć „bez składników", bo właściciel zgodził się na przepis
         * bez ani jednego (#364). Gdyby rozstrzygała pustość, wyczyszczenie
         * pola po cichu zostawiałoby stare wiersze i przepis kłamałby listą,
         * której autor już nie widzi.
         *
         * Formularz szczegółów tych dwóch pól nie ma w ogóle, więc idzie
         * drugą gałęzią — tą samą, co przed #364, co do wiersza.
         */
        $zTekstu = $request->exists('skladniki_tekst');
        $krokiZTekstu = $request->exists('przygotowanie_tekst');

        if ($zTekstu) {
            $ingredients = TekstNaWiersze::skladniki($data['skladniki_tekst'] ?? null);

            if (count($ingredients) > Recipe::MAX_INGREDIENTS) {
                throw ValidationException::withMessages([
                    'skladniki_tekst' => 'To bardzo dużo składników — zmieść się w '
                        .Recipe::MAX_INGREDIENTS.' wierszach. Sprawdź, czy nie trafił tu przez pomyłkę opis przygotowania.',
                ]);
            }
        } else {
            $ingredients = array_values(array_map(
                static fn (array $row): array => [
                    'text' => $row['text'] ?? '',
                    'group_name' => $row['group_name'] ?? null,
                    'note' => $row['note'] ?? null,
                    'no_amount' => (bool) ($row['no_amount'] ?? false),
                ],
                $data['ingredients'] ?? [],
            ));
        }

        if ($krokiZTekstu) {
            $steps = array_map(
                static fn (array $row): array => [
                    'id' => null,
                    'instruction' => $row['instruction'],
                    'timer_minutes' => null,
                    'media_id' => null,
                    'remove_media' => false,
                ],
                TekstNaWiersze::kroki($data['przygotowanie_tekst'] ?? null),
            );

            /*
             * BŁĄD PRZY POLU, A NIE NAD CAŁYM FORMULARZEM (AGENTS.md §5).
             *
             * Bez tego pusty opis przygotowania wracał z `PublishRecipe`
             * jako `BladDlaCzlowieka` i lądował pod kluczem `title` — czyli
             * zdanie „Opisz przynajmniej jeden krok" świeciło na czerwono
             * przy NAZWIE przepisu, którą człowiek wypełnił poprawnie.
             * Reguła zostaje ta sama i dalej pilnuje jej akcja domenowa;
             * tu stoi tylko po to, żeby komunikat trafił tam, gdzie jest
             * robota do zrobienia.
             */
            if ($steps === [] && $request->input('action') !== 'draft') {
                throw ValidationException::withMessages([
                    'przygotowanie_tekst' => 'Napisz, co się po kolei robi — bez tego nikt nie ugotuje tego przepisu. Wystarczy jedno zdanie.',
                ]);
            }

            if (count($steps) > Recipe::MAX_STEPS) {
                throw ValidationException::withMessages([
                    'przygotowanie_tekst' => 'To bardzo dużo kroków — zmieść się w '
                        .Recipe::MAX_STEPS.' krokach. Pusta linijka zaczyna nowy krok, więc sprawdź, czy nie ma ich za dużo.',
                ]);
            }
        } else {
            // KLUCZE ZOSTAJĄ TAKIE, JAK W ŻĄDANIU — bez `array_values()`.
            // Po nich `withStepPhotos()` szuka pliku (`steps.3.photo`)
            // i po nich adresuje komunikat błędu, a widok wypisuje go przez
            // `@error("steps.3.photo")` z numerem WIERSZA FORMULARZA. Gdyby
            // klucze zostały tu przenumerowane, plik z wiersza o numerze
            // nieciągłym nie zostałby znaleziony, a błąd wylądowałby pod
            // cudzym wierszem. Kolejność zapisu bierze się z kolejności
            // elementów tablicy, nie z wartości kluczy, więc numeracja
            // pozycji w bazie na tym nie traci.
            $steps = array_map(
                static fn (array $row): array => [
                    'id' => $row['id'] ?? null,
                    'instruction' => $row['instruction'] ?? '',
                    'timer_minutes' => $row['timer_minutes'] ?? null,
                    // ZAWSZE null: identyfikator zdjęcia NIE JEST polem tego
                    // formularza i nie ma go w regułach walidacji wyżej.
                    // Wypełnia go wyłącznie `withStepPhotos()` — z pliku,
                    // który naprawdę przyszedł w TYM żądaniu. Ukryte pole
                    // z `media_id` dałoby klientowi możliwość podania cudzego
                    // identyfikatora; tożsamość, której formularz potrzebuje,
                    // niesie `id` KROKU, a to jest dopasowywane wyłącznie
                    // wewnątrz tego przepisu.
                    'media_id' => null,
                    'remove_media' => (bool) ($row['remove_photo'] ?? false),
                ],
                $data['steps'] ?? [],
            );
        }

        return [
            'recipe' => [
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'servings' => $data['servings'] ?? null,
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'cook_minutes' => $data['cook_minutes'] ?? null,
                'difficulty' => $data['difficulty'] ?? null,
                'visibility' => $data['visibility'],
                'source_type' => $data['source_type'] ?? null,
                'source_person' => $data['source_person'] ?? null,
                'source_note' => $data['source_note'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'family_since_year' => $data['family_since_year'] ?? null,
            ],
            'ingredients' => $ingredients,
            'steps' => $steps,
        ];
    }

    /**
     * Wgranie zdjęć kroków — po jednym na wiersz, tą samą drogą co każde inne
     * zdjęcie w serwisie.
     *
     * ZDJĘCIE WGRYWAMY TYLKO DLA WIERSZA, KTÓRY MA TREŚĆ. Wiersz bez opisu
     * kroku jest pomijany przy zapisie (`PublishRecipe::cleanSteps`), więc
     * zdjęcie do niego dołączone byłoby wierszem w `media`, do którego nic
     * nie prowadzi — śmieciem w buckecie i w eksporcie RODO tego człowieka.
     *
     * Wchodzi tablica O KLUCZACH Z ŻĄDANIA, wychodzi zwykła lista — od tego
     * miejsca numery wierszy formularza nie są już do niczego potrzebne.
     *
     * @param  array<array-key, array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException gdy zdjęcie odpadnie — komunikat trafia
     *                             PRZY POLE tego kroku, nie nad formularzem
     */
    private function withStepPhotos(Request $request, User $user, array $steps): array
    {
        foreach ($steps as $index => $row) {
            if (! $request->hasFile("steps.{$index}.photo")) {
                continue;
            }

            if (trim((string) ($row['instruction'] ?? '')) === '') {
                continue;
            }

            try {
                $steps[$index]['media_id'] = $this->storeImage
                    ->handle($user, $request->file("steps.{$index}.photo"))
                    ->getKey();
            } catch (BladDlaCzlowieka $e) {
                throw ValidationException::withMessages([
                    "steps.{$index}.photo" => $e->getMessage(),
                ]);
            }
        }

        return array_values($steps);
    }
}
