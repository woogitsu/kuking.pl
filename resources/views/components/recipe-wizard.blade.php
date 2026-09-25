<?php

declare(strict_types=1);

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\GrupySkladnikow;
use App\Domain\Recipes\StepTimer;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Kreator przepisu — trzy kroki plus podgląd.
 *
 *   1 z 3  o przepisie   →  2 z 3  składniki  →  3 z 3  przygotowanie
 *                        →  podgląd  →  Opublikuj
 *
 * ZASADA NADRZĘDNA (docs/ROADMAP.md, punkt 5): przerwanie kreatora nie może
 * skasować niczego, co człowiek już wpisał. Dlatego:
 *
 *  - szkic zapisuje się po każdym kroku ORAZ po ~3 s bezczynności w polu
 *    (wire:model.live.debounce.3000ms → hook updated() → saveDraft()),
 *  - jeden szkic na całą sesję kreatora: pierwszy zapis tworzy przepis,
 *    każdy następny go aktualizuje (dlatego $recipeId jest #[Locked]),
 *  - nieudana walidacja NICZEGO nie czyści — komunikat pojawia się nad
 *    formularzem i przy polu, a wpisane dane zostają w stanie komponentu
 *    ORAZ w zapisanym szkicu,
 *  - identyfikatory zdjęć wędrują przez całe życie komponentu, bo
 *    PublishRecipe zapisuje dokładnie to, co dostanie — pominięcie
 *    source_scan_media_id skasowałoby zdjęcie kartki z zeszytu.
 *
 * Kreator wymaga JavaScriptu, więc NIE JEST jedyną drogą: formularz
 * jednostronicowy żyje dalej pod /dodaj/przepis/jedna-strona i publikuje
 * przepis zwykłym POST-em (AGENTS.md → „JavaScript jest ulepszeniem”).
 */
new class extends Component
{
    use WithFileUploads;

    /** Liczba kroków pokazywana człowiekowi („Krok 2 z 3”). Podgląd to krok 4. */
    public const STEPS = 3;

    public const STEP_PREVIEW = 4;

    public const STEP_NAMES = [
        1 => 'o przepisie',
        2 => 'składniki',
        3 => 'przygotowanie',
        self::STEP_PREVIEW => 'podgląd',
    ];

    public int $step = 1;

    /*
     * #[Locked] — te trzy pola decydują, KTÓRY przepis jest nadpisywany
     * i które zdjęcia do niego należą. Klient nie może ich podmienić.
     */
    #[Locked]
    public ?string $recipeId = null;

    #[Locked]
    public ?string $heroMediaId = null;

    #[Locked]
    public ?string $sourceScanMediaId = null;

    /**
     * Czy kreator otwarto NA JUŻ OPUBLIKOWANYM przepisie (issue #364).
     *
     * Od #364 kreator przestał być ekranem tworzenia i jest ekranem
     * DOPISYWANIA SZCZEGÓŁÓW — wchodzi się do niego z opublikowanego przepisu
     * (`/przepisy/{slug}/szczegoly`). Przycisk „Opublikuj przepis" mówiłby
     * tam nieprawdę: przepis jest opublikowany od chwili, gdy człowiek
     * kliknął „Opublikuj" na ekranie dodawania.
     *
     * `#[Locked]`, bo decyduje o tym, co człowiek przeczyta na przycisku,
     * i nie ma powodu, żeby klient mógł to podmienić.
     */
    #[Locked]
    public bool $juzOpublikowany = false;

    public string $title = '';

    public string $summary = '';

    public string $servings = '';

    public string $prep_minutes = '';

    public string $cook_minutes = '';

    public string $difficulty = '';

    public string $visibility = 'public';

    public string $source_type = 'own';

    public string $source_person = '';

    public string $source_note = '';

    public string $source_url = '';

    public string $family_since_year = '';

    /** @var list<array{_key: string, group_name: string, text: string, note: string, no_amount: bool}> */
    public array $ingredients = [];

    /**
     * Wiersze przygotowania.
     *
     * `mediaId` I `timer_minutes` NIOSĄ SIĘ W WIERSZU, nie w osobnej tablicy
     * trzymanej obok. `moveStepUp/Down` i `removeStep` przestawiają CAŁE
     * elementy tej tablicy, więc zdjęcie i minutnik jadą razem ze swoim
     * krokiem — a tego nie dałaby druga tablica indeksowana pozycją
     * (audyt T12/T24: przestawienie kroków przypisywało zdjęcie do złego).
     *
     * @var list<array{_key: string, instruction: string, timer_minutes: string, mediaId: ?string, photo: mixed}>
     */
    public array $steps = [];

    public $heroPhoto = null;

    /** Stan plakietki autosave: '' | 'saved' | 'waiting' | 'error'. */
    public string $saveState = '';

    public string $saveMessage = '';

    /** Licznik zmian wysłanych z przeglądarki i ostatniej obsłużonej wersji formularza. */
    public int $editRevision = 0;

    #[Locked]
    public int $acknowledgedRevision = 0;

    /** Licznik stabilnych kluczy wierszy — bez nich zmiana kolejności gubi treść pól. */
    public int $rowCounter = 0;

    /**
     * Czy szkic już zapisał się w TYM żądaniu.
     *
     * Kliknięcie „Dalej” po zmianie pola przychodzi jako jedno żądanie:
     * najpierw hook updated(), potem next(). Bez tej flagi ten sam szkic
     * zapisywałby się dwa razy pod rząd.
     */
    private bool $savedThisRequest = false;

    // -----------------------------------------------------------------
    // Wejście do kreatora
    // -----------------------------------------------------------------

    public function mount(?string $recipeId = null): void
    {
        if ($recipeId !== null) {
            $recipe = Recipe::with(['ingredients', 'steps'])->findOrFail($recipeId);
            Gate::authorize('update', $recipe);

            $this->fillFrom($recipe);

            return;
        }

        // Trzy puste wiersze widoczne od razu — pusta lista z samym przyciskiem
        // „Dodaj składnik” jest mniej zrozumiała niż gotowe pola (issue #13).
        $this->ingredients = [$this->blankIngredient(), $this->blankIngredient(), $this->blankIngredient()];
        $this->steps = [$this->blankStep(), $this->blankStep(), $this->blankStep()];
    }

    private function fillFrom(Recipe $recipe): void
    {
        $this->recipeId = $recipe->getKey();
        $this->juzOpublikowany = $recipe->isPublished();
        $this->heroMediaId = $recipe->hero_media_id;
        $this->sourceScanMediaId = $recipe->source_scan_media_id;

        $this->title = (string) $recipe->title;
        $this->summary = (string) $recipe->summary;
        $this->servings = $this->numberToText($recipe->servings);
        $this->prep_minutes = $this->numberToText($recipe->prep_minutes);
        $this->cook_minutes = $this->numberToText($recipe->cook_minutes);
        $this->difficulty = (string) $recipe->difficulty;
        $this->visibility = (string) ($recipe->visibility ?: 'public');
        $this->source_type = (string) ($recipe->source_type ?: Recipe::SOURCE_OWN);
        $this->source_person = (string) $recipe->source_person;
        $this->source_note = (string) $recipe->source_note;
        $this->source_url = (string) $recipe->source_url;
        $this->family_since_year = $this->numberToText($recipe->family_since_year);

        $this->ingredients = $recipe->ingredients
            ->map(fn ($row): array => [
                '_key' => $this->nextRowKey(),
                'group_name' => (string) $row->group_name,
                'text' => (string) $row->ingredient_text,
                'note' => (string) $row->note,
                'no_amount' => (bool) $row->no_amount,
            ])
            ->all();

        $this->steps = $recipe->steps
            ->map(fn ($row): array => [
                '_key' => $this->nextRowKey(),
                'instruction' => (string) $row->instruction,
                // Baza trzyma sekundy (tego czyta tryb gotowania), człowiek
                // wpisuje minuty. Przelicznik jest jeden — `StepTimer` —
                // i ten sam po obu stronach zapisu.
                'timer_minutes' => $this->numberToText(StepTimer::minutesFromSeconds($row->timer_seconds)),
                'mediaId' => $row->media_id,
                'photo' => null,
            ])
            ->all();

        // Jeden pusty wiersz na końcu, żeby dopisanie czegoś nie wymagało
        // najpierw kliknięcia „Dodaj składnik”.
        $this->ingredients[] = $this->blankIngredient();
        $this->steps[] = $this->blankStep();
    }

    // -----------------------------------------------------------------
    // Nawigacja między krokami
    // -----------------------------------------------------------------

    public function next(): void
    {
        $this->resetErrorBag();

        if ($this->step === 1 && ! $this->validateAboutStep()) {
            return;
        }

        if (! $this->saveDraft()) {
            if (! $this->validateAboutStep()) {
                $this->step = 1;
            } else {
                $this->validateRows();
            }

            return;
        }

        $this->step = min($this->step + 1, self::STEP_PREVIEW);
    }

    public function back(): void
    {
        $this->resetErrorBag();

        // Zapis PRZED cofnięciem — inaczej „Wstecz” wyglądałoby jak utrata
        // tego, co człowiek właśnie wpisał.
        $this->saveDraft();

        $this->step = max($this->step - 1, 1);
    }

    /**
     * Który krok pokazuje pole o tym kluczu błędu (issue #747).
     *
     * Podsumowanie błędów zbiera klucze ze WSZYSTKICH kroków naraz —
     * `$errors->keys()` nie wie nic o aktualnie wyrenderowanym `$step`.
     * `back()` potrafi zostawić błąd walidacji z kroku 3 (`saveDraft()` →
     * `validateRows(changeStep: false)`) i mimo to zejść na krok 2: link
     * `href="#f-steps-0-instruction"` wskazywałby wtedy na pole, którego
     * w bieżącym HTML w ogóle nie ma.
     */
    public function stepForKey(string $key): int
    {
        return match (true) {
            str_starts_with($key, 'ingredients.') => 2,
            // Obejmuje zarówno `steps` (błąd „opisz przynajmniej jeden
            // krok”) jak i `steps.N.instruction` / `steps.N.photo`.
            str_starts_with($key, 'steps') => 3,
            $key === 'publikacja' => self::STEP_PREVIEW,
            default => 1,
        };
    }

    /**
     * Kliknięcie odnośnika w podsumowaniu błędów, gdy pole stoi na INNYM
     * kroku niż ten, który człowiek aktualnie widzi. Przełącza krok
     * i prosi przeglądarkę (przez zdarzenie JS) o ustawienie fokusu na
     * właściwym polu PO przerenderowaniu — sam `$this->step` nie wystarczy,
     * bo DOM w tej samej chwili jeszcze nie istnieje.
     */
    public function jumpToError(string $key): void
    {
        $this->step = $this->stepForKey($key);

        $this->dispatch('kreator-fokus-pole', pole: 'f-'.str_replace(['[', ']', '.'], '-', $key));
    }

    // -----------------------------------------------------------------
    // Wiersze składników (issue #13)
    // -----------------------------------------------------------------

    public function addIngredient(): void
    {
        $this->ingredients[] = $this->blankIngredient();
        $this->saveDraft();
    }

    public function removeIngredient(int $index): void
    {
        $this->ingredients = $this->withoutRow($this->ingredients, $index);

        if ($this->ingredients === []) {
            $this->ingredients = [$this->blankIngredient()];
        }

        $this->saveDraft();
    }

    public function moveIngredientUp(int $index): void
    {
        $this->ingredients = $this->swapRows($this->ingredients, $index, $index - 1);
        $this->saveDraft();
    }

    public function moveIngredientDown(int $index): void
    {
        $this->ingredients = $this->swapRows($this->ingredients, $index, $index + 1);
        $this->saveDraft();
    }

    // -----------------------------------------------------------------
    // Wiersze przygotowania (issue #13)
    // -----------------------------------------------------------------

    public function addStep(): void
    {
        $this->steps[] = $this->blankStep();
        $this->saveDraft();
    }

    public function removeStep(int $index): void
    {
        $this->replaceSteps($this->withoutRow($this->steps, $index));

        if ($this->steps === []) {
            $this->steps = [$this->blankStep()];
        }

        $this->saveDraft();
    }

    public function moveStepUp(int $index): void
    {
        $this->replaceSteps($this->swapRows($this->steps, $index, $index - 1));
        $this->saveDraft();
    }

    public function moveStepDown(int $index): void
    {
        $this->replaceSteps($this->swapRows($this->steps, $index, $index + 1));
        $this->saveDraft();
    }

    /** Zmiana pozycji przenosi również błędy; usunięcie zabiera tylko błędy usuwanego kroku. */
    private function replaceSteps(array $rows): void
    {
        $positions = array_column($rows, null, '_key');
        $newIndexes = array_flip(array_keys($positions));
        $errors = [];

        foreach ($this->getErrorBag()->getMessages() as $field => $messages) {
            if (preg_match('/^steps\.(\d+)\.(.+)$/', $field, $match)) {
                $key = $this->steps[(int) $match[1]]['_key'] ?? null;
                if ($key === null || ! isset($newIndexes[$key])) {
                    continue;
                }
                $field = 'steps.'.$newIndexes[$key].'.'.$match[2];
            }
            $errors[$field] = $messages;
        }

        $this->steps = $rows;
        $this->setErrorBag($errors);
    }

    // -----------------------------------------------------------------
    // Autosave
    // -----------------------------------------------------------------

    /**
     * Hook Livewire: odpala się po zmianie pola. Pola tekstowe mają
     * debounce 3000 ms, więc to jest właśnie „zapis po ~3 s bezczynności”.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['step', 'saveState', 'saveMessage', 'rowCounter'], true)) {
            return;
        }

        $this->resetErrorBag($property);
        $this->saveDraft();
    }

    public function saveDraft(): bool
    {
        $this->acknowledgedRevision = $this->editRevision;

        if ($this->savedThisRequest) {
            return $this->saveState === 'saved';
        }

        $this->storePendingPhotos();

        if (mb_strlen(trim($this->title)) < 3) {
            // Bez nazwy nie da się utworzyć przepisu (PublishRecipe tego pilnuje),
            // więc mówimy wprost, czego brakuje — zamiast cicho nie zapisywać.
            $this->saveState = 'waiting';
            $this->saveMessage = $this->juzOpublikowany ? 'Podaj nazwę przepisu, żeby zapisać zmiany.' : 'Szkic zapisze się, kiedy podasz nazwę przepisu.';

            return false;
        }

        // Autozapis przechodzi te same granice co ręczna publikacja (#528).
        // Sprawdzamy SUROWE pola przed persist()/clean*(), inaczej długi
        // tytuł kończy się SQL 22001, a wiersz bywa po cichu przycięty.
        // Błąd nie nadpisuje wcześniejszego dobrego szkicu ani tekstu w UI.
        $aboutValid = $this->validateAboutStep();
        $rowsValid = $this->validateRows(changeStep: false);
        if (! $aboutValid || ! $rowsValid) {
            $this->saveState = 'error';
            $this->saveMessage = 'Nie zapisaliśmy tych zmian. Popraw zaznaczone pola. Cały tekst jest nadal w formularzu.';

            return false;
        }

        try {
            $this->persist(publish: false);
        } catch (BladDlaCzlowieka $e) {
            $this->saveState = 'error';
            $this->saveMessage = ($this->juzOpublikowany ? 'Nie udało się zapisać zmian: ' : 'Nie udało się zapisać szkicu: ').$e->getMessage().' Nic nie zginęło — cały tekst jest dalej w formularzu.';

            return false;
        }

        $this->savedThisRequest = true;
        $this->saveState = 'saved';
        $this->saveMessage = $this->juzOpublikowany ? 'Zmiany zapisane.' : 'Szkic zapisany.';

        return true;
    }

    // -----------------------------------------------------------------
    // Publikacja
    // -----------------------------------------------------------------

    public function publish(): void
    {
        $this->resetErrorBag();

        if (! $this->storePendingPhotos()) {
            /*
             * Zdjęcie się nie przyjęło. Nie publikujemy w ciszy — człowiek
             * ma zobaczyć dlaczego. Reszta danych zostaje zapisana w szkicu.
             *
             * `storePendingPhotos()` przypisuje błąd ALBO do `heroPhoto`
             * (krok 1), ALBO do `steps.N.photo` (krok 3) — nigdy do obu na
             * raz w jednym wywołaniu tej metody nie znaczy to samo. Stałe
             * „krok 1” tutaj (issue #747) pokazywało puste zdjęcie główne,
             * podczas gdy prawdziwy błąd — i jedyne pole z komunikatem —
             * czekał na kroku 3.
             */
            $this->step = collect($this->getErrorBag()->keys())->contains(fn (string $klucz) => str_starts_with($klucz, 'steps'))
                ? 3
                : 1;
            $this->saveDraft();

            return;
        }

        if (! $this->validateAboutStep()) {
            $this->step = 1;
            $this->saveDraft();

            return;
        }

        if (! $this->validateRows()) {
            $this->saveDraft();

            return;
        }

        /*
         * SKŁADNIKÓW TU NIE SPRAWDZAMY — ZGODA WŁAŚCICIELA z 11.09.2026
         * (issue #364): „przepis wolno opublikować bez ani jednego składnika".
         *
         * Bramka stała tu w parze z tą samą bramką w `PublishRecipe` i obie
         * zniknęły razem, bo jedna reguła nie może obowiązywać na jednej
         * z dwóch dróg zapisu. Krok przygotowania zostaje warunkiem —
         * uzasadnienie przy bramce w `PublishRecipe`.
         */
        if ($this->cleanSteps() === []) {
            $this->step = 3;
            $this->addError('steps', $this->juzOpublikowany ? 'Opisz przynajmniej jeden krok przygotowania, żeby zapisać zmiany. Tekst jest dalej w formularzu.' : 'Opisz przynajmniej jeden krok przygotowania, żeby opublikować przepis. Nic nie zginęło — resztę masz zapisaną w szkicu.');
            $this->saveDraft();

            return;
        }

        try {
            $recipe = $this->persist(publish: true);
        } catch (BladDlaCzlowieka $e) {
            $this->addError('publikacja', $e->getMessage());
            $this->saveDraft();

            return;
        }

        session()->flash('status', $this->juzOpublikowany
            ? 'Szczegóły zapisane.'
            : match ($recipe->visibility) {
                'private' => 'Przepis zapisany. Widzisz go tylko Ty.',
                'followers' => 'Przepis opublikowany dla osób, które Cię obserwują.',
                default => 'Przepis opublikowany. Teraz ktoś może z niego ugotować.',
            });

        $this->redirect(route('recipes.show', $recipe->slug));
    }

    // -----------------------------------------------------------------
    // Zapis do bazy
    // -----------------------------------------------------------------

    private function persist(bool $publish): Recipe
    {
        $recipe = app(PublishRecipe::class)->handle(
            author: auth()->user(),
            attributes: [
                'title' => trim($this->title),
                'summary' => $this->textOrNull($this->summary),
                'servings' => $this->numberOrNull($this->servings),
                'prep_minutes' => $this->intOrNull($this->prep_minutes),
                'cook_minutes' => $this->intOrNull($this->cook_minutes),
                'difficulty' => $this->textOrNull($this->difficulty),
                'visibility' => $this->visibility,
                'source_type' => $this->source_type,
                'source_person' => $this->textOrNull($this->source_person),
                'source_note' => $this->textOrNull($this->source_note),
                'source_url' => $this->textOrNull($this->source_url),
                'family_since_year' => $this->intOrNull($this->family_since_year),
                'hero_media_id' => $this->heroMediaId,
                'source_scan_media_id' => $this->sourceScanMediaId,
            ],
            ingredients: $this->cleanIngredients(),
            steps: $this->cleanSteps(),
            publish: $publish,
            existing: $this->existingRecipe(),
            ip: request()->ip(),
        );

        $this->recipeId = $recipe->getKey();

        return $recipe;
    }

    /** Przepis, który nadpisujemy — z autoryzacją przy KAŻDYM zapisie, nie tylko przy wejściu. */
    private function existingRecipe(): ?Recipe
    {
        if ($this->recipeId === null) {
            return null;
        }

        $recipe = Recipe::find($this->recipeId);

        if ($recipe === null) {
            throw new BladDlaCzlowieka('Ten przepis nie jest już dostępny. Skopiuj wpisany tekst, zanim opuścisz formularz.');
        }

        Gate::authorize('update', $recipe);

        return $recipe;
    }

    /**
     * Wybrane zdjęcia idą przez zwykły pipeline mediów (magic bytes, limit
     * megapikseli, zdjęcie EXIF w tle). Zwraca false, jeśli którykolwiek plik
     * odpadł.
     *
     * Zdjęcie gotowego dania I zdjęcia kroków przechodzą tu razem, bo idą tą
     * samą drogą i mają te same limity — dwie osobne metody to dwa miejsca,
     * w których można zapomnieć o jednym ze sprawdzeń.
     */
    private function storePendingPhotos(): bool
    {
        $ok = $this->storePendingHeroPhoto();

        foreach ($this->steps as $index => $row) {
            if (($row['photo'] ?? null) === null) {
                continue;
            }

            $photo = $row['photo'];

            // Zerujemy od razu, żeby odrzucony plik nie blokował każdego
            // następnego autosave'u tym samym błędem.
            $this->steps[$index]['photo'] = null;

            try {
                $this->steps[$index]['mediaId'] = app(StoreUploadedImage::class)
                    ->handle(auth()->user(), $photo)
                    ->getKey();
            } catch (BladDlaCzlowieka $e) {
                $this->addError("steps.{$index}.photo", $e->getMessage());
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * „Usuń zdjęcie z tego kroku" — osobna akcja, nie efekt uboczny czegoś
     * innego. Zdjęcie zostaje w `media` (właściciel ma je w eksporcie RODO),
     * odpięty zostaje tylko krok.
     */
    public function removeStepPhoto(int $index): void
    {
        if (! isset($this->steps[$index])) {
            return;
        }

        $this->steps[$index]['mediaId'] = null;
        $this->steps[$index]['photo'] = null;

        $this->saveDraft();
    }

    private function storePendingHeroPhoto(): bool
    {
        if ($this->heroPhoto === null) {
            return true;
        }

        $photo = $this->heroPhoto;

        // Zerujemy od razu, żeby odrzucony plik nie blokował każdego
        // następnego autosave'u tym samym błędem.
        $this->heroPhoto = null;

        try {
            $this->heroMediaId = app(StoreUploadedImage::class)
                ->handle(auth()->user(), $photo)
                ->getKey();
        } catch (BladDlaCzlowieka $e) {
            $this->addError('heroPhoto', $e->getMessage());

            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Walidacja (komunikaty po polsku, mówiące CO ZROBIĆ)
    // -----------------------------------------------------------------

    private function validateAboutStep(): bool
    {
        // #900: jak w RecipeController — nowy lub zmieniony adres musi być
        // HTTP/HTTPS, niezmieniony dawny adres z bazy nie blokuje zapisu.
        // Porównanie z bazą, nie ze stanem komponentu: autozapis nie może
        // zrobić z dopiero wpisanego FTP „historycznego wyjątku”.
        $dawnyAdres = $this->recipeId === null ? null : Recipe::whereKey($this->recipeId)->value('source_url');
        $adres = $this->textOrNull($this->source_url);

        $validator = Validator::make([
            'title' => trim($this->title),
            'summary' => $this->textOrNull($this->summary),
            'servings' => $this->textOrNull($this->servings),
            'prep_minutes' => $this->textOrNull($this->prep_minutes),
            'cook_minutes' => $this->textOrNull($this->cook_minutes),
            'difficulty' => $this->textOrNull($this->difficulty),
            'visibility' => $this->visibility,
            'source_type' => $this->source_type,
            'source_person' => $this->textOrNull($this->source_person),
            'source_note' => $this->textOrNull($this->source_note),
            'source_url' => $this->textOrNull($this->source_url),
            'family_since_year' => $this->textOrNull($this->family_since_year),
        ], [
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'summary' => ['nullable', 'string', 'max:2000'],
            // Krok 0,01 (setne) — ta sama reguła co `ZapisPrzepisuRequest`
            // (decyzja właściciela z 20.09.2026, issue #750). Bez `decimal:0,2`
            // kreator zapisywał 1,255 po cichu jako 1,26 (kolumna decimal(6,2)).
            'servings' => ['nullable', 'numeric', 'min:0.5', 'max:999', 'decimal:0,2'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'visibility' => ['required', 'in:public,followers,private'],
            'source_type' => ['required', 'in:own,family,adaptation,external'],
            'source_person' => ['nullable', 'string', 'max:120'],
            'source_note' => ['nullable', 'string', 'max:2000'],
            'source_url' => ['nullable', $dawnyAdres !== null && $adres === $dawnyAdres ? 'url' : 'url:http,https', 'max:2000'],
            'family_since_year' => ['nullable', 'integer', 'min:1850', 'max:2100'],
        ], [
            'title.required' => 'Podaj nazwę przepisu — na przykład „Rosół babci Zofii”.',
            'title.min' => 'Nazwa przepisu musi mieć co najmniej 3 znaki. Dopisz kilka liter.',
            'title.max' => 'Nazwa przepisu jest za długa. Skróć ją do 180 znaków.',
            'summary.max' => 'Krótki opis jest za długi. Zostaw najwyżej 2000 znaków — resztę wpisz w historii przepisu.',
            'servings.numeric' => 'Liczba porcji musi być liczbą. Wpisz na przykład 4.',
            'servings.min' => 'Liczba porcji musi być większa od zera. Wpisz na przykład 4.',
            'servings.max' => 'Ta liczba porcji jest nierealna. Wpisz najwyżej 999.',
            'servings.decimal' => 'Liczba porcji może mieć najwyżej dwa miejsca po przecinku (setne). Zamiast 1,255 wpisz 1,25 albo 1,26.',
            'prep_minutes.integer' => 'Czas przygotowania podaj w pełnych minutach, na przykład 20.',
            'prep_minutes.min' => 'Czas przygotowania nie może być ujemny. Wpisz na przykład 20.',
            'prep_minutes.max' => 'Czas przygotowania jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
            'cook_minutes.integer' => 'Czas gotowania podaj w pełnych minutach, na przykład 90.',
            'cook_minutes.min' => 'Czas gotowania nie może być ujemny. Wpisz na przykład 90.',
            'cook_minutes.max' => 'Czas gotowania jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten przepis.',
            'visibility.in' => 'Zaznacz, kto ma widzieć ten przepis.',
            'source_type.required' => 'Zaznacz, skąd jest ten przepis.',
            'source_type.in' => 'Zaznacz, skąd jest ten przepis.',
            'source_person.max' => 'To pole jest za długie. Zostaw najwyżej 120 znaków — wystarczy krótka wzmianka, na przykład „od mamy”.',
            'source_note.max' => 'Historia przepisu jest za długa. Zostaw najwyżej 2000 znaków.',
            'source_url.url' => 'Wklej adres strony zaczynający się od http:// lub https://.',
            'family_since_year.integer' => 'Rok wpisz czterema cyframi, na przykład 1974.',
            'family_since_year.min' => 'Ten rok jest za wczesny. Wpisz rok od 1850.',
            'family_since_year.max' => 'Ten rok jest za późny. Wpisz rok do 2100.',
        ]);

        // Ponowna walidacja usuwa stare błędy tylko tych pól. Nie kasuje
        // komunikatu zdjęcia ani innego etapu; poprawka pola odblokowuje zapis.
        $this->resetErrorBag(array_keys($validator->getData()));
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }

            return false;
        }

        return true;
    }

    private function validateRows(bool $changeStep = true): bool
    {
        $this->resetErrorBag(['ingredients.*.text', 'ingredients.*.group_name', 'ingredients.*.note', 'steps.*.instruction', 'steps.*.timer_minutes']);
        $badIngredient = false;
        $badStep = false;

        foreach ($this->ingredients as $index => $row) {
            if (mb_strlen(trim((string) ($row['text'] ?? ''))) > 240) {
                $this->addError("ingredients.{$index}.text", 'Ten składnik jest za długi. Zostaw najwyżej 240 znaków albo rozbij go na dwa wiersze.');
                $badIngredient = true;
            }

            if (mb_strlen(trim((string) ($row['group_name'] ?? ''))) > 120) {
                $this->addError("ingredients.{$index}.group_name", 'Nazwa grupy jest za długa. Zostaw najwyżej 120 znaków, na przykład „Ciasto”.');
                $badIngredient = true;
            }

            if (mb_strlen(trim((string) ($row['note'] ?? ''))) > 300) {
                $this->addError("ingredients.{$index}.note", 'Ta uwaga jest za długa. Zostaw najwyżej 300 znaków.');
                $badIngredient = true;
            }
        }

        foreach ($this->steps as $index => $row) {
            if (mb_strlen(trim((string) ($row['instruction'] ?? ''))) > 4000) {
                $this->addError("steps.{$index}.instruction", 'Ten krok jest za długi. Zostaw najwyżej 4000 znaków albo podziel go na dwa kroki.');
                $badStep = true;
            }

            // Minutnik sprawdzamy TĄ SAMĄ bramką, która go potem przelicza
            // (`StepTimer`), a nie osobnym zestawem reguł obok. Inaczej
            // kreator przyjmowałby wartość, którą akcja domenowa i tak
            // odrzuci — i odrzuci ją nad całym formularzem, a nie przy polu.
            try {
                StepTimer::secondsFromMinutes($row['timer_minutes'] ?? null);
            } catch (BladDlaCzlowieka $e) {
                $this->addError("steps.{$index}.timer_minutes", $e->getMessage());
                $badStep = true;
            }
        }

        if ($changeStep && $badIngredient) {
            $this->step = 2;
        } elseif ($changeStep && $badStep) {
            $this->step = 3;
        }

        return ! $badIngredient && ! $badStep;
    }

    // -----------------------------------------------------------------
    // Dane do zapisu i do podglądu
    // -----------------------------------------------------------------

    /**
     * Puste wiersze są pomijane — pusty składnik nigdy nie trafia do bazy.
     *
     * @return list<array{text: string, group_name: ?string, note: ?string}>
     */
    public function cleanIngredients(): array
    {
        $clean = [];

        foreach ($this->ingredients as $row) {
            $text = trim((string) ($row['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $clean[] = [
                'text' => mb_substr($text, 0, 240),
                'group_name' => $this->clampOrNull($row['group_name'] ?? null, 120),
                'note' => $this->clampOrNull($row['note'] ?? null, 300),
                // „Bez ilości” — sól do smaku, mleko ile weźmie (issue #44).
                'no_amount' => (bool) ($row['no_amount'] ?? false),
            ];
        }

        return $clean;
    }

    /**
     * @return list<array{id: null, instruction: string, timer_minutes: string, media_id: ?string}>
     */
    public function cleanSteps(): array
    {
        $clean = [];

        foreach ($this->steps as $row) {
            $instruction = trim((string) ($row['instruction'] ?? ''));

            if ($instruction === '') {
                continue;
            }

            $clean[] = [
                // `id` ZAWSZE null, i to jest świadome.
                //
                // Formularz bez JavaScriptu musi odesłać identyfikator kroku,
                // bo zdjęcia nie umie przysłać drugi raz. Kreator zdjęcie
                // NIESIE — `mediaId` siedzi w wierszu i przeżywa każde
                // przestawienie kolejności, bo `swapRows()` przenosi cały
                // wiersz. Podanie tu `id` dodałoby DRUGĄ drogę do tego samego
                // zdjęcia, a przy pierwszym rozjeździe między nimi wygrywałaby
                // ta, o której nikt nie pamięta.
                'id' => null,
                'instruction' => mb_substr($instruction, 0, 4000),
                'timer_minutes' => (string) ($row['timer_minutes'] ?? ''),
                // Brak `mediaId` znaczy tu „bez zdjęcia" wprost: nie ma `id`,
                // z którego dałoby się cokolwiek odziedziczyć.
                'media_id' => $row['mediaId'] ?? null,
            ];
        }

        return $clean;
    }

    /**
     * Składniki pogrupowane do podglądu („Ciasto”, „Nadzienie”).
     *
     * Układ liczy `GrupySkladnikow` — ten sam kod, co na stronie przepisu
     * i w eksporcie danych. Podgląd, który układa składniki po swojemu, jest
     * gorszy niż brak podglądu: pokazuje przepis, którego po opublikowaniu
     * nikt nie zobaczy. Wcześniej różnił się dwiema rzeczami — składniki bez
     * grupy zostawały tam, gdzie stały (a nie na górze), a „Farsz" i „farsz"
     * dawały dwa nagłówki.
     *
     * @return list<array{nazwa: ?string, skladniki: list<array{text: string, group_name: ?string, note: ?string}>}>
     */
    public function groupedIngredients(): array
    {
        return GrupySkladnikow::ulozyc($this->cleanIngredients());
    }

    public function stepName(): string
    {
        return self::STEP_NAMES[$this->step] ?? '';
    }

    /**
     * Nagłówek podglądu — ZALEŻNY OD WYBRANEJ WIDOCZNOŚCI.
     *
     * Do 11 września 2026 stało tu bezwarunkowe „Podgląd: tak zobaczą to
     * inni". Przy przepisie oznaczonym „Tylko ja" to była nieprawda:
     * nikt inny tego nie zobaczy i nie ma go zobaczyć.
     *
     * Zdanie zależne, a nie jedno neutralne dla wszystkich trzech przypadków,
     * bo ekran widoczność ZNA. Wybór stoi w kroku 1 (`visibility`), a na
     * podgląd wchodzi się przyciskiem „Dalej", czyli przez `next()` — więc
     * zanim ten nagłówek się wyrenderuje, wartość jest już w stanie
     * komponentu i po stronie serwera. To nie jest założenie: `next()`
     * wywołuje `saveDraft()`, a ten zapisuje `visibility` do przepisu.
     */
    public function previewHeading(): string
    {
        return match ($this->visibility) {
            'private' => 'Podgląd: tak będziesz widzieć ten przepis',
            'followers' => 'Podgląd: tak zobaczą to osoby, które Cię obserwują',
            default => 'Podgląd: tak zobaczą to inni',
        };
    }

    public function previewServings(): ?float
    {
        return $this->numberOrNull($this->servings);
    }

    /**
     * Liczba porcji do podglądu — liczona TYM SAMYM kodem, co znaczek na
     * stronie przepisu (`Recipe::servingsLabel()`), a nie drugą kopią
     * odmiany liczebnika obok. Model nie jest zapisywany.
     *
     * PO CO TO POWSTAŁO (issue #38)
     * Podgląd pisał `(int) previewServings().' porcji'`, czyli dokładnie to,
     * co `servingsLabel()` naprawiało na stronie przepisu (audyt A28):
     *
     *     w polu 1     → „1 porcji"     (nie po polsku)
     *     w polu 2     → „2 porcji"     (nie po polsku)
     *     w polu 0,5   → „0 porcji"     (nieprawda o samym sobie — rzut na
     *                                    int obcina połówkę do zera)
     *
     * Ekran, który obiecuje, że tak wygląda gotowy przepis, pokazywał więc
     * coś innego niż to, co widać po opublikowaniu.
     */
    public function previewServingsLabel(): ?string
    {
        $porcje = $this->previewServings();

        return $porcje === null ? null : (new Recipe(['servings' => $porcje]))->servingsLabel();
    }

    /**
     * Etykieta minutnika do podglądu — liczona TYM SAMYM kodem, co w trybie
     * gotowania (`RecipeStep::timerLabel()`), a nie drugą kopią odmiany
     * liczebnika obok. Model nie jest zapisywany; służy wyłącznie do
     * policzenia zdania „45 minut".
     *
     * Wartość niemożliwą zwracamy jako `null`, a nie jako wyjątek: na podgląd
     * da się wejść przyciskiem „Dalej", który sprawdza tylko krok pierwszy,
     * więc render nie może się wywalić na tym, o czym i tak powie dopiero
     * „Opublikuj przepis".
     */
    public function previewTimerLabel(mixed $minutes): ?string
    {
        try {
            $seconds = StepTimer::secondsFromMinutes($minutes);
        } catch (BladDlaCzlowieka) {
            return null;
        }

        return (new RecipeStep(['timer_seconds' => $seconds]))->timerLabel(afterNa: true);
    }

    public function totalMinutes(): ?int
    {
        $total = (int) $this->intOrNull($this->prep_minutes) + (int) $this->intOrNull($this->cook_minutes);

        return $total > 0 ? $total : null;
    }

    // -----------------------------------------------------------------
    // Drobne narzędzia
    // -----------------------------------------------------------------

    /** @return array{_key: string, group_name: string, text: string, note: string, no_amount: bool} */
    private function blankIngredient(): array
    {
        return ['_key' => $this->nextRowKey(), 'group_name' => '', 'text' => '', 'note' => '', 'no_amount' => false];
    }

    /** @return array{_key: string, instruction: string, timer_minutes: string, mediaId: ?string, photo: mixed} */
    private function blankStep(): array
    {
        return [
            '_key' => $this->nextRowKey(),
            'instruction' => '',
            'timer_minutes' => '',
            'mediaId' => null,
            'photo' => null,
        ];
    }

    private function nextRowKey(): string
    {
        return 'w'.(++$this->rowCounter);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutRow(array $rows, int $index): array
    {
        unset($rows[$index]);

        // array_values, bo pozycje w bazie mają UNIQUE (recipe_id, position)
        // i muszą być ciągłe: 0, 1, 2, …
        return array_values($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function swapRows(array $rows, int $from, int $to): array
    {
        if (! isset($rows[$from], $rows[$to])) {
            return $rows;
        }

        $carry = $rows[$from];
        $rows[$from] = $rows[$to];
        $rows[$to] = $carry;

        return array_values($rows);
    }

    private function textOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function clampOrNull(mixed $value, int $length): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $length);
    }

    private function numberOrNull(string $value): ?float
    {
        $trimmed = str_replace(',', '.', trim($value));

        return $trimmed === '' || ! is_numeric($trimmed) ? null : (float) $trimmed;
    }

    private function intOrNull(string $value): ?int
    {
        $trimmed = trim($value);

        return $trimmed === '' || ! is_numeric($trimmed) ? null : (int) $trimmed;
    }

    private function numberToText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        }

        return (string) $value;
    }
};
?>

<div class="stack"
     x-data="{ revision: $wire.editRevision }"
     x-on:input="$wire.editRevision = ++revision">
    {{-- ------------------------------------------------------------------
         Wskaźnik kroku. Tekst „Krok 2 z 3” + nazwa kroku, nie same kropki
         (docs/design/DESIGN_SYSTEM.md → WizardSteps).
    ------------------------------------------------------------------- --}}
    <div class="wizard-steps">
        <span class="wizard-steps-current" aria-current="step">
            @if($step === $this::STEP_PREVIEW)
                Podgląd przed publikacją
            @else
                Krok {{ $step }} z {{ $this::STEPS }} — {{ $this->stepName() }}
            @endif
        </span>
        <span class="wizard-steps-track" aria-hidden="true">
            @for($dot = 1; $dot <= $this::STEPS; $dot++)
                <span class="wizard-steps-dot" data-done="{{ $step > $dot || $step === $this::STEP_PREVIEW ? 'true' : 'false' }}"></span>
            @endfor
        </span>
    </div>

    {{-- Plakietka autosave. aria-live="polite", żeby czytnik ekranu ogłosił
         „Szkic zapisany.” bez przerywania pisania. --}}
    <div aria-live="polite">
        <div wire:loading.remove>
            <p class="autosave-badge" data-state="{{ $saveState }}"
               x-show="revision <= $wire.acknowledgedRevision">
                @if($saveMessage === '' && $juzOpublikowany)
                    Zmiany zapisują się po drodze.
                @elseif($saveMessage === '')
                    {{-- STAN POCZĄTKOWY MÓWI O WARUNKU, A NIE O SAMEJ AUTOMATYCE.

                         Stało tu bezwarunkowe „Szkic zapisuje się sam" i było to
                         nieprawdą dokładnie w tym jednym momencie, w którym ta
                         plakietka jest widoczna: `saveDraft()` bez nazwy przepisu
                         NIE ZAPISUJE NICZEGO (warunek `mb_strlen(trim($title)) < 3`
                         wyżej), a pusty `$saveMessage` znaczy właśnie „jeszcze nic
                         się nie zapisało". Po pierwszym udanym zapisie stoi tu już
                         „Szkic zapisany.". --}}
                    Szkic zapisze się, kiedy podasz nazwę przepisu.
                @else
                    {{ $saveMessage }}
                @endif
            </p>
            <p class="autosave-badge" data-state="waiting" x-cloak x-show="revision > $wire.acknowledgedRevision">
                Zmiany czekają na zapis.
            </p>
        </div>
        <p class="autosave-badge" data-state="saving"
           wire:loading>
            Zapisywanie…
        </p>
    </div>

    {{-- Podsumowanie błędów na górze + link do każdego pola. --}}
    @if($errors->any())
        <div class="error-summary" role="alert" tabindex="-1">
            <p class="error-summary-title">
                Sprawdź formularz
            </p>
            <ul>
                {{--
                    Pole błędu może stać na kroku, którego CAŁE `@if($step
                    === N)` nie jest teraz wyrenderowane (issue #747) — np.
                    po `back()` z błędem walidacji przygotowania. Zwykłe
                    `href="#f-..."` prowadziłoby donikąd: cel nie istnieje
                    w bieżącym HTML. Link zostaje zwykłym `<a>` tylko
                    wtedy, gdy jego pole jest na kroku, który człowiek
                    naprawdę widzi; w przeciwnym razie to `wire:click`,
                    który najpierw przełącza krok i prosi o fokus na
                    właściwym polu po przerenderowaniu.
                --}}
                @foreach($errors->keys() as $key)
                    @php $celId = 'f-'.str_replace(['[', ']', '.'], '-', $key); @endphp
                    <li>
                        @if($this->stepForKey($key) === $step)
                            <a href="#{{ $celId }}">{{ $errors->first($key) }}</a>
                        @else
                            <button type="button" class="error-summary-link" wire:click="jumpToError('{{ $key }}')">{{ $errors->first($key) }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($step === 1)
        {{-- ==============================================================
             Krok 1 z 3 — o przepisie
        =============================================================== --}}
        {{-- BEZ `form-section` — i to nie jest sprzątanie, tylko naprawa.
             `.form-section` daje `border-top: 2px` obwódki DEKORACYJNEJ
             i `padding-top`, a `.form-section:first-of-type` zeruje oba.
             Obie reguły stoją w `app.css`, czyli PO `tokens.css` w tym samym
             `@layer components`, więc przy równej wadze selektora wygrywały
             z `.panel-formularza`: zmierzone w przeglądarce `border-top: 0px`
             i `padding-top: 0px` na kroku 1, a na pozostałych obwódka
             dekoracyjna 2 px zamiast obwódki kontrolki 1 px.

             Zdjęcie klasy niczego nie kosztuje: kroki są rozłączne (`@if`
             / `@elseif`), więc nie ma rodzeństwa, które `.form-section` miałby
             rozdzielać, a odstęp od wskaźnika kroku daje `.stack` wyżej. --}}
        <section class="panel-formularza">
            <h2 class="form-section-title">Krok 1 z {{ $this::STEPS }}: o przepisie</h2>
            {{-- JEDEN MODEL DZIAŁANIA NA JEDNYM EKRANIE.

                 Stało tu „Jeśli nie masz teraz czasu — zapisz szkic"
                 (docs/brand/COPY_STYLE.md §6, zdanie napisane dla formularza
                 na jednej stronie) — a tuż nad tym zdaniem plakietka mówiła,
                 że szkic zapisuje się sam. Człowiek dostawał dwa różne opisy
                 tego, jak ten ekran działa, i żaden z nich nie był pełny.

                 ZMIERZONE, ZANIM WYBRALIŚMY WERSJĘ: autozapis tutaj JEST,
                 ale nie jest bezwarunkowy. `saveDraft()` chodzi po każdym
                 kroku i po ~3 s przerwy w pisaniu (`wire:model.live.debounce`
                 w `x-field` → hook `updated()`), ale bez nazwy przepisu nie
                 zapisuje nic, a przy błędzie zapisu mówi o tym wprost.
                 Dlatego PRZYCISK „Zapisz szkic" ZOSTAJE, a znika obietnica
                 automatu bez warunku — nie odwrotnie. --}}
            <p class="meta mb-4">
                @if($juzOpublikowany)
                    Zachowaj nazwę i co najmniej jeden krok przygotowania, żeby zapisać zmiany.
                @else
                    Wystarczy nazwa, żeby ruszyć dalej. Od niej zaczyna się też zapisywanie:
                @endif
                {{ $juzOpublikowany ? 'Zmiany zapisują się same' : 'szkic zapisuje się sam' }} po każdym kroku i po chwili przerwy w pisaniu,
                a przycisk „{{ $juzOpublikowany ? 'Zapisz zmiany' : 'Zapisz szkic' }}” robi to od razu.
            </p>

            <x-field name="title" label="Nazwa przepisu" required wire="title"
                     :value="$title" placeholder="Rosół babci Zofii" />

            <div class="field @error('heroPhoto') has-error @enderror">
                {{-- Duży obszar wyboru zdjęcia (UI kit v2, `PhotoPicker` —
                     patrz komentarz w resources/css/ekran-dodawania.css).
                     Natywne pole pliku jest schowane dla oka (D-035), bo
                     rysowało angielskie „Choose File / No file chosen";
                     zostaje pod klawiaturą i w drzewie dostępności, a klikalna
                     jest etykieta. `wire:model` i pozostałe atrybuty pola są
                     niezmienione. `<input>` MUSI stać bezpośrednio przed
                     `<label>` — obwódkę fokusu rysuje reguła sąsiedztwa. --}}
                <span class="pole-zdjecia-nazwa" id="f-heroPhoto-etykieta">Zdjęcie gotowego dania <span class="meta">(nieobowiązkowe)</span></span>
                {{-- Treść komunikatu idzie z PHP, a nie z `app.js`, żeby liczba
                     megabajtów miała jedno źródło (`LimityZdjec`) i nie
                     rozjechała się z `config/kuking.php` — issue #111. --}}
                <input class="visually-hidden pole-zdjecia-input" id="f-heroPhoto" type="file" wire:model="heroPhoto"
                       accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                       data-blad-wysylki="{{ \App\Support\LimityZdjec::komunikatNieudanejWysylki() }}"
                       aria-labelledby="f-heroPhoto-etykieta f-heroPhoto-tytul"
                       aria-describedby="f-heroPhoto-help">
                <label class="pole-zdjecia" for="f-heroPhoto">
                    <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                    <span class="pole-zdjecia-tytul" id="f-heroPhoto-tytul">{{ $heroMediaId !== null ? 'Zmień zdjęcie' : 'Dodaj zdjęcie' }}</span>
                    <span class="field-help" id="f-heroPhoto-help">To zdjęcie zobaczą ludzie na liście przepisów.</span>
                </label>
                @error('heroPhoto')<span class="field-error">{{ $message }}</span>@enderror
                @if($heroMediaId !== null)
                    <p class="meta mt-2">Zdjęcie jest już dodane. Wybierz plik jeszcze raz, jeśli chcesz je zmienić.</p>
                @endif
            </div>

            <x-field name="summary" label="Krótko o przepisie" type="textarea" :rows="3" wire="summary"
                     :value="$summary"
                     help="Jedno-dwa zdania. Na co ten przepis jest dobry, kiedy go robisz." />

            <div class="siatka-pol">
                {{-- Krok 0,01 musi się zgadzać z regułą `decimal:0,2` wyżej (#750),
                     inaczej zapisana 1,25 jest dla przeglądarki `stepMismatch`. --}}
                <x-field name="servings" label="Na ile porcji" type="number" inputmode="decimal" wire="servings"
                         :value="$servings" :min="0.5" :max="999" :step="0.01" />
                <x-field name="prep_minutes" label="Przygotowanie (minuty)" type="number" inputmode="numeric" wire="prep_minutes"
                         :value="$prep_minutes" :min="0" :max="10080" />
                <x-field name="cook_minutes" label="Gotowanie / pieczenie (minuty)" type="number" inputmode="numeric" wire="cook_minutes"
                         :value="$cook_minutes" :min="0" :max="10080" />
            </div>

            <fieldset class="border-0 p-0 mt-6">
                <legend class="font-bold mb-3">Jak trudny jest ten przepis?</legend>
                <div class="choice-grid">
                    @foreach(\App\Models\Recipe::DIFFICULTY_LABELS as $value => $label)
                        <label class="choice">
                            <input type="radio" wire:model="difficulty" value="{{ $value }}">
                            <span class="choice-label">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('difficulty')<span class="field-error">{{ $message }}</span>@enderror
            </fieldset>

            <fieldset class="border-0 p-0 mt-6">
                <legend class="font-bold mb-3">Kto ma widzieć ten przepis?</legend>
                <div class="choice-grid">
                    <label class="choice">
                        <input type="radio" wire:model="visibility" value="public">
                        <span><span class="choice-label">Wszyscy</span><span class="choice-help">Także osoby bez konta. Przepis może pojawić się w Google.</span></span>
                    </label>
                    <label class="choice">
                        <input type="radio" wire:model="visibility" value="followers">
                        <span><span class="choice-label">Tylko osoby, które mnie obserwują</span></span>
                    </label>
                    <label class="choice">
                        <input type="radio" wire:model="visibility" value="private">
                        <span><span class="choice-label">Tylko ja</span><span class="choice-help">Twój prywatny zeszyt.</span></span>
                    </label>
                </div>
                @error('visibility')<span class="field-error">{{ $message }}</span>@enderror
            </fieldset>

            <div class="form-section">
                <h3 class="form-section-title">Skąd ten przepis</h3>
                {{-- ZDANIE MÓWI, CO TU WPISAĆ, A NIE JAK CZĘSTO TO KTOŚ CZYTA.

                     Stało tu „To najczęściej czytana część przepisu" —
                     twierdzenie o zachowaniu czytelników, którego nikt nigdy
                     nie zmierzył i którego nie ma czym pokryć. --}}
                <p class="meta mb-4">
                    Tu napiszesz, skąd masz ten przepis i co Cię z nim wiąże.
                </p>

                <fieldset class="border-0 p-0">
                    <legend class="font-bold mb-3">Ten przepis jest…</legend>
                    <div class="choice-grid">
                        @foreach(\App\Models\Recipe::SOURCE_LABELS as $value => $label)
                            <label class="choice">
                                <input type="radio" wire:model="source_type" value="{{ $value }}">
                                <span class="choice-label">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('source_type')<span class="field-error">{{ $message }}</span>@enderror
                </fieldset>

                {{-- PYTAMY O FRAZĘ, KTÓRA STOI SAMODZIELNIE — uzasadnienie
                     przy tym samym polu w `pages/recipes/szczegoly.blade.php`. --}}
                <x-field name="source_person" label="Od kogo albo skąd masz ten przepis" wire="source_person" :value="$source_person"
                         placeholder="od mamy · z gazety · z bloga Nasze smaki"
                         help="Napisz to tak, żeby dało się przeczytać samo: „od mamy”, „z gazety”, „od sąsiadki Haliny”. Pokażemy to przy przepisie dokładnie tak, jak wpiszesz." />

                {{-- POMOC JEST PRAWDZIWA PRZY KAŻDEJ Z TRZECH WIDOCZNOŚCI.

                     Stało tu „To zostaje w rodzinie." — nieprawda przy
                     przepisie publicznym, a taki jest tu domyślny
                     (`public $visibility = 'public'`). Zdanie zależne od
                     `visibility` byłoby tutaj gorsze niż neutralne: pole
                     stoi na tym samym kroku co wybór widoczności, a radia
                     mają zwykły `wire:model` (bez `.live`), więc wartość
                     w kolejnej odpowiedzi bywa o jedno kliknięcie z tyłu.
                     Zdanie zależne od stanu, który chwilami jest nieaktualny,
                     zamieniłoby jedną nieprawdę na drugą, trudniejszą do
                     złapania. To jest prawdziwe zawsze. --}}
                <x-field name="source_note" label="Historia tego przepisu" type="textarea" :rows="4" wire="source_note"
                         :value="$source_note"
                         help="Skąd go znasz, kiedy się go gotuje, co Ci się z nim wiąże. Ta historia jest częścią przepisu — zobaczy ją każdy, kto zobaczy przepis." />

                <x-field name="family_since_year" label="W rodzinie od roku" type="number" inputmode="numeric" wire="family_since_year"
                         :value="$family_since_year" :min="1850" :max="2100" placeholder="1974" />

                <x-field name="source_url" label="Adres strony, z której jest przepis" type="url" wire="source_url"
                         :value="$source_url"
                         help="Podaj, jeśli przepis pochodzi z bloga albo innej strony. Nie publikuj cudzych treści bez zgody." />

                <p class="field-help">
                    Zdjęcie starej kartki albo zeszytu dodasz na
                    <a href="{{ route('recipes.create.simple') }}">formularzu na jednej stronie</a>,
                    a przy {{ $juzOpublikowany ? 'opublikowanym przepisie' : 'zapisanym szkicu' }} — w jego edycji.
                </p>
            </div>
        </section>
    @elseif($step === 2)
        {{-- ==============================================================
             Krok 2 z 3 — składniki
        =============================================================== --}}
        <section class="panel-formularza">
            <h2 class="form-section-title">Krok 2 z {{ $this::STEPS }}: składniki</h2>
            <p class="meta mb-4">
                Pisz tak, jak mówisz: „szklanka mąki”, „2 duże cebule”, „mleko — ile weźmie”.
                Nie musisz nic przeliczać na gramy. Puste wiersze zostaną pominięte.
                {{-- Zdanie wyżej jest wprost z docs/brand/COPY_STYLE.md, §6 „Przepis”. --}}
                Grupę wypełnij tylko wtedy, gdy przepis ma osobne części, na przykład „Ciasto” i „Nadzienie”.
                Przyciski „Przenieś w górę” i „Przenieś w dół” są nieaktywne tam, gdzie nie ma już gdzie przenosić.
            </p>

            @error('ingredients')<p class="field-error mb-4">{{ $message }}</p>@enderror

            @foreach($ingredients as $index => $row)
                <div class="wizard-row" wire:key="skladnik-{{ $row['_key'] ?? $index }}">
                    <x-field :name="'ingredients.'.$index.'.text'" :label="'Składnik '.($index + 1)"
                             :wire="'ingredients.'.$index.'.text'" :value="$row['text'] ?? ''"
                             :placeholder="$index === 0 ? '1 kurczak, najlepiej zagrodowy' : null" />

                    <div class="siatka-pol-szeroka">
                        <x-field :name="'ingredients.'.$index.'.group_name'" label="Grupa składników"
                                 :wire="'ingredients.'.$index.'.group_name'" :value="$row['group_name'] ?? ''"
                                 placeholder="Ciasto" />
                        <x-field :name="'ingredients.'.$index.'.note'" label="Uwaga do składnika"
                                 :wire="'ingredients.'.$index.'.note'" :value="$row['note'] ?? ''"
                                 placeholder="albo masło roślinne" />
                    </div>

                    {{--
                        „BEZ ILOŚCI” — SÓL DO SMAKU (issue #44).

                        Nieobowiązkowe i domyślnie wyłączone. Ma znaczenie
                        dla przyszłego przeliczania porcji (V2, jeszcze niewdrożonego):
                        przepis razy trzy poprosiłby inaczej o trzy szczypty
                        soli i o trzy razy „ile weźmie”. To nie jest drobiazg
                        kosmetyczny — to moment, w którym przepis przestaje
                        wyglądać na napisany przez człowieka.
                    --}}
                    <label class="choice mt-3">
                        <input type="checkbox" wire:model="ingredients.{{ $index }}.no_amount">
                        <span>
                            <span class="choice-label">Bez ilości</span>
                            <span class="choice-help">Zaznacz, jeśli nie podajesz liczby i jednostki. Sposób dozowania wpisz w nazwie składnika, np. „mleko — ile weźmie”.</span>
                        </span>
                    </label>

                    <div class="wizard-row-actions">
                        <div class="wizard-row-move">
                            <button class="btn btn-secondary" type="button"
                                    wire:click="moveIngredientUp({{ $index }})"
                                    @disabled($index === 0)>Przenieś w górę</button>
                            <button class="btn btn-secondary" type="button"
                                    wire:click="moveIngredientDown({{ $index }})"
                                    @disabled($index === count($ingredients) - 1)>Przenieś w dół</button>
                        </div>
                        <div class="wizard-row-remove">
                            <button class="btn btn-danger" type="button"
                                    wire:click="removeIngredient({{ $index }})"
                                    @if(trim((string) ($row['text'] ?? '')) !== '') wire:confirm="Na pewno usunąć składnik „{{ trim((string) $row['text']) }}”? Tej operacji nie da się cofnąć." @endif
                            >Usuń ten wiersz</button>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="form-actions">
                <button class="btn btn-secondary" type="button" wire:click="addIngredient">Dodaj składnik</button>
            </div>
        </section>
    @elseif($step === 3)
        {{-- ==============================================================
             Krok 3 z 3 — przygotowanie
        =============================================================== --}}
        <section class="panel-formularza">
            <h2 class="form-section-title">Krok 3 z {{ $this::STEPS }}: przygotowanie</h2>
            <p class="meta mb-4">
                Jeden krok to jedna czynność. Krótkie kroki łatwiej czytać przy garnku.
                Puste wiersze zostaną pominięte.
                Przyciski „Przenieś w górę” i „Przenieś w dół” są nieaktywne tam, gdzie nie ma już gdzie przenosić.
            </p>

            @error('steps')<p class="field-error mb-4">{{ $message }}</p>@enderror

            @foreach($steps as $index => $row)
                <div class="wizard-row" wire:key="krok-{{ $row['_key'] ?? $index }}">
                    <x-field :name="'steps.'.$index.'.instruction'" :label="'Krok '.($index + 1).': co się robi'" type="textarea" :rows="3"
                             :wire="'steps.'.$index.'.instruction'" :value="$row['instruction'] ?? ''"
                             :placeholder="$index === 0 ? 'Kurczaka zalej zimną wodą i zagotuj. Zbierz szumowiny.' : null" />

                    {{-- `x-field` wolno tu użyć, choć nazwa ma kropki: kreator
                         wysyła dane przez `wire:model`, a nie POST-em, więc
                         atrybut `name` nigdy nie przechodzi przez parser
                         formularzy PHP-a (który zamienia kropki na
                         podkreślenia). W formularzu bez JavaScriptu to samo
                         pole jest rozpisane ręcznie, z nawiasami w `name`. --}}
                    <x-field :name="'steps.'.$index.'.timer_minutes'" label="Ile minut ma trwać ten krok?"
                             type="number" inputmode="numeric"
                             :wire="'steps.'.$index.'.timer_minutes'" :value="$row['timer_minutes'] ?? ''"
                             :min="0" :max="\App\Domain\Recipes\StepTimer::MAX_MINUTES"
                             help="Wpisz liczbę minut — na przykład 45. Przy gotowaniu pokażemy wtedy: „Ustaw sobie kuchenny minutnik na 45 minut”. Zostaw puste, jeśli ten krok nie potrzebuje odliczania." />

                    <div class="field @error("steps.{$index}.photo") has-error @enderror">
                        {{-- Duży obszar wyboru zdjęcia — ten sam wzorzec co
                             przy „Zdjęcie gotowego dania" wyżej w tym pliku:
                             pole pliku schowane dla oka (D-035), klikalna
                             etykieta, `<input>` bezpośrednio przed nią. --}}
                        <span class="pole-zdjecia-nazwa" id="f-steps-{{ $index }}-photo-etykieta">
                            Zdjęcie do tego kroku <span class="meta">(nieobowiązkowe)</span>
                        </span>
                        <input class="visually-hidden pole-zdjecia-input" id="f-steps-{{ $index }}-photo" type="file"
                               wire:model="steps.{{ $index }}.photo"
                               accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                               data-blad-wysylki="{{ \App\Support\LimityZdjec::komunikatNieudanejWysylki() }}"
                               aria-labelledby="f-steps-{{ $index }}-photo-etykieta f-steps-{{ $index }}-photo-tytul"
                               aria-describedby="f-steps-{{ $index }}-photo-help">
                        <label class="pole-zdjecia" for="f-steps-{{ $index }}-photo">
                            <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                            <span class="pole-zdjecia-tytul" id="f-steps-{{ $index }}-photo-tytul">{{ ($row['mediaId'] ?? null) !== null ? 'Zmień zdjęcie' : 'Dodaj zdjęcie' }}</span>
                            <span class="field-help" id="f-steps-{{ $index }}-photo-help">
                                Przydaje się tam, gdzie trudno opisać słowami — jak zawinąć ciasto,
                                jak gęsty ma być sos.
                            </span>
                        </label>
                        @error("steps.{$index}.photo")<span class="field-error">{{ $message }}</span>@enderror

                        @if(($row['mediaId'] ?? null) !== null)
                            <p class="meta mt-2">
                                Zdjęcie do tego kroku jest już dodane. Wybierz plik jeszcze raz,
                                jeśli chcesz je zmienić.
                            </p>
                            <button class="btn btn-secondary mt-2" type="button"
                                    wire:click="removeStepPhoto({{ $index }})"
                                    wire:confirm="Na pewno usunąć zdjęcie z tego kroku? Sam krok zostanie.">
                                Usuń zdjęcie z tego kroku
                            </button>
                        @endif
                    </div>

                    <div class="wizard-row-actions">
                        <div class="wizard-row-move">
                            <button class="btn btn-secondary" type="button"
                                    wire:click="moveStepUp({{ $index }})"
                                    @disabled($index === 0)>Przenieś w górę</button>
                            <button class="btn btn-secondary" type="button"
                                    wire:click="moveStepDown({{ $index }})"
                                    @disabled($index === count($steps) - 1)>Przenieś w dół</button>
                        </div>
                        <div class="wizard-row-remove">
                            <button class="btn btn-danger" type="button"
                                    wire:click="removeStep({{ $index }})"
                                    @if(trim((string) ($row['instruction'] ?? '')) !== '') wire:confirm="Na pewno usunąć ten krok przygotowania? Tej operacji nie da się cofnąć." @endif
                            >Usuń ten wiersz</button>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="form-actions">
                <button class="btn btn-secondary" type="button" wire:click="addStep">Dodaj krok</button>
            </div>
        </section>
    @else
        {{-- ==============================================================
             Podgląd — dokładnie to, co zobaczy ten, kto ma prawo to zobaczyć.
             Nagłówek zależy od wybranej widoczności: patrz previewHeading().
        =============================================================== --}}
        {{-- SEKCJA, NIE PANEL FORMULARZA — jedyny taki krok w kreatorze.
             Mocna obwódka panelu jest tą samą, którą mają pola, więc obiecuje,
             że w środku coś się wpisuje. W podglądzie nie ma ani jednego pola
             (przyciski „Wstecz" i „Opublikuj przepis" stoją POZA tą sekcją,
             w `.form-actions`). Kreator pokazuje jeden krok naraz, więc nie
             powstaje ekran, na którym trzy kroki mają jedną warstwę, a czwarty
             inną — zmiana warstwy jest tu sygnałem „tu już tylko czytasz". --}}
        <section class="sekcja-strony">
            <h2 class="form-section-title">{{ $this->previewHeading() }}</h2>
            <p class="meta mb-4">
                Sprawdź spokojnie. Jeśli coś jest nie tak, wróć przyciskiem „Wstecz” — nic nie zginie.
            </p>

            @error('publikacja')<p class="field-error mb-4">{{ $message }}</p>@enderror

            <article class="stack">
                <h3 class="naglowek-podgladu">{{ trim($title) !== '' ? trim($title) : 'Przepis bez nazwy' }}</h3>

                <ul class="recipe-facts">
                    @if($this->previewServingsLabel() !== null)
                        <li><span class="badge">{{ $this->previewServingsLabel() }}</span></li>
                    @endif
                    @if($this->totalMinutes() !== null)
                        <li><span class="badge">Razem około {{ $this->totalMinutes() }} min</span></li>
                    @endif
                    @if($difficulty !== '')
                        <li><span class="badge">{{ \App\Models\Recipe::DIFFICULTY_LABELS[$difficulty] ?? $difficulty }}</span></li>
                    @endif
                    @if(trim($family_since_year) !== '')
                        <li><span class="badge badge-cooked">W rodzinie od {{ trim($family_since_year) }}</span></li>
                    @endif
                </ul>

                @if($heroMediaId !== null)
                    <p class="meta">Zdjęcie gotowego dania jest dodane. Pokaże się na stronie przepisu, kiedy skończymy je przygotowywać.</p>
                @endif

                @if(trim($summary) !== '')
                    <p class="text-lead">{{ trim($summary) }}</p>
                @endif

                @if(trim($source_person) !== '' || trim($source_note) !== '')
                    <section class="recipe-story">
                        <h4 class="mt-0 text-title-sm">Skąd ten przepis</h4>
                        @if(trim($source_person) !== '')
                            {{-- Podgląd pokazuje dokładnie to, co strona
                                 przepisu — wartość dosłownie, bez doklejonego
                                 „Po". Uzasadnienie stoi przy tym samym
                                 miejscu w `pages/recipes/show.blade.php`. --}}
                            <p><strong>{{ \Illuminate\Support\Str::ucfirst(trim($source_person)) }}</strong></p>
                        @endif
                        @if(trim($source_note) !== '')
                            <p class="whitespace-pre-line mb-0">{{ trim($source_note) }}</p>
                        @endif
                    </section>
                @endif

                <section>
                    <h4 class="text-title-sm">Składniki</h4>
                    @php($previewGroups = $this->groupedIngredients())
                    @if($previewGroups === [])
                        <p class="field-error">Nie ma jeszcze żadnego składnika. Wróć do kroku 2 i dopisz przynajmniej jeden.</p>
                    @else
                        @foreach($previewGroups as $previewGroup)
                            {{-- `<h5>`, bo nagłówkiem tej sekcji podglądu jest
                                 `<h4>Składniki` wyżej. Na stronie przepisu ta
                                 sama grupa jest `<h3>` — poziom bierze się ze
                                 struktury strony, nie z wyglądu, a wygląd
                                 daje jedna klasa `.naglowek-grupy`. --}}
                            @if($previewGroup['nazwa'] !== null)
                                <h5 class="naglowek-grupy">{{ $previewGroup['nazwa'] }}</h5>
                            @endif
                            <ul class="ingredient-list">
                                @foreach($previewGroup['skladniki'] as $groupRow)
                                    <li>
                                        {{ $groupRow['text'] }}
                                        @if($groupRow['note'] !== null)<span class="meta"> — {{ $groupRow['note'] }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endforeach
                    @endif
                </section>

                <section>
                    <h4 class="text-title-sm">Przygotowanie</h4>
                    @php($previewSteps = $this->cleanSteps())
                    @if($previewSteps === [])
                        <p class="field-error">Nie ma jeszcze żadnego kroku. Wróć do kroku 3 i opisz przynajmniej jeden.</p>
                    @else
                        <ol class="step-list">
                            @foreach($previewSteps as $previewIndex => $previewRow)
                                <li>
                                    <span class="step-number" aria-hidden="true">{{ $previewIndex + 1 }}</span>
                                    <div>
                                        <span class="visually-hidden">Krok {{ $previewIndex + 1 }}.</span>
                                        <p class="m-0 whitespace-pre-line">{{ $previewRow['instruction'] }}</p>
                                        {{-- Minutnik i zdjęcie w podglądzie, bo podgląd obiecuje,
                                             że tak wygląda gotowy przepis — a przy gotowaniu widać
                                             jedno i drugie. Etykietę liczy `RecipeStep::timerLabel()`,
                                             ten sam kod co w trybie gotowania. --}}
                                        @php($previewTimer = $this->previewTimerLabel($previewRow['timer_minutes']))
                                        @if($previewTimer !== null)
                                            <p class="meta m-0">Ustaw sobie kuchenny minutnik na {{ $previewTimer }}.</p>
                                        @endif
                                        @if($previewRow['media_id'] !== null)
                                            <p class="meta m-0">Zdjęcie do tego kroku jest dodane.</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </article>
        </section>
    @endif

    {{-- ------------------------------------------------------------------
         Nawigacja kreatora. „Wstecz”, „Dalej” i „Zapisz szkic” są widoczne
         na każdym kroku; „Wstecz” na kroku 1 jest nieaktywne, ale widoczne
         i wyjaśnione (docs/design/DESIGN_SYSTEM.md → WizardSteps).
    ------------------------------------------------------------------- --}}
    <div class="form-actions">
        <button class="btn btn-secondary" type="button" wire:click="back" @disabled($step === 1)>Wstecz</button>

        @if($step === $this::STEP_PREVIEW)
            <button class="btn btn-primary" type="button" wire:click="publish">{{ $juzOpublikowany ? 'Zapisz szczegóły' : 'Opublikuj przepis' }}</button>
        @else
            <button class="btn btn-primary" type="button" wire:click="next">Dalej</button>
        @endif

        <button class="btn btn-secondary" type="button" wire:click="saveDraft">{{ $juzOpublikowany ? 'Zapisz zmiany' : 'Zapisz szkic' }}</button>
        <a class="btn btn-quiet" href="{{ route('home') }}">Nie teraz</a>
    </div>

    @if($step === 1)
        <p class="field-help">„Wstecz” jest nieaktywne, bo jesteś na pierwszym kroku — przed nim nic nie ma.</p>
    @endif

    <p class="field-help">
        @if($juzOpublikowany)
            Zapisane zmiany widać od razu w przepisie. Do edycji wrócisz ze strony przepisu.
        @else
            Możesz w każdej chwili zamknąć tę stronę. Szkic zostaje na Twoim koncie
            i wrócisz do niego ze strony <a href="{{ route('add') }}">Dodaj</a>.
        @endif
    </p>
</div>
