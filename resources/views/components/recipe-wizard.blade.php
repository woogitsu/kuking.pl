<?php

declare(strict_types=1);

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
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

    /** @var list<array{_key: string, instruction: string}> */
    public array $steps = [];

    public $heroPhoto = null;

    /** Stan plakietki autosave: '' | 'saved' | 'waiting' | 'error'. */
    public string $saveState = '';

    public string $saveMessage = '';

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

        $this->saveDraft();

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
        $this->steps = $this->withoutRow($this->steps, $index);

        if ($this->steps === []) {
            $this->steps = [$this->blankStep()];
        }

        $this->saveDraft();
    }

    public function moveStepUp(int $index): void
    {
        $this->steps = $this->swapRows($this->steps, $index, $index - 1);
        $this->saveDraft();
    }

    public function moveStepDown(int $index): void
    {
        $this->steps = $this->swapRows($this->steps, $index, $index + 1);
        $this->saveDraft();
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

        $this->saveDraft();
    }

    public function saveDraft(): bool
    {
        if ($this->savedThisRequest) {
            return $this->saveState === 'saved';
        }

        $this->storePendingPhoto();

        if (mb_strlen(trim($this->title)) < 3) {
            // Bez nazwy nie da się utworzyć przepisu (PublishRecipe tego pilnuje),
            // więc mówimy wprost, czego brakuje — zamiast cicho nie zapisywać.
            $this->saveState = 'waiting';
            $this->saveMessage = 'Szkic zapisze się, kiedy podasz nazwę przepisu.';

            return false;
        }

        try {
            $this->persist(publish: false);
        } catch (\RuntimeException $e) {
            $this->saveState = 'error';
            $this->saveMessage = 'Nie udało się zapisać szkicu: '.$e->getMessage().' Nic nie zginęło — to, co wpisałeś, jest dalej w formularzu.';

            return false;
        }

        $this->savedThisRequest = true;
        $this->saveState = 'saved';
        $this->saveMessage = 'Szkic zapisany.';

        return true;
    }

    // -----------------------------------------------------------------
    // Publikacja
    // -----------------------------------------------------------------

    public function publish(): void
    {
        $this->resetErrorBag();

        if (! $this->storePendingPhoto()) {
            // Zdjęcie się nie przyjęło. Nie publikujemy w ciszy — człowiek
            // ma zobaczyć dlaczego. Reszta danych zostaje zapisana w szkicu.
            $this->step = 1;
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

        if ($this->cleanIngredients() === []) {
            $this->step = 2;
            $this->addError('ingredients', 'Dodaj przynajmniej jeden składnik, żeby opublikować przepis. Nic nie zginęło — resztę masz zapisaną w szkicu.');
            $this->saveDraft();

            return;
        }

        if ($this->cleanSteps() === []) {
            $this->step = 3;
            $this->addError('steps', 'Opisz przynajmniej jeden krok przygotowania, żeby opublikować przepis. Nic nie zginęło — resztę masz zapisaną w szkicu.');
            $this->saveDraft();

            return;
        }

        try {
            $recipe = $this->persist(publish: true);
        } catch (\RuntimeException $e) {
            $this->addError('publikacja', $e->getMessage());
            $this->saveDraft();

            return;
        }

        session()->flash('status', 'Przepis opublikowany. Teraz ktoś może z niego ugotować.');

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
            $this->recipeId = null;

            return null;
        }

        Gate::authorize('update', $recipe);

        return $recipe;
    }

    /**
     * Wybrane zdjęcie idzie przez zwykły pipeline mediów (magic bytes, limit
     * megapikseli, zdjęcie EXIF w tle). Zwraca false, jeśli plik odpadł.
     */
    private function storePendingPhoto(): bool
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
        } catch (\RuntimeException $e) {
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
            'visibility.required' => 'Zaznacz, kto ma widzieć ten przepis.',
            'visibility.in' => 'Zaznacz, kto ma widzieć ten przepis.',
            'source_type.required' => 'Zaznacz, skąd jest ten przepis.',
            'source_type.in' => 'Zaznacz, skąd jest ten przepis.',
            'source_person.max' => 'To pole jest za długie. Zostaw najwyżej 120 znaków — samo imię wystarczy.',
            'source_note.max' => 'Historia przepisu jest za długa. Zostaw najwyżej 2000 znaków.',
            'source_url.url' => 'Ten adres strony wygląda na niepełny. Powinien zaczynać się od https://',
            'family_since_year.integer' => 'Rok wpisz czterema cyframi, na przykład 1974.',
            'family_since_year.min' => 'Ten rok jest za wczesny. Wpisz rok od 1850.',
            'family_since_year.max' => 'Ten rok jest za późny. Wpisz rok do 2100.',
        ]);

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return false;
        }

        return true;
    }

    private function validateRows(): bool
    {
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
        }

        if ($badIngredient) {
            $this->step = 2;
        } elseif ($badStep) {
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

    /** @return list<array{instruction: string}> */
    public function cleanSteps(): array
    {
        $clean = [];

        foreach ($this->steps as $row) {
            $instruction = trim((string) ($row['instruction'] ?? ''));

            if ($instruction === '') {
                continue;
            }

            $clean[] = ['instruction' => mb_substr($instruction, 0, 4000)];
        }

        return $clean;
    }

    /**
     * Składniki pogrupowane do podglądu („Ciasto”, „Nadzienie”).
     *
     * @return array<string, list<array{text: string, group_name: ?string, note: ?string}>>
     */
    public function groupedIngredients(): array
    {
        $groups = [];

        foreach ($this->cleanIngredients() as $row) {
            $groups[$row['group_name'] ?? ''][] = $row;
        }

        return $groups;
    }

    public function stepName(): string
    {
        return self::STEP_NAMES[$this->step] ?? '';
    }

    public function previewServings(): ?float
    {
        return $this->numberOrNull($this->servings);
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

    /** @return array{_key: string, instruction: string} */
    private function blankStep(): array
    {
        return ['_key' => $this->nextRowKey(), 'instruction' => ''];
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

<div class="stack">
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
        <p class="autosave-badge" data-state="{{ $saveState }}"
           wire:loading.remove wire:target="saveDraft, next, back, publish, addIngredient, addStep, removeIngredient, removeStep">
            @if($saveMessage === '')
                Szkic zapisuje się sam — po każdym kroku i po chwili przerwy w pisaniu.
            @else
                {{ $saveMessage }}
            @endif
        </p>
        <p class="autosave-badge" data-state="saving"
           wire:loading wire:target="saveDraft, next, back, publish, addIngredient, addStep, removeIngredient, removeStep">
            Zapisywanie…
        </p>
    </div>

    {{-- Podsumowanie błędów na górze + link do każdego pola. --}}
    @if($errors->any())
        <div class="error-summary" role="alert" tabindex="-1">
            <p class="error-summary-title">
                @if($errors->count() === 1)
                    Jednej rzeczy jeszcze brakuje
                @else
                    Kilku rzeczy jeszcze brakuje
                @endif
            </p>
            <ul>
                @foreach($errors->keys() as $key)
                    <li><a href="#f-{{ str_replace(['[', ']', '.'], '-', $key) }}">{{ $errors->first($key) }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($step === 1)
        {{-- ==============================================================
             Krok 1 z 3 — o przepisie
        =============================================================== --}}
        <section class="form-section card">
            <h2 class="form-section-title">Krok 1 z {{ $this::STEPS }}: o przepisie</h2>
            <p class="meta mb-4">
                Wystarczy nazwa, żeby ruszyć dalej.
                Jeśli nie masz teraz czasu — zapisz szkic. Nic nie zginie i wrócisz do tego, kiedy zechcesz.
            </p>

            <x-field name="title" label="Nazwa przepisu" required wire="title"
                     :value="$title" placeholder="Rosół babci Zofii" />

            <div class="field">
                <label for="f-heroPhoto">Zdjęcie gotowego dania <span class="meta">(nieobowiązkowe)</span></label>
                <span class="field-help" id="f-heroPhoto-help">To zdjęcie zobaczą ludzie na liście przepisów.</span>
                <input class="field-input" id="f-heroPhoto" type="file" wire:model="heroPhoto"
                       accept="image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif"
                       aria-describedby="f-heroPhoto-help">
                @error('heroPhoto')<span class="field-error">{{ $message }}</span>@enderror
                @if($heroMediaId !== null)
                    <p class="meta mt-2">Zdjęcie jest już dodane. Wybierz plik jeszcze raz, jeśli chcesz je zmienić.</p>
                @endif
            </div>

            <x-field name="summary" label="Krótko o przepisie" type="textarea" :rows="3" wire="summary"
                     :value="$summary"
                     help="Jedno-dwa zdania. Na co ten przepis jest dobry, kiedy go robisz." />

            <div style="display:grid; gap:var(--spacing-4); grid-template-columns:repeat(auto-fit, minmax(12rem, 1fr));">
                <x-field name="servings" label="Na ile porcji" type="number" inputmode="decimal" wire="servings"
                         :value="$servings" :min="0.5" :max="999" :step="0.5" />
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
                <p class="meta mb-4">
                    To najczęściej czytana część przepisu. Ludzie chcą wiedzieć, po kim on jest.
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

                <x-field name="source_person" label="Po kim ten przepis" wire="source_person" :value="$source_person"
                         placeholder="po mamie, Halinie"
                         help="Zostanie podpisany nad tytułem: „przepis Haliny, spisany przez Ciebie”." />

                <x-field name="source_note" label="Historia tego przepisu" type="textarea" :rows="4" wire="source_note"
                         :value="$source_note"
                         help="Skąd go znasz, kiedy się go gotuje, co Ci się z nim wiąże. To zostaje w rodzinie." />

                <x-field name="family_since_year" label="W rodzinie od roku" type="number" inputmode="numeric" wire="family_since_year"
                         :value="$family_since_year" :min="1850" :max="2100" placeholder="1974" />

                <x-field name="source_url" label="Adres strony, z której jest przepis" type="url" wire="source_url"
                         :value="$source_url"
                         help="Podaj, jeśli przepis pochodzi z bloga albo innej strony. Nie publikuj cudzych treści bez zgody." />

                <p class="field-help">
                    Zdjęcie starej kartki albo zeszytu dodasz na
                    <a href="{{ route('recipes.create.simple') }}">formularzu na jednej stronie</a>,
                    a przy zapisanym szkicu — w jego edycji.
                </p>
            </div>
        </section>
    @elseif($step === 2)
        {{-- ==============================================================
             Krok 2 z 3 — składniki
        =============================================================== --}}
        <section class="form-section card">
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

                    <div style="display:grid; gap:var(--spacing-4); grid-template-columns:repeat(auto-fit, minmax(14rem, 1fr));">
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
                        dopiero przy przeliczaniu przepisu na inną liczbę porcji:
                        przepis razy trzy poprosiłby inaczej o trzy szczypty
                        soli i o trzy razy „ile weźmie”. To nie jest drobiazg
                        kosmetyczny — to moment, w którym przepis przestaje
                        wyglądać na napisany przez człowieka.
                    --}}
                    <label class="choice mt-3">
                        <input type="checkbox" wire:model="ingredients.{{ $index }}.no_amount">
                        <span>
                            <span class="choice-label">Bez ilości</span>
                            <span class="choice-help">Zaznacz przy „do smaku”, „ile weźmie”, „szczypta”. Taki składnik nie będzie mnożony, gdy ktoś przeliczy przepis na więcej porcji.</span>
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
        <section class="form-section card">
            <h2 class="form-section-title">Krok 3 z {{ $this::STEPS }}: przygotowanie</h2>
            <p class="meta mb-4">
                Jeden krok to jedna czynność. Krótkie kroki łatwiej czytać przy garnku.
                Puste wiersze zostaną pominięte.
                Przyciski „Przenieś w górę” i „Przenieś w dół” są nieaktywne tam, gdzie nie ma już gdzie przenosić.
            </p>

            @error('steps')<p class="field-error mb-4">{{ $message }}</p>@enderror

            @foreach($steps as $index => $row)
                <div class="wizard-row" wire:key="krok-{{ $row['_key'] ?? $index }}">
                    <x-field :name="'steps.'.$index.'.instruction'" :label="'Krok '.($index + 1)" type="textarea" :rows="3"
                             :wire="'steps.'.$index.'.instruction'" :value="$row['instruction'] ?? ''"
                             :placeholder="$index === 0 ? 'Kurczaka zalej zimną wodą i zagotuj. Zbierz szumowiny.' : null" />

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
             Podgląd — dokładnie to, co zobaczą inni
        =============================================================== --}}
        <section class="form-section card">
            <h2 class="form-section-title">Podgląd: tak zobaczą to inni</h2>
            <p class="meta mb-4">
                Sprawdź spokojnie. Jeśli coś jest nie tak, wróć przyciskiem „Wstecz” — nic nie zginie.
            </p>

            @error('publikacja')<p class="field-error mb-4">{{ $message }}</p>@enderror

            <article class="stack">
                <h3 style="font-size:var(--text-title); margin:0;">{{ trim($title) !== '' ? trim($title) : 'Przepis bez nazwy' }}</h3>

                <ul class="recipe-facts">
                    @if($this->previewServings() !== null)
                        <li><span class="badge">{{ (int) $this->previewServings() }} porcji</span></li>
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
                    <p style="font-size:var(--text-lead);">{{ trim($summary) }}</p>
                @endif

                @if(trim($source_person) !== '' || trim($source_note) !== '')
                    <section class="recipe-story">
                        <h4 style="margin-top:0; font-size:var(--text-title-sm);">Skąd ten przepis</h4>
                        @if(trim($source_person) !== '')
                            <p><strong>Po {{ trim($source_person) }}.</strong></p>
                        @endif
                        @if(trim($source_note) !== '')
                            <p style="white-space:pre-line; margin-bottom:0;">{{ trim($source_note) }}</p>
                        @endif
                    </section>
                @endif

                <section>
                    <h4 style="font-size:var(--text-title-sm);">Składniki</h4>
                    @php($previewGroups = $this->groupedIngredients())
                    @if($previewGroups === [])
                        <p class="field-error">Nie ma jeszcze żadnego składnika. Wróć do kroku 2 i dopisz przynajmniej jeden.</p>
                    @else
                        @foreach($previewGroups as $groupName => $groupRows)
                            @if($groupName !== '')
                                <h5 style="font-size:var(--text-body-lg); margin-bottom:var(--spacing-2);">{{ $groupName }}</h5>
                            @endif
                            <ul class="ingredient-list">
                                @foreach($groupRows as $groupRow)
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
                    <h4 style="font-size:var(--text-title-sm);">Przygotowanie</h4>
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
                                        <p style="margin:0; white-space:pre-line;">{{ $previewRow['instruction'] }}</p>
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
            <button class="btn btn-primary" type="button" wire:click="publish">Opublikuj przepis</button>
        @else
            <button class="btn btn-primary" type="button" wire:click="next">Dalej</button>
        @endif

        <button class="btn btn-secondary" type="button" wire:click="saveDraft">Zapisz szkic</button>
        <a class="btn btn-quiet" href="{{ route('home') }}">Nie teraz</a>
    </div>

    @if($step === 1)
        <p class="field-help">„Wstecz” jest nieaktywne, bo jesteś na pierwszym kroku — przed nim nic nie ma.</p>
    @endif

    <p class="field-help">
        Możesz w każdej chwili zamknąć tę stronę. Szkic zostaje na Twoim koncie
        i wrócisz do niego ze strony <a href="{{ route('add') }}">Dodaj</a>.
    </p>
</div>
