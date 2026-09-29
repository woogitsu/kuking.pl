<?php

declare(strict_types=1);

use App\Domain\Import\PodobienstwoDoZrodla;
use App\Domain\Import\BramkaPublikacjiOdczytu;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\ExistingStepDuplicates;
use App\Domain\Recipes\GrupySkladnikow;
use App\Domain\Recipes\StepTimer;
use App\Exceptions\BladDlaCzlowieka;
use App\Livewire\Forms\PrzepisForm;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use App\Domain\Recipes\KosztPrzepisu;
use App\Support\KreatorPrzepisu\DanePublikacji;
use App\Support\KreatorPrzepisu\KrokOPrzepisie;
use App\Support\KreatorPrzepisu\PodgladPrzepisu;
use App\Support\KreatorPrzepisu\WierszePrzepisu;
use App\Support\KreatorPrzepisu\ZdjeciaKreatora;
use Illuminate\Support\Facades\Gate;
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
 *    (wire:model.live.debounce.3000ms → hook updated() → autozapis(), bez wersji przepisu),
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

    /**
     * Szkic z importu (D-300): `url`, `pdf` albo `zdjecie`; `null` = zwykły
     * przepis. `#[Locked]`, bo decyduje o zablokowanym źródle i o bramce
     * „Sprawdziłem odczytany tekst" — i tak pilnuje ich `PublishRecipe`,
     * a to pole tylko rysuje baner i pole wyboru.
     */
    #[Locked]
    public ?string $zrodloImportu = null;

    /** Czy przed publikacją trzeba zaznaczyć „Sprawdziłem odczytany tekst". */
    #[Locked]
    public bool $wymagaSprawdzenia = false;

    public bool $sprawdzilemOdczyt = false;

    /**
     * Szkic z odczytu zdjęcia kartki (V2, D-298): baner, zdjęcie obok pól
     * i bramka „Odczytany tekst jest sprawdzony” przed publikacją. `#[Locked]`,
     * bo o tym, czy bramka obowiązuje, decyduje baza, nie przeglądarka —
     * a ostatecznie i tak `BramkaPublikacjiOdczytu` w `PublishRecipe`.
     */
    #[Locked]
    public bool $zOdczytu = false;

    /** Pole „Odczytany tekst jest sprawdzony ze zdjęciem” na podglądzie. */
    public bool $odczytSprawdzony = false;

    /**
     * Wszystkie pola kroku „o przepisie” — nazwa, krótki opis, porcje, koszt,
     * czasy, trudność, widoczność i „Skąd ten przepis” — w Livewire Form
     * Object (issue #1387, kroki 3 i 6). W Livewire żyją pod `form.<pole>`,
     * także w kluczach błędów.
     */
    public PrzepisForm $form;

    /**
     * Wersja kształtu stanu komponentu, ustawiana w `mount()`.
     *
     * Karta otwarta PRZED wdrożeniem kroku 3 odsyła migawkę bez `form`,
     * a Livewire pomija nieznane klucze i tworzy PUSTY obiekt formularza.
     * Pierwszy autozapis takiej karty nadpisałby przepis domyślnymi
     * wartościami — prywatny przepis stałby się publiczny, a historia
     * przepisu by znikła. Stara migawka nie ma tego pola (zostaje 0), więc
     * `hydrate()` odmawia, zanim cokolwiek się zapisze. Zapisany szkic
     * zostaje nietknięty w bazie. Każda kolejna zmiana kształtu stanu
     * (np. przeniesienie kolejnych pól) PODBIJA `WERSJA_STANU`.
     */
    #[Locked]
    public int $wersjaStanu = 0;

    public const WERSJA_STANU = 4;

    /** @var list<array{_key: string, group_name: string, text: string, note: string, substitutes: string, no_amount: bool}> */
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

    /** Rewizja treści z bazy; różna od licznika zmian interfejsu. */
    #[Locked]
    public int $contentRevision = 0;

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
        $this->wersjaStanu = self::WERSJA_STANU;

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

    /**
     * Hook Livewire: każde żądanie po pierwszym renderze, PRZED zastosowaniem
     * zmian pól i wywołaniem akcji. Migawka sprzed kroku 3 (patrz
     * `$wersjaStanu`) dostaje 419 — ten sam kod, co wygasła strona, więc
     * przeglądarka poprosi o odświeżenie, a nic się nie zapisze.
     */
    public function hydrate(): void
    {
        if ($this->wersjaStanu !== self::WERSJA_STANU) {
            abort(419);
        }
    }

    private function fillFrom(Recipe $recipe): void
    {
        $this->recipeId = $recipe->getKey();
        $this->contentRevision = $recipe->content_revision;
        $this->juzOpublikowany = $recipe->isPublished();

        $pochodzenie = PrzepisZImportu::query()->find($recipe->getKey());
        $this->zrodloImportu = $pochodzenie?->zrodlo;
        $this->wymagaSprawdzenia = $pochodzenie !== null && ! $pochodzenie->sprawdzony() && ! $recipe->isPublished();
        $this->zOdczytu = ! $this->juzOpublikowany && BramkaPublikacjiOdczytu::maOdczyt($recipe);
        $this->heroMediaId = $recipe->hero_media_id;
        $this->sourceScanMediaId = $recipe->source_scan_media_id;

        $this->form->title = (string) $recipe->title;
        $this->form->summary = (string) $recipe->summary;
        $this->form->servings = PodgladPrzepisu::liczbaNaTekst($recipe->servings);
        $this->form->estimated_cost_pln = KosztPrzepisu::doPola($recipe->estimated_cost_pln);
        $this->form->prep_minutes = PodgladPrzepisu::liczbaNaTekst($recipe->prep_minutes);
        $this->form->cook_minutes = PodgladPrzepisu::liczbaNaTekst($recipe->cook_minutes);
        $this->form->difficulty = (string) $recipe->difficulty;
        $this->form->visibility = (string) ($recipe->visibility ?: 'public');
        $this->form->source_type = (string) ($recipe->source_type ?: Recipe::SOURCE_OWN);
        $this->form->source_person = (string) $recipe->source_person;
        $this->form->source_note = (string) $recipe->source_note;
        $this->form->source_url = (string) $recipe->source_url;
        $this->form->family_since_year = PodgladPrzepisu::liczbaNaTekst($recipe->family_since_year);

        $this->ingredients = $recipe->ingredients
            ->map(fn ($row): array => [
                '_key' => $this->nextRowKey(),
                'group_name' => (string) $row->group_name,
                'text' => (string) $row->ingredient_text,
                'note' => (string) $row->note,
                'substitutes' => (string) $row->substitutes,
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
                'timer_minutes' => PodgladPrzepisu::liczbaNaTekst(StepTimer::minutesFromSeconds($row->timer_seconds)),
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

        if (! $this->autozapis()) {
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
        $this->autozapis();

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
            $key === 'publikacja', $key === 'odczyt_sprawdzony' => self::STEP_PREVIEW,
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
        $this->autozapis();
    }

    public function removeIngredient(int $index): void
    {
        $this->ingredients = WierszePrzepisu::bezWiersza($this->ingredients, $index);

        if ($this->ingredients === []) {
            $this->ingredients = [$this->blankIngredient()];
        }

        $this->autozapis();
    }

    public function moveIngredientUp(int $index): void
    {
        $this->ingredients = WierszePrzepisu::zamien($this->ingredients, $index, $index - 1);
        $this->autozapis();
    }

    public function moveIngredientDown(int $index): void
    {
        $this->ingredients = WierszePrzepisu::zamien($this->ingredients, $index, $index + 1);
        $this->autozapis();
    }

    // -----------------------------------------------------------------
    // Wiersze przygotowania (issue #13)
    // -----------------------------------------------------------------

    public function addStep(): void
    {
        $this->steps[] = $this->blankStep();
        $this->autozapis();
    }

    public function removeStep(int $index): void
    {
        $this->replaceSteps(WierszePrzepisu::bezWiersza($this->steps, $index));

        if ($this->steps === []) {
            $this->steps = [$this->blankStep()];
        }

        $this->autozapis();
    }

    public function moveStepUp(int $index): void
    {
        $this->replaceSteps(WierszePrzepisu::zamien($this->steps, $index, $index - 1));
        $this->autozapis();
    }

    public function moveStepDown(int $index): void
    {
        $this->replaceSteps(WierszePrzepisu::zamien($this->steps, $index, $index + 1));
        $this->autozapis();
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
        $this->autozapis();
    }

    /**
     * „Zapisz zmiany” / „Zapisz szkic” — świadomy zapis człowieka.
     *
     * Na opublikowanym przepisie TYLKO ta droga (i `wyjdz()`) zostawia nową
     * wersję w historii (decyzja właściciela z 24.09.2026, issue #1316).
     * Autozapis po ~3 s, „Dalej” i „Wstecz” zapisują treść bez wersji —
     * inaczej każda pauza w pisaniu dawałaby wersję, a sklejanie ich
     * w jedną nadpisywałoby historię, której nadpisywać nie wolno.
     */
    public function saveDraft(): bool
    {
        return $this->zapiszSzkic(wersja: true);
    }

    /**
     * Wyjście z kreatora na opublikowanym przepisie: zapis z wersją i powrót.
     * Nieudany zapis zostawia człowieka w formularzu z komunikatem — nic nie
     * znika po cichu. Bez JavaScriptu link prowadzi zwykłym `href`.
     */
    public function wyjdz(): void
    {
        if (! $this->zapiszSzkic(wersja: true)) {
            return;
        }

        $this->redirect(route('home'));
    }

    /** Zapis w tle (pola, „Dalej”, „Wstecz”) — nigdy nie tworzy wersji przepisu. */
    private function autozapis(): bool
    {
        return $this->zapiszSzkic(wersja: false);
    }

    private function zapiszSzkic(bool $wersja): bool
    {
        $this->acknowledgedRevision = $this->editRevision;

        if ($this->savedThisRequest) {
            /*
             * Livewire wysyła zmianę pola i kliknięcie „Zapisz zmiany” jednym
             * żądaniem: `updated()` zapisał już treść autozapisem (bez wersji).
             * Świadomy zapis nie może przez to zgubić swojej wersji.
             */
            if ($wersja && $this->saveState === 'saved' && ($recipe = $this->existingRecipe()) !== null && $recipe->isPublished()) {
                app(SnapshotRecipeVersion::class)->poprawka($recipe, auth()->user());
            }

            return $this->saveState === 'saved';
        }

        if (! $this->validateExistingStepIds()) {
            return false;
        }

        $this->storePendingPhotos();

        if (mb_strlen(trim($this->form->title)) < 3) {
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
            $this->persist(publish: false, wersjaPoprawki: $wersja);
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

        if (! $this->validateExistingStepIds()) {
            $this->step = 3;

            return;
        }

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
            $this->autozapis();

            return;
        }

        if (! $this->validateAboutStep()) {
            $this->step = 1;
            $this->autozapis();

            return;
        }

        if (! $this->validateRows()) {
            $this->autozapis();

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
            $this->autozapis();

            return;
        }

        // Szkic z odczytu kartki (D-298): błąd przy WŁAŚCIWYM wierszu
        // i w podsumowaniu, zanim w ogóle zapytamy bazę. Ta sama reguła stoi
        // w `PublishRecipe` jako ostatnia linia.
        // Szkic zapisujemy PRZED sprawdzeniem: walidacja wierszy w
        // `saveDraft()` czyści błędy pól, więc odwrotna kolejność
        // zjadałaby komunikat przy wierszu ze znacznikiem.
        if ($this->zOdczytu) {
            $this->saveDraft();

            if (! $this->sprawdzOdczyt()) {
                return;
            }
        }

        try {
            $recipe = $this->persist(publish: true);
        } catch (BladDlaCzlowieka $e) {
            $this->addError('publikacja', $e->getMessage());
            $this->autozapis();

            return;
        }

        \App\Support\Komunikat::wSesji(session()->driver(), \App\Support\Komunikat::sukces($this->juzOpublikowany
            ? 'Szczegóły zapisane.'
            : match ($recipe->visibility) {
                'private' => 'Przepis zapisany. Widzisz go tylko Ty.',
                'followers' => 'Przepis opublikowany dla osób, które Cię obserwują.',
                default => 'Przepis opublikowany. Teraz ktoś może z niego ugotować.',
            }));

        $this->redirect(route('recipes.show', $recipe->slug));
    }

    // -----------------------------------------------------------------
    // Zapis do bazy
    // -----------------------------------------------------------------

    private function validateExistingStepIds(): bool
    {
        try {
            $recipe = $this->existingRecipe();
        } catch (BladDlaCzlowieka $e) {
            $this->addError('publikacja', $e->getMessage());
            $this->saveState = 'error';
            $this->saveMessage = $e->getMessage();

            return false;
        }

        $errors = ExistingStepDuplicates::errors($this->steps, $recipe?->steps()->pluck('id') ?? []);
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            $this->saveState = 'error';
            $this->saveMessage = 'Nie zapisaliśmy tych zmian. Popraw zaznaczone pola. Cały tekst jest nadal w formularzu.';

            return false;
        }

        return true;
    }

    private function persist(bool $publish, bool $wersjaPoprawki = false): Recipe
    {
        $recipe = app(PublishRecipe::class)->handle(
            author: auth()->user(),
            attributes: DanePublikacji::atrybuty(
                title: $this->form->title,
                summary: $this->form->summary,
                servings: $this->form->servings,
                estimatedCostPln: $this->form->estimated_cost_pln,
                prepMinutes: $this->form->prep_minutes,
                cookMinutes: $this->form->cook_minutes,
                difficulty: $this->form->difficulty,
                visibility: $this->form->visibility,
                sourceType: $this->form->source_type,
                sourcePerson: $this->form->source_person,
                sourceNote: $this->form->source_note,
                sourceUrl: $this->form->source_url,
                familySinceYear: $this->form->family_since_year,
                heroMediaId: $this->heroMediaId,
                sourceScanMediaId: $this->sourceScanMediaId,
                sprawdzilemOdczyt: $this->sprawdzilemOdczyt,
                odczytSprawdzony: $this->odczytSprawdzony,
            ),
            ingredients: $this->cleanIngredients(),
            steps: $this->cleanSteps(),
            publish: $publish,
            existing: $this->existingRecipe(),
            oczekiwanaRewizja: $this->recipeId === null ? null : $this->contentRevision,
            wersjaPoprawki: $wersjaPoprawki,
            ip: request()->ip(),
        );

        $this->recipeId = $recipe->getKey();
        $this->contentRevision = $recipe->content_revision;

        return $recipe;
    }

    /**
     * Znaczniki `[?…?]` przy konkretnym wierszu i pole „Tekst sprawdzony” (D-298).
     * Indeksy wierszy są indeksami formularza, więc link w podsumowaniu
     * prowadzi dokładnie do pola ze znacznikiem.
     */
    private function sprawdzOdczyt(): bool
    {
        $ok = true;

        if (str_contains($this->form->title, BramkaPublikacjiOdczytu::ZNACZNIK)) {
            $this->addError('form.title', 'Sprawdź słowo oznaczone [?] w nazwie przepisu i usuń znaczniki [? ?].');
            $ok = false;
        }

        if (str_contains($this->form->summary, BramkaPublikacjiOdczytu::ZNACZNIK)) {
            $this->addError('form.summary', 'Sprawdź słowo oznaczone [?] w opisie przepisu i usuń znaczniki [? ?].');
            $ok = false;
        }

        foreach ($this->ingredients as $index => $row) {
            if (str_contains((string) ($row['text'] ?? ''), BramkaPublikacjiOdczytu::ZNACZNIK)) {
                $this->addError('ingredients.'.$index.'.text', 'Sprawdź słowo oznaczone [?] w '.($index + 1).'. składniku i usuń znaczniki [? ?].');
                $ok = false;
            }
        }

        foreach ($this->steps as $index => $row) {
            if (str_contains((string) ($row['instruction'] ?? ''), BramkaPublikacjiOdczytu::ZNACZNIK)) {
                $this->addError('steps.'.$index.'.instruction', 'Sprawdź słowo oznaczone [?] w '.($index + 1).'. kroku i usuń znaczniki [? ?].');
                $ok = false;
            }
        }

        if (! $this->odczytSprawdzony) {
            $this->addError('odczyt_sprawdzony', BramkaPublikacjiOdczytu::KOMUNIKAT_SPRAWDZENIE);
            $ok = false;
        }

        if (! $ok) {
            $pierwszy = (string) collect($this->getErrorBag()->keys())->first();
            $this->step = $this->stepForKey($pierwszy);
        }

        return $ok;
    }

    /** Ile znaczników `[?` stoi jeszcze w polach — dla banera. */
    public function niepewnych(): int
    {
        return BramkaPublikacjiOdczytu::ileNiepewnych(
            $this->form->title,
            $this->form->summary,
            ...array_map(fn (array $r): string => (string) ($r['text'] ?? ''), $this->ingredients),
            ...array_map(fn (array $r): string => (string) ($r['instruction'] ?? ''), $this->steps),
        );
    }

    /** Zdjęcie kartki do pokazania obok pól — tylko przy szkicu z odczytu. */
    public function skanOdczytu(): ?\App\Models\Media
    {
        return $this->zOdczytu && $this->sourceScanMediaId !== null
            ? \App\Models\Media::query()->find($this->sourceScanMediaId)
            : null;
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
     * Samo przyjmowanie plików robi `ZdjeciaKreatora` (issue #1387, krok 7);
     * tu zostaje to, co należy do Livewire'a: wybrane pliki zerujemy w stanie
     * PRZED przyjęciem (odrzucony plik nie może blokować każdego następnego
     * autosave'u tym samym błędem), a wynik przenosimy do stanu i do worka
     * błędów pod kluczami pól (`heroPhoto`, `steps.N.photo`, #1572).
     * Dotychczasowe `heroMediaId` i `steps.N.mediaId` ruszamy tylko wtedy,
     * gdy nowe zdjęcie się przyjęło — po błędzie zachowane zdjęcie zostaje.
     */
    private function storePendingPhotos(): bool
    {
        $glowne = $this->heroPhoto;
        $this->heroPhoto = null;

        $kroki = [];
        foreach ($this->steps as $index => $row) {
            $kroki[$index] = $row['photo'] ?? null;
            if ($kroki[$index] !== null) {
                $this->steps[$index]['photo'] = null;
            }
        }

        $wynik = app(ZdjeciaKreatora::class)->przyjmij(auth()->user(), $glowne, $kroki);

        if ($wynik->mediaIdGlownego !== null) {
            $this->heroMediaId = $wynik->mediaIdGlownego;
        }

        foreach ($wynik->mediaIdKrokow as $index => $mediaId) {
            $this->steps[$index]['mediaId'] = $mediaId;
        }

        foreach ($wynik->bledy as $klucz => $komunikat) {
            $this->addError($klucz, $komunikat);
        }

        return $wynik->udany();
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

        $this->autozapis();
    }

    // -----------------------------------------------------------------
    // Walidacja (komunikaty po polsku, mówiące CO ZROBIĆ)
    // -----------------------------------------------------------------

    private function validateAboutStep(): bool
    {
        // Reguły, komunikaty i normalizacja pól tego kroku mają jedno nazwane
        // źródło: `KrokOPrzepisie` (issue #1387). Tu zostaje orkiestracja.
        //
        // #900: dawny adres porównujemy z BAZĄ, nie ze stanem komponentu:
        // autozapis nie może zrobić z dopiero wpisanego FTP „historycznego
        // wyjątku”.
        $dawnyAdres = $this->recipeId === null ? null : Recipe::whereKey($this->recipeId)->value('source_url');

        // Wszystkie pola kroku są w `$form`. `KrokOPrzepisie` dostaje je pod
        // nazwami bez przedrostka, a klucze błędów wracają do worka jako
        // `form.<pole>`.
        $pola = $this->form->pola();
        $validator = KrokOPrzepisie::walidator($pola, $dawnyAdres);

        // Ponowna walidacja usuwa stare błędy tylko tych pól. Nie kasuje
        // komunikatu zdjęcia ani innego etapu; poprawka pola odblokowuje zapis.
        $this->resetErrorBag(array_map(PrzepisForm::kluczBledu(...), array_keys($validator->getData())));
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError(PrzepisForm::kluczBledu($key), $message);
                }
            }

            return false;
        }

        return true;
    }

    private function validateRows(bool $changeStep = true): bool
    {
        // Granice, komunikaty i normalizacja wierszy mają jedno nazwane
        // źródło: `WierszePrzepisu` (issue #1387, krok 2). Tu zostaje
        // orkiestracja: worek błędów i wybór kroku do pokazania.
        $this->resetErrorBag(WierszePrzepisu::KLUCZE_BLEDOW);
        $bledySkladnikow = WierszePrzepisu::bledySkladnikow($this->ingredients);
        $bledyKrokow = WierszePrzepisu::bledyKrokow($this->steps);
        $badIngredient = $bledySkladnikow !== [];
        $badStep = $bledyKrokow !== [];

        foreach ([...$bledySkladnikow, ...$bledyKrokow] as $klucz => $komunikat) {
            $this->addError($klucz, $komunikat);
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
     * @return list<array{text: string, group_name: ?string, note: ?string, substitutes: ?string, no_amount: bool}>
     */
    public function cleanIngredients(): array
    {
        return WierszePrzepisu::skladniki($this->ingredients);
    }

    /**
     * `id` zawsze null — zdjęcie kroku niesie `mediaId` w wierszu
     * (uzasadnienie w `WierszePrzepisu::kroki()`).
     *
     * @return list<array{id: null, instruction: string, timer_minutes: string, media_id: ?string}>
     */
    public function cleanSteps(): array
    {
        return WierszePrzepisu::kroki($this->steps);
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
     * @return list<array{nazwa: ?string, skladniki: list<array{text: string, group_name: ?string, note: ?string, substitutes: ?string}>}>
     */
    public function groupedIngredients(): array
    {
        return GrupySkladnikow::ulozyc($this->cleanIngredients());
    }

    public function stepName(): string
    {
        return self::STEP_NAMES[$this->step] ?? '';
    }

    /** Nagłówek zależy od wybranej widoczności (`PodgladPrzepisu::naglowek()`). */
    public function previewHeading(): string
    {
        return PodgladPrzepisu::naglowek($this->form->visibility);
    }

    public function previewServings(): ?float
    {
        return PodgladPrzepisu::liczbaLubNull($this->form->servings);
    }

    /** Liczba porcji do podglądu — ten sam kod co znaczek na stronie przepisu. */
    public function previewServingsLabel(): ?string
    {
        return PodgladPrzepisu::etykietaPorcji($this->form->servings);
    }

    /** Etykieta minutnika do podglądu — ten sam kod co w trybie gotowania. */
    public function previewTimerLabel(mixed $minutes): ?string
    {
        return PodgladPrzepisu::etykietaMinutnika($minutes);
    }

    /** Zdanie o koszcie do podglądu — to samo co na stronie przepisu (D-286). */
    public function previewCostLabel(): ?string
    {
        return PodgladPrzepisu::etykietaKosztu($this->form->estimated_cost_pln);
    }

    /** Ta sama reguła co na stronie przepisu i w filtrze „Do 30 minut” (#1090). */
    public function totalMinutes(): ?int
    {
        return PodgladPrzepisu::czasRazem($this->form->prep_minutes, $this->form->cook_minutes);
    }

    // -----------------------------------------------------------------
    // Drobne narzędzia
    // -----------------------------------------------------------------

    /** @return array{_key: string, group_name: string, text: string, note: string, substitutes: string, no_amount: bool} */
    private function blankIngredient(): array
    {
        return ['_key' => $this->nextRowKey(), 'group_name' => '', 'text' => '', 'note' => '', 'substitutes' => '', 'no_amount' => false];
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
};
?>

{{-- `data-kreator-zapis` czyta `resources/js/strona-nieaktualna.js`, gdy
     żądanie dostanie 419 (#977): komunikat nie może obiecać, że szkic jest
     bezpieczny, jeśli żaden się jeszcze nie zapisał. --}}
<div class="stack"
     data-kreator-zapis="{{ $recipeId === null ? 'brak' : ($juzOpublikowany ? 'opublikowany' : 'szkic') }}"
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

    @if($zrodloImportu !== null && ! $juzOpublikowany)
        {{-- Baner szkicu z importu (D-300) — na KAŻDYM kroku. --}}
        <div class="notice" role="note">
            <p class="mt-0 mb-0">
                <strong>Ten tekst odczytał komputer{{ $zrodloImportu === 'url' ? ' ze strony internetowej' : ($zrodloImportu === 'pdf' ? ' z pliku PDF' : ' ze zdjęcia') }}.</strong>
                Porównaj każdą linijkę ze źródłem i popraw, co trzeba. Nic się nie opublikuje, dopóki nie klikniesz „Opublikuj przepis”.
                @if($zrodloImportu === 'url')
                    Opis przygotowania napisz własnymi słowami — adres strony zostaje przy przepisie jako źródło.
                @endif
            </p>
        </div>
    @endif

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
                         NIE ZAPISUJE NICZEGO (warunek `mb_strlen(trim($form->title)) < 3`
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

    @if($zOdczytu)
        @include('pages.import.partials.baner', ['niepewnych' => $this->niepewnych()])
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

            <x-field name="form.title" label="Nazwa przepisu" required wire="form.title"
                     :value="$form->title" placeholder="Rosół babci Zofii" />

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
                       @error('heroPhoto') aria-invalid="true" aria-describedby="f-heroPhoto-help f-heroPhoto-error" @else aria-describedby="f-heroPhoto-help" @enderror>
                <label class="pole-zdjecia" for="f-heroPhoto">
                    <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                    <span class="pole-zdjecia-tytul" id="f-heroPhoto-tytul">{{ $heroMediaId !== null ? 'Zmień zdjęcie' : 'Dodaj zdjęcie' }}</span>
                    <span class="field-help" id="f-heroPhoto-help">To zdjęcie zobaczą ludzie na liście przepisów.</span>
                </label>
                @error('heroPhoto')<span class="field-error" id="f-heroPhoto-error">{{ $message }}</span>@enderror
                @if($heroMediaId !== null)
                    <p class="meta mt-2">Zdjęcie jest już dodane. Wybierz plik jeszcze raz, jeśli chcesz je zmienić.</p>
                @endif
            </div>

            <x-field name="form.summary" label="Krótko o przepisie" type="textarea" :rows="3" wire="form.summary"
                     :value="$form->summary"
                     help="Jedno-dwa zdania. Na co ten przepis jest dobry, kiedy go robisz." />

            <div class="siatka-pol">
                {{-- Krok 0,01 musi się zgadzać z regułą `decimal:0,2` wyżej (#750),
                     inaczej zapisana 1,25 jest dla przeglądarki `stepMismatch`. --}}
                <x-field name="form.servings" label="Na ile porcji" type="number" inputmode="decimal" wire="form.servings"
                         :value="$form->servings" :min="0.5" :max="999" :step="0.01" />
                <x-field name="form.prep_minutes" label="Przygotowanie (minuty)" type="number" inputmode="numeric" wire="form.prep_minutes"
                         :value="$form->prep_minutes" :min="0" :max="10080" />
                <x-field name="form.cook_minutes" label="Gotowanie / pieczenie (minuty)" type="number" inputmode="numeric" wire="form.cook_minutes"
                         :value="$form->cook_minutes" :min="0" :max="10080" />
            </div>

            {{-- Koszt wg autora (D-286) — pole tekstowe, bo „24,50" z przecinkiem
                 ma przejść (uzasadnienie przy tym samym polu w `szczegoly.blade.php`). --}}
            <x-field name="form.estimated_cost_pln" label="Przybliżony koszt całego przepisu (zł)" inputmode="decimal" wire="form.estimated_cost_pln"
                     :value="$form->estimated_cost_pln"
                     help="Ile mniej więcej kosztują składniki na cały przepis. Wpisz samą liczbę złotych, na przykład 24 albo 24,50. Na stronie przepisu pokażemy to jako szacunek autora." />

            {{-- `id` jest CELEM odnośnika z podsumowania błędów i zdarzenia
                 `kreator-fokus-pole`, a atrybuty ARIA wiążą błąd z grupą —
                 patrz `x-blad-grupy`. Bez `id` odnośnik do błędu grupy
                 wyboru nie prowadził nigdzie (issue #1387, krok 3). --}}
            <fieldset class="border-0 p-0 mt-6" id="f-form-difficulty"
                      @error('form.difficulty') tabindex="-1" aria-invalid="true" aria-describedby="f-form-difficulty-error" @enderror>
                <legend class="font-bold mb-3">Jak trudny jest ten przepis?</legend>
                <div class="choice-grid">
                    @foreach(\App\Models\Recipe::DIFFICULTY_LABELS as $value => $label)
                        <label class="choice">
                            <input type="radio" wire:model="form.difficulty" value="{{ $value }}">
                            <span class="choice-label">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <x-blad-grupy name="form.difficulty" />
            </fieldset>

            <fieldset class="border-0 p-0 mt-6" id="f-form-visibility"
                      @error('form.visibility') tabindex="-1" aria-invalid="true" aria-describedby="f-form-visibility-error" @enderror>
                <legend class="font-bold mb-3">Kto ma widzieć ten przepis?</legend>
                <div class="choice-grid">
                    <label class="choice">
                        <input type="radio" wire:model="form.visibility" value="public">
                        <span><span class="choice-label">Wszyscy</span><span class="choice-help">Także osoby bez konta. Przepis może pojawić się w Google.</span></span>
                    </label>
                    <label class="choice">
                        <input type="radio" wire:model="form.visibility" value="followers">
                        <span><span class="choice-label">Tylko osoby, które mnie obserwują</span></span>
                    </label>
                    <label class="choice">
                        <input type="radio" wire:model="form.visibility" value="private">
                        <span><span class="choice-label">Tylko ja</span><span class="choice-help">Twój prywatny zeszyt.</span></span>
                    </label>
                </div>
                <x-blad-grupy name="form.visibility" />
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

                <fieldset class="border-0 p-0" id="f-form-source_type"
                          @error('form.source_type') tabindex="-1" aria-invalid="true" aria-describedby="f-form-source_type-error" @enderror>
                    <legend class="font-bold mb-3">Ten przepis jest…</legend>
                    @if($zrodloImportu === 'url')
                        <p class="field-help">Ten przepis pochodzi ze strony {{ $form->source_url }} — źródła szkicu zapisanego ze strony nie da się zmienić.</p>
                    @endif
                    <div class="choice-grid">
                        @foreach(\App\Models\Recipe::SOURCE_LABELS as $value => $label)
                            <label class="choice">
                                <input type="radio" wire:model="form.source_type" value="{{ $value }}" @disabled($zrodloImportu === 'url')>
                                <span class="choice-label">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-blad-grupy name="form.source_type" />
                </fieldset>

                {{-- PYTAMY O FRAZĘ, KTÓRA STOI SAMODZIELNIE — uzasadnienie
                     przy tym samym polu w `pages/recipes/szczegoly.blade.php`. --}}
                <x-field name="form.source_person" label="Od kogo albo skąd masz ten przepis" wire="form.source_person" :value="$form->source_person"
                         placeholder="od mamy · z gazety · z bloga Nasze smaki"
                         help="Napisz to tak, żeby dało się przeczytać samo: „od mamy”, „z gazety”, „od sąsiadki Haliny”. Pokażemy to przy przepisie dokładnie tak, jak wpiszesz." />

                {{-- POMOC JEST PRAWDZIWA PRZY KAŻDEJ Z TRZECH WIDOCZNOŚCI.

                     Stało tu „To zostaje w rodzinie." — nieprawda przy
                     przepisie publicznym, a taki jest tu domyślny
                     (`PrzepisForm::$visibility = 'public'`). Zdanie zależne od
                     `visibility` byłoby tutaj gorsze niż neutralne: pole
                     stoi na tym samym kroku co wybór widoczności, a radia
                     mają zwykły `wire:model` (bez `.live`), więc wartość
                     w kolejnej odpowiedzi bywa o jedno kliknięcie z tyłu.
                     Zdanie zależne od stanu, który chwilami jest nieaktualny,
                     zamieniłoby jedną nieprawdę na drugą, trudniejszą do
                     złapania. To jest prawdziwe zawsze. --}}
                <x-field name="form.source_note" label="Historia tego przepisu" type="textarea" :rows="4" wire="form.source_note"
                         :value="$form->source_note"
                         help="Skąd go znasz, kiedy się go gotuje, co Ci się z nim wiąże. Ta historia jest częścią przepisu — zobaczy ją każdy, kto zobaczy przepis." />

                <x-field name="form.family_since_year" label="W rodzinie od roku" type="number" inputmode="numeric" wire="form.family_since_year"
                         :value="$form->family_since_year" :min="1850" :max="2100" placeholder="1974" />

                <x-field name="form.source_url" label="Adres strony, z której jest przepis" type="url" wire="form.source_url"
                         :value="$form->source_url"
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

            @if($zOdczytu)
                @include('pages.import.partials.oryginal', ['skan' => $this->skanOdczytu()])
            @endif

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
                                 placeholder="najlepiej wiejskie" />
                    </div>

                    {{-- Zamiennik od autora (D-284) — widz zobaczy go pod
                         składnikiem jako „Zamiast tego: …”. Nieobowiązkowy. --}}
                    <x-field :name="'ingredients.'.$index.'.substitutes'" label="Czym można to zastąpić (nieobowiązkowe)"
                             :wire="'ingredients.'.$index.'.substitutes'" :value="$row['substitutes'] ?? ''"
                             placeholder="margaryna albo olej kokosowy" />

                    {{--
                        „BEZ ILOŚCI” — SÓL DO SMAKU (issue #44).

                        Nieobowiązkowe i domyślnie wyłączone. Ma znaczenie
                        dla przeliczania porcji na stronie przepisu (V2, D-284):
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

            @if($zOdczytu)
                @include('pages.import.partials.oryginal', ['skan' => $this->skanOdczytu()])
            @endif

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
                               @error("steps.{$index}.photo") aria-invalid="true" aria-describedby="f-steps-{{ $index }}-photo-help f-steps-{{ $index }}-photo-error" @else aria-describedby="f-steps-{{ $index }}-photo-help" @enderror>
                        <label class="pole-zdjecia" for="f-steps-{{ $index }}-photo">
                            <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                            <span class="pole-zdjecia-tytul" id="f-steps-{{ $index }}-photo-tytul">{{ ($row['mediaId'] ?? null) !== null ? 'Zmień zdjęcie' : 'Dodaj zdjęcie' }}</span>
                            <span class="field-help" id="f-steps-{{ $index }}-photo-help">
                                Przydaje się tam, gdzie trudno opisać słowami — jak zawinąć ciasto,
                                jak gęsty ma być sos.
                            </span>
                        </label>
                        @error("steps.{$index}.photo")<span class="field-error" id="f-steps-{{ $index }}-photo-error">{{ $message }}</span>@enderror

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

            @if($wymagaSprawdzenia)
                @if($recipeId !== null && app(PodobienstwoDoZrodla::class)->ostrzegac(\App\Models\Recipe::findOrFail($recipeId), implode("\n", array_column($this->cleanSteps(), 'instruction'))))
                    <div class="notice" role="note">
                        <p class="mt-0 mb-0">
                            <strong>Opis przygotowania jest prawie taki sam jak na stronie źródłowej.</strong>
                            Napisz go własnymi słowami, zanim opublikujesz — cudzy tekst należy do jego autora.
                            Wróć przyciskiem „Wstecz” do kroku „przygotowanie”.
                        </p>
                    </div>
                @endif
                <div class="field @error('sprawdzilemOdczyt') has-error @enderror">
                    <label class="choice">
                        <input type="checkbox" wire:model="sprawdzilemOdczyt" id="f-sprawdzilem">
                        <span class="choice-label">Sprawdziłem odczytany tekst</span>
                    </label>
                    <span class="field-help">Zaznacz, gdy porównasz składniki i kroki ze źródłem.</span>
                </div>
            @endif

            <article class="stack">
                <h3 class="naglowek-podgladu">{{ trim($form->title) !== '' ? trim($form->title) : 'Przepis bez nazwy' }}</h3>

                <ul class="recipe-facts">
                    @if($this->previewServingsLabel() !== null)
                        <li><span class="badge">{{ $this->previewServingsLabel() }}</span></li>
                    @endif
                    @if($this->totalMinutes() !== null)
                        <li><span class="badge">Razem około {{ $this->totalMinutes() }} min</span></li>
                    @endif
                    @if($this->previewCostLabel() !== null)
                        <li><span class="badge">{{ $this->previewCostLabel() }}</span></li>
                    @endif
                    @if($form->difficulty !== '')
                        <li><span class="badge">{{ \App\Models\Recipe::DIFFICULTY_LABELS[$form->difficulty] ?? $form->difficulty }}</span></li>
                    @endif
                    @if(trim($form->family_since_year) !== '')
                        <li><span class="badge badge-cooked">W rodzinie od {{ trim($form->family_since_year) }}</span></li>
                    @endif
                </ul>

                @if($heroMediaId !== null)
                    <p class="meta">Zdjęcie gotowego dania jest dodane. Pokaże się na stronie przepisu, kiedy skończymy je przygotowywać.</p>
                @endif

                @if(trim($form->summary) !== '')
                    <p class="text-lead">{{ trim($form->summary) }}</p>
                @endif

                @if(trim($form->source_person) !== '' || trim($form->source_note) !== '')
                    <section class="recipe-story">
                        <h4 class="mt-0 text-title-sm">Skąd ten przepis</h4>
                        @if(trim($form->source_person) !== '')
                            {{-- Podgląd pokazuje dokładnie to, co strona
                                 przepisu — wartość dosłownie, bez doklejonego
                                 „Po". Uzasadnienie stoi przy tym samym
                                 miejscu w `pages/recipes/show.blade.php`. --}}
                            <p><strong>{{ \Illuminate\Support\Str::ucfirst(trim($form->source_person)) }}</strong></p>
                        @endif
                        @if(trim($form->source_note) !== '')
                            <p class="whitespace-pre-line mb-0">{{ trim($form->source_note) }}</p>
                        @endif
                    </section>
                @endif

                <section>
                    <h4 class="text-title-sm">Składniki</h4>
                    @php($previewGroups = $this->groupedIngredients())
                    @if($previewGroups === [])
                        <p class="meta">Nie dodano jeszcze składników. Możesz dopisać je później.</p>
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
                                        @if(($groupRow['substitutes'] ?? null) !== null)<span class="skladnik-zamiennik">Zamiast tego: {{ $groupRow['substitutes'] }}</span>@endif
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

            @if($zOdczytu)
                {{-- „Odczytany tekst jest sprawdzony” (decyzja właściciela
                     26.09.2026, D-298) — bez tego pola publikacja odmawia. --}}
                <div class="field mt-4 @error('odczyt_sprawdzony') has-error @enderror">
                    <label class="choice" for="f-odczyt_sprawdzony">
                        <input id="f-odczyt_sprawdzony" type="checkbox" wire:model="odczytSprawdzony" value="1"
                               @error('odczyt_sprawdzony') aria-invalid="true" aria-describedby="f-odczyt_sprawdzony-error" @enderror>
                        <span>
                            <span class="choice-label">Odczytany tekst jest sprawdzony ze zdjęciem</span>
                            <span class="choice-help">Każda linijka zgadza się z kartką, a znaczniki [? ?] są usunięte.</span>
                        </span>
                    </label>
                    @error('odczyt_sprawdzony')<span class="field-error" id="f-odczyt_sprawdzony-error">{{ $message }}</span>@enderror
                </div>
            @endif
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
        <a class="btn btn-quiet" href="{{ route('home') }}" @if($juzOpublikowany) wire:click.prevent="wyjdz" @endif>Nie teraz</a>
    </div>

    @if($step === 1)
        <p class="field-help">„Wstecz” jest nieaktywne, bo jesteś na pierwszym kroku — przed nim nic nie ma.</p>
    @endif

    <p class="field-help">
        @if($juzOpublikowany)
            Zapisane zmiany widać od razu w przepisie. Do edycji wrócisz ze strony przepisu.
        @else
            {{-- Bez nazwy (co najmniej 3 znaki) `saveDraft()` nic nie zapisuje,
                 więc zdanie nie obiecuje szkicu „w każdej chwili” (audyt B9). --}}
            Kiedy podasz nazwę przepisu, szkic zapisuje się na Twoim koncie. Możesz wtedy
            zamknąć tę stronę i wrócić do niego ze strony <a href="{{ route('add') }}">Dodaj</a>.
        @endif
    </p>
</div>
