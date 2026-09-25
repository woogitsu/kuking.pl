<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Comments\OdpowiedziWatku;
use App\Domain\Recipes\Actions\ZapiszPrzepisZFormularza;
use App\Domain\Recipes\CoMoznaDopisac;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Requests\Recipes\ZapisPrzepisuRequest;
use App\Models\Recipe;
use App\Models\Unit;
use App\Support\PaginationLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
 * (`App\Domain\Recipes\TekstNaWiersze`). Szukanie po składnikach nadal
 * czyta te same wiersze. Skalowanie porcji pozostaje niewdrożonym planem V2.
 *
 * Wszystkie drogi kończą się w tej samej akcji domenowej `PublishRecipe`
 * (formularze bez JavaScriptu przez `ZapiszPrzepisZFormularza`, walidacja
 * w `ZapisPrzepisuRequest` — issue #970),
 * więc reguły („szkic da się zapisać z samym tytułem”, „publikacja wymaga
 * kroku, ale NIE wymaga składnika” — zgoda właściciela z #364) są jedne,
 * nie trzy.
 */
class RecipeController extends Controller
{
    public function __construct(
        private readonly ZapiszPrzepisZFormularza $zapiszPrzepis,
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
    public function details(Request $request, Recipe $recipe): View|RedirectResponse
    {
        $this->authorize('update', $recipe);

        // Nazwa szkicu zmienia slug. Kolejne żądania Livewire potrzebują stałego adresu.
        if ($recipe->status === Recipe::STATUS_DRAFT) {
            return redirect()->route('recipes.create', ['szkic' => $recipe->getKey()]);
        }

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

    /** Prywatna lista autora; skróty na „Dodaj” nie zastępują dostępu do starszych szkiców. */
    public function drafts(Request $request): View
    {
        $drafts = $request->user()->recipes()
            ->where('status', Recipe::STATUS_DRAFT)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate(20);
        foreach ($drafts as $draft) {
            $this->authorize('view', $draft);
        }

        return view('pages.recipes.drafts', ['drafts' => $drafts]);
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

    public function store(ZapisPrzepisuRequest $request): RedirectResponse
    {
        $data = $request->daneZapisu();

        try {
            $recipe = $this->zapiszPrzepis->handle(
                author: $request->user(),
                dane: $data,
                zdjecieGlowne: $request->przeslanyPlik('hero_photo'),
                skan: $request->przeslanyPlik('source_scan'),
                zdjeciaKrokow: $request->zdjeciaKrokow($data['steps']),
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

    public function update(ZapisPrzepisuRequest $request, Recipe $recipe): RedirectResponse
    {
        // Policy sprawdza już `ZapisPrzepisuRequest::authorize()` — przed
        // walidacją, jak dotąd. Zostaje też tutaj, żeby wejście było widać
        // w kontrolerze i żeby przeniesienie walidacji go nie zgubiło.
        $this->authorize('update', $recipe);

        $data = $request->daneZapisu();

        try {
            $recipe = $this->zapiszPrzepis->handle(
                author: $request->user(),
                dane: $data,
                zdjecieGlowne: $request->przeslanyPlik('hero_photo'),
                skan: $request->przeslanyPlik('source_scan'),
                zdjeciaKrokow: $request->zdjeciaKrokow($data['steps']),
                publish: $request->input('action') !== 'draft',
                ip: $request->ip(),
                existing: $recipe,
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
        // dziś PAGINOWANE. Odpowiedzi jednego wątku też idą porcjami
        // (issue #939): nic nie ogranicza, ile razy ta sama osoba odpowie,
        // więc „ograniczone liczbą osób w rozmowie” nie było prawdą.
        $komentarze = $model->comments()
            ->widoczneDla($request->user())
            ->with([
                'author.profile.avatar',
                'replies' => fn ($query) => OdpowiedziWatku::pierwszaPorcja($query, $request->user()),
                'replies.author.profile.avatar',
                // TO NIE JEST NADMIAROWE, CHOĆ PRZEPIS STOI OBOK W `$model`.
                //
                // Pod każdym komentarzem i każdą odpowiedzią widok pyta
                // `@can('delete', $comment)`. `CommentPolicy::delete()` woła
                // `Comment::notifiableUserId()`, a ta `Comment::subject()`,
                // czyli `$this->post ?? $this->recipe ?? $this->cookedEvent`.
                // Relacja nie była doładowana, więc KAŻDY komentarz szedł po
                // swój przepis osobnym zapytaniem — mimo że wszystkie
                // komentarze na tej stronie dotyczą jednego, już wczytanego.
                //
                // Zmierzone (`scripts/pomiar-n1.php`, 10 000 wpisów, po
                // `ANALYZE`): 41 zapytań przy 5 komentarzach na stronie, 61 przy
                // 15 i 81 przy 25 — jedno na komentarz i jedno na odpowiedź.
                // Dwie linijki niżej zamieniają to na dwa zapytania niezależne
                // od liczby komentarzy: 33 przy każdym rozmiarze strony.
                'recipe',
                'replies.recipe',
            ])
            ->paginate((int) config('kuking.comments.page_size'), ['*'], 'komentarze');
        OdpowiedziWatku::uzupelnij($komentarze, $request, ['author.profile.avatar', 'recipe']);

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
            ->paginate(12, ['*'], 'wykonania');

        PaginationLinks::preserveOtherPage($komentarze, $cookedEvents);
        PaginationLinks::preserveOtherPage($cookedEvents, $komentarze);

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
            // #666: każde wykonanie może mieć osobną odpowiedź, także od tej samej osoby.
            // Liczniki opisujemy jako wykonania i odpowiedzi, bez deduplikacji kucharzy.
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
            // ZESZYTY, W KTÓRYCH TEN PRZEPIS LEŻY — nie samo „tak/nie" (issue #775).
            //
            // Sam `isSaved` nie wystarczał ekranowi do niczego poza podmianą
            // napisu na przycisku. Wyjęcie potrzebuje wiedzieć WIĘCEJ: gdy
            // zeszyt jest jeden, formularz może wskazać go wprost (`collection_id`)
            // i wtedy nic poza nim nie zostanie ruszone; gdy jest ich kilka,
            // przycisk musi napisać, że zdejmuje ze wszystkich, zanim ktoś
            // w niego kliknie. Jedno zapytanie, dwie nazwane kolumny.
            'zeszytyZPrzepisem' => $request->user() === null
                ? collect()
                : $request->user()
                    ->collections()
                    ->whereHas('recipes', fn ($query) => $query->whereKey($model->getKey()))
                    ->orderBy('name')
                    ->get(['collections.id', 'collections.name']),
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

        // `?? null`, bo `validate()` NIE zwraca klucza, którego w żądaniu nie
        // było — a `parent_id` jest `nullable`. Komentarz wysłany bez tego
        // pola (czyli każdy spoza naszego formularza, który zawsze wysyła
        // puste) kończył się błędem „Undefined array key", czyli 500 zamiast
        // komentarza.
        $parentId = $data['parent_id'] ?? null;

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $model,
                body: $data['body'],
                // `widoczneDla()` — audyt W7-06. Bez tego można było podać
                // UUID komentarza ukrytego przez blokadę i podpiąć się pod
                // cudzy wątek. Akcja domenowa sprawdza to drugi raz, bo
                // kontrolerów jest kilka.
                parent: $parentId === null
                    ? null
                    : $model->comments()
                        ->widoczneDla($request->user())
                        ->whereKey($parentId)
                        ->first(),
                // ISSUE #761: patrz komentarz przy tym samym parametrze
                // w PostController::comment().
                parentRequested: $parentId !== null,
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
}
