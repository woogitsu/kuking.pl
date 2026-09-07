<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\Unit;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Przepisy.
 *
 * Dodawanie przepisu ma DWIE drogi i obie są prawdziwe:
 *
 *  1. `/dodaj/przepis` — kreator trzykrokowy z autosave'em szkicu
 *     (komponent Livewire `recipe-wizard`, docs/FLOWS_AND_SCREENS.md).
 *     Wymaga JavaScriptu.
 *  2. `/dodaj/przepis/jedna-strona` — ten sam formularz na jednej stronie,
 *     zwykły POST, zero JavaScriptu. To NIE jest ustępstwo ani zaszłość:
 *     przy słabym zasięgu skrypt się nie dociąga, a użytkownik zostaje
 *     z martwym formularzem (AGENTS.md → „JavaScript jest ulepszeniem”).
 *
 * Obie drogi kończą się w tej samej akcji domenowej `PublishRecipe`, więc
 * reguły („szkic da się zapisać z samym tytułem”, „publikacja wymaga
 * składnika i kroku”) są jedne, nie dwie.
 */
class RecipeController extends Controller
{
    public function __construct(
        private readonly PublishRecipe $publishRecipe,
        private readonly StoreUploadedImage $storeImage,
        private readonly PublishComment $publishComment,
    ) {}

    /**
     * Kreator trzykrokowy. `?szkic={uuid}` wraca do niedokończonego szkicu.
     */
    public function create(Request $request): View
    {
        $draftId = $request->query('szkic');
        $draft = null;

        // Str::isUuid, bo kolumna id jest typu uuid — byle jaki tekst
        // w adresie wywaliłby zapytanie, a nie dał czytelnego 404.
        if (is_string($draftId) && Str::isUuid($draftId)) {
            $draft = Recipe::where('status', Recipe::STATUS_DRAFT)->findOrFail($draftId);
            $this->authorize('update', $draft);
        }

        return view('pages.recipes.wizard', [
            'draft' => $draft,
            'drafts' => $request->user()
                ->recipes()
                ->where('status', Recipe::STATUS_DRAFT)
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * Formularz na jednej stronie — droga bez JavaScriptu.
     */
    public function createSimple(): View
    {
        return view('pages.recipes.create', [
            'units' => Unit::orderBy('name')->get(),
            'recipe' => null,
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
                steps: $data['steps'],
                publish: $request->input('action') !== 'draft',
                ip: $request->ip(),
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        if (! $recipe->isPublished()) {
            return redirect()->route('recipes.edit', $recipe)->with('status', 'Szkic zapisany. Możesz wrócić do niego, kiedy chcesz.');
        }

        return redirect()->route('recipes.show', $recipe)->with('status',
            'Przepis opublikowany. Teraz ktoś może z niego ugotować.',
        );
    }

    public function edit(Request $request, Recipe $recipe): View
    {
        $this->authorize('update', $recipe);

        return view('pages.recipes.create', [
            'units' => Unit::orderBy('name')->get(),
            'recipe' => $recipe->load(['ingredients', 'steps', 'heroMedia']),
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
                    'hero_media_id' => $heroMediaId,
                    'source_scan_media_id' => $scanMediaId,
                ],
                ingredients: $data['ingredients'],
                steps: $data['steps'],
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
            // Komentarze filtrowane przez blokady (issue #41). Bez tego
            // zablokowana osoba nadal była widoczna pod cudzymi treściami.
            'comments' => fn ($query) => $query->widoczneDla($request->user()),
            'comments.author.profile.avatar',
            'comments.replies' => fn ($query) => $query->widoczneDla($request->user()),
            'comments.replies.author.profile.avatar',
        ]);

        return view('pages.recipes.show', [
            'recipe' => $model,
            // Widoczne dla widza (audyt A4) — bez tego galeria „Komu wyszło"
            // pokazywała każde wykonanie, nie pytając, czy widz zablokował
            // osobę, która ugotowała, albo czy ta osoba zablokowała widza.
            'cookedEvents' => $model->cookedEvents()
                ->widoczneDla($request->user())
                ->with(['user.profile.avatar', 'media'])
                ->limit(12)
                ->get(),
            // LICZNIK LICZY DOKŁADNIE TO, CO POKAZUJE GALERIA WYŻEJ.
            //
            // Stało tu gołe `count()` na całej relacji, dziesięć linijek pod
            // galerią, która filtr `widoczneDla()` miała od audytu A4. Reguła
            // była więc w warstwie LISTY i nie było jej w warstwie LICZBY.
            // Zmierzone przy dwóch wykonaniach, z których jedno należało do
            // osoby zablokowanej przez widza: galeria pokazywała jedną kartę,
            // a znaczek nad nią „Ugotowane 2 ×" i JSON-LD
            // `"userInteractionCount":2`. Czyli sama strona meldowała widzowi,
            // że osoba, którą zablokował, ugotowała ten przepis — ten sam
            // „oracle istnienia" co zamknięte W7-05 i co licznik obserwujących
            // na profilu.
            //
            // Skutek świadomy: liczba jest per widz, tak jak per widz jest już
            // galeria. Dla gościa `widoczneDla(null)` nie filtruje niczego,
            // więc dane dla wyszukiwarek zostają bez zmian.
            'cookedCount' => $model->cookedEvents()->widoczneDla($request->user())->count(),
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
     * @return array{recipe: array<string, mixed>, ingredients: list<array<string, mixed>>, steps: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'servings' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'visibility' => ['required', 'in:public,followers,private'],
            'source_type' => ['required', 'in:own,family,adaptation,external'],
            'source_person' => ['nullable', 'string', 'max:120'],
            'source_note' => ['nullable', 'string', 'max:2000'],
            'source_url' => ['nullable', 'url', 'max:2000'],
            'family_since_year' => ['nullable', 'integer', 'min:1850', 'max:2100'],
            'hero_photo' => ['nullable', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'source_scan' => ['nullable', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'ingredients' => ['nullable', 'array', 'max:120'],
            'ingredients.*.text' => ['nullable', 'string', 'max:240'],
            'ingredients.*.group_name' => ['nullable', 'string', 'max:120'],
            'ingredients.*.note' => ['nullable', 'string', 'max:300'],
            // „Bez ilości” — sól do smaku, mleko ile weźmie (issue #44).
            // Pole wysyła zwykły checkbox, więc przychodzi jako "1" albo
            // nie przychodzi wcale.
            'ingredients.*.no_amount' => ['nullable', 'boolean'],
            'steps' => ['nullable', 'array', 'max:60'],
            'steps.*.instruction' => ['nullable', 'string', 'max:4000'],
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
            'source_person.max' => 'To pole jest za długie. Zostaw najwyżej 120 znaków — samo imię wystarczy.',
            'source_note.max' => 'Historia przepisu jest za długa. Zostaw najwyżej 2000 znaków.',
            'source_url.url' => 'Ten adres strony wygląda na niepełny. Powinien zaczynać się od https://',
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
        ]);

        return [
            'recipe' => [
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'servings' => $data['servings'] ?? null,
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'cook_minutes' => $data['cook_minutes'] ?? null,
                'difficulty' => $data['difficulty'] ?? null,
                'visibility' => $data['visibility'],
                'source_type' => $data['source_type'],
                'source_person' => $data['source_person'] ?? null,
                'source_note' => $data['source_note'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'family_since_year' => $data['family_since_year'] ?? null,
            ],
            'ingredients' => array_values(array_map(
                static fn (array $row): array => [
                    'text' => $row['text'] ?? '',
                    'group_name' => $row['group_name'] ?? null,
                    'note' => $row['note'] ?? null,
                    'no_amount' => (bool) ($row['no_amount'] ?? false),
                ],
                $data['ingredients'] ?? [],
            )),
            'steps' => array_values(array_map(
                static fn (array $row): array => ['instruction' => $row['instruction'] ?? ''],
                $data['steps'] ?? [],
            )),
        ];
    }
}
