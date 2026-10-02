<?php

declare(strict_types=1);

use App\Domain\Import\BramkaPublikacjiOdczytu;
use App\Domain\Import\PodobienstwoDoZrodla;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\Alergeny\DeklaracjaAlergenow;
use App\Domain\Recipes\Alergeny\OznaczAlergenyPrzepisu;
use App\Domain\Recipes\Alergeny\SlownikAlergenow;
use App\Domain\Recipes\ExistingStepDuplicates;
use App\Domain\Recipes\GrupySkladnikow;
use App\Domain\Recipes\LimitZapisuKreatora;
use App\Domain\Recipes\KosztPrzepisu;
use App\Domain\Recipes\Porcje\GotoweSztuki;
use App\Domain\Recipes\StepTimer;
use App\Exceptions\BladDlaCzlowieka;
use App\Livewire\Forms\PrzepisForm;
use App\Models\Media;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use App\Support\Komunikat;
use App\Support\KreatorPrzepisu\AutozapisKreatora;
use App\Support\KreatorPrzepisu\DanePublikacji;
use App\Support\KreatorPrzepisu\NawigacjaKreatora;
use App\Support\KreatorPrzepisu\PodgladPrzepisu;
use App\Support\KreatorPrzepisu\RewizjaTresci;
use App\Support\KreatorPrzepisu\StanZapisu;
use App\Support\KreatorPrzepisu\WalidacjaKreatora;
use App\Support\KreatorPrzepisu\WierszePrzepisu;
use App\Support\KreatorPrzepisu\WynikWalidacjiKreatora;
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
    public const STEPS = NawigacjaKreatora::KROKI;

    public const STEP_PREVIEW = NawigacjaKreatora::KROK_PODGLADU;

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
     * Karta otwarta PRZED wdrożeniem kroku 3 (a potem kroku 6, który też
     * zmienił kształt stanu) odsyła migawkę bez `form`,
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

    public const WERSJA_STANU = 5;

    /** @var list<array{_key: string, group_name: string, text: string, note: string, substitutes: string, no_amount: bool}> */
    public array $ingredients = [];

    /**
     * Alergeny według autora (#1902, D-333) — kody zaznaczone w kroku 2
     * i pole „Składniki sprawdzone”. Tylko przy włączonej fladze
     * `kuking.alergeny.wlaczone`; stan wzorowany na zapisanym przepisie
     * (`fillFrom()`), a o zapisie decyduje `PublishRecipe` po porównaniu
     * z tym, co już leży w bazie — niezmienione pola niczego nie potwierdzają.
     *
     * @var list<string>
     */
    public array $alergeny = [];

    public bool $alergenyPotwierdzone = false;

    /** Zapisany stan oznaczenia: unchecked | declared | needs_review (tylko do odczytu w widoku). */
    #[Locked]
    public string $alergenyStan = Recipe::ALERGENY_NIESPRAWDZONE;

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
     * zapisywałby się dwa razy pod rząd. Flagę trzyma `AutozapisKreatora`
     * (issue #1387, krok 9); pole jest prywatne, więc nie trafia do migawki.
     */
    private ?AutozapisKreatora $autozapisKreatora = null;

    private function rejestrAutozapisu(): AutozapisKreatora
    {
        return $this->autozapisKreatora ??= new AutozapisKreatora;
    }

    /** Przepisuje stan z `StanZapisu` do publicznych pól, które czyta szablon. */
    private function ustawStanZapisu(StanZapisu $stan): void
    {
        $this->saveState = $stan->stan;
        $this->saveMessage = $stan->komunikat;
    }

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
        $this->form->yield_count = GotoweSztuki::doPola($recipe->yield_count);
        $this->form->yield_unit = (string) $recipe->yield_unit;
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

        $this->alergeny = $recipe->allergens;
        $this->alergenyPotwierdzone = $recipe->alergenyZdeklarowane();
        $this->alergenyStan = (string) $recipe->allergen_status;

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

        $this->step = NawigacjaKreatora::nastepny($this->step);
    }

    public function back(): void
    {
        $this->resetErrorBag();

        // Zapis PRZED cofnięciem — inaczej „Wstecz” wyglądałoby jak utrata
        // tego, co człowiek właśnie wpisał.
        $this->autozapis();

        $this->step = NawigacjaKreatora::poprzedni($this->step);
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
        return NawigacjaKreatora::krokDlaKlucza($key);
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

        $this->dispatch('kreator-fokus-pole', pole: NawigacjaKreatora::idPola($key));
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
        if (AutozapisKreatora::pominZmiane($property)) {
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
        $this->acknowledgedRevision = RewizjaTresci::potwierdzona($this->editRevision);

        // Świadomy zapis z zaznaczonymi alergenami bez potwierdzenia: błąd przy
        // polu i w podsumowaniu, zanim cokolwiek zapiszemy jako oznaczenie.
        // Autozapis tego nie robi — człowiek może być w połowie zaznaczania.
        if ($wersja && ! $this->validateAlergeny()) {
            $this->ustawStanZapisu(StanZapisu::bladPol());
            $this->step = 2;

            return false;
        }

        if ($this->rejestrAutozapisu()->zapisanoWTymZadaniu()) {
            /*
             * Livewire wysyła zmianę pola i kliknięcie „Zapisz zmiany” jednym
             * żądaniem: `updated()` zapisał już treść autozapisem (bez wersji).
             * Świadomy zapis nie może przez to zgubić swojej wersji.
             */
            if (AutozapisKreatora::mogeDopisacWersje($wersja, $this->saveState)
                && ($recipe = $this->existingRecipe()) !== null && $recipe->isPublished()) {
                app(SnapshotRecipeVersion::class)->poprawka($recipe, auth()->user());
            }

            return AutozapisKreatora::wynikPowtorzonegoZapisu($this->saveState);
        }

        if (! $this->validateExistingStepIds()) {
            return false;
        }

        $this->storePendingPhotos();

        if (! AutozapisKreatora::maNazwe($this->form->title)) {
            // Bez nazwy nie da się utworzyć przepisu (PublishRecipe tego pilnuje),
            // więc mówimy wprost, czego brakuje — zamiast cicho nie zapisywać.
            $this->ustawStanZapisu(StanZapisu::brakNazwy($this->juzOpublikowany));

            return false;
        }

        // Autozapis przechodzi te same granice co ręczna publikacja (#528).
        // Sprawdzamy SUROWE pola przed persist()/clean*(), inaczej długi
        // tytuł kończy się SQL 22001, a wiersz bywa po cichu przycięty.
        // Błąd nie nadpisuje wcześniejszego dobrego szkicu ani tekstu w UI.
        $aboutValid = $this->validateAboutStep();
        $rowsValid = $this->validateRows(changeStep: false);
        if (! $aboutValid || ! $rowsValid) {
            $this->ustawStanZapisu(StanZapisu::bladPol());

            return false;
        }

        try {
            $this->persist(publish: false, wersjaPoprawki: $wersja);
        } catch (BladDlaCzlowieka $e) {
            $this->ustawStanZapisu(StanZapisu::bladZapisu($this->juzOpublikowany, $e->getMessage()));

            return false;
        }

        $this->rejestrAutozapisu()->oznaczZapisano();
        $this->ustawStanZapisu(StanZapisu::zapisany($this->juzOpublikowany));

        return true;
    }

    // -----------------------------------------------------------------
    // Publikacja
    // -----------------------------------------------------------------

    public function publish(): void
    {
        $this->resetErrorBag();

        if (! $this->validateExistingStepIds()) {
            /*
             * Błąd „przepis zniknął” (`publikacja`) stoi na podglądzie, a
             * powtórzony zapisany krok (`steps.N.instruction`) — na kroku 3.
             * Stałe „krok 3” cofało człowieka z podglądu w miejsce, którego
             * ten błąd nie dotyczy (issue #1387, uwaga z kroku 8).
             */
            $this->step = NawigacjaKreatora::krokPierwszegoBledu($this->getErrorBag()->keys());

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
            $this->step = NawigacjaKreatora::krokPoBleduZdjecia($this->getErrorBag()->keys());
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

        if (! $this->validateAlergeny()) {
            $this->step = 2;
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
            $this->addError('steps', WalidacjaKreatora::brakKrokuPrzygotowania($this->juzOpublikowany));
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

        Komunikat::wSesji(session()->driver(), Komunikat::sukces($this->juzOpublikowany
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
            $this->ustawStanZapisu(StanZapisu::bladIdentyfikatora($e->getMessage()));

            return false;
        }

        $errors = ExistingStepDuplicates::errors($this->steps, $recipe?->steps()->pluck('id') ?? []);
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            $this->ustawStanZapisu(StanZapisu::bladPol());

            return false;
        }

        return true;
    }

    private function persist(bool $publish, bool $wersjaPoprawki = false): Recipe
    {
        /*
         * Limit `post` (#2268, audyt S-01): trasa `livewire-…/update` nie ma
         * `throttle:`, więc kreator liczy się sam, w TYM SAMYM koszyku co
         * `POST /dodaj/przepis`. Nowy przepis i publikacja — tak; autozapis
         * istniejącego szkicu — nie (to pisanie, nie wytwarzanie treści).
         * Pełny koszyk to `BladDlaCzlowieka`: wołający pokazuje go przy
         * formularzu, a cały tekst zostaje w kreatorze.
         */
        $limit = app(LimitZapisuKreatora::class);
        $liczy = $publish || $this->recipeId === null;
        if ($liczy) {
            $limit->sprawdz(auth()->user());
        }

        $recipe = app(PublishRecipe::class)->handle(
            author: auth()->user(),
            attributes: DanePublikacji::atrybuty(
                title: $this->form->title,
                summary: $this->form->summary,
                servings: $this->form->servings,
                yieldCount: $this->form->yield_count,
                yieldUnit: $this->form->yield_unit,
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
            oczekiwanaRewizja: RewizjaTresci::oczekiwana($this->recipeId, $this->contentRevision),
            wersjaPoprawki: $wersjaPoprawki,
            ip: request()->ip(),
            deklaracjaAlergenow: $this->deklaracjaAlergenowDoZapisu(),
        );

        // Stan oznaczenia po zapisie: składniki mogły zmienić `declared` na
        // `needs_review`. Wtedy pole „sprawdzone” przestaje być prawdziwe —
        // bez tej synchronizacji kolejny autozapis odesłałby je jako świeże
        // potwierdzenie i unieważnienie by przepadło.
        $this->alergenyStan = (string) $recipe->allergen_status;
        if ($recipe->allergen_status === Recipe::ALERGENY_DO_PRZEGLADU) {
            $this->alergenyPotwierdzone = false;
        }

        if ($liczy) {
            $limit->policz(auth()->user());
        }

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
            $this->step = NawigacjaKreatora::krokPierwszegoBledu($this->getErrorBag()->keys());
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
    public function skanOdczytu(): ?Media
    {
        return $this->zOdczytu && $this->sourceScanMediaId !== null
            ? Media::query()->find($this->sourceScanMediaId)
            : null;
    }

    // -----------------------------------------------------------------
    // Alergeny według autora (#1902, D-333)
    // -----------------------------------------------------------------

    /** Czy sekcja „Alergeny” jest w ogóle włączona (flaga; domyślnie wyłączona). */
    public function alergenyWlaczone(): bool
    {
        return (bool) config('kuking.alergeny.wlaczone');
    }

    /**
     * Zaznaczone alergeny BEZ potwierdzenia, które różnią się od zapisanych —
     * czyli coś, co człowiek zmienił i czego nie wolno zapisać jako oznaczenie.
     * Niezmienione pola (np. lista wczytana przy stanie `needs_review`) to nie
     * jest zmiana i nie blokuje ani zapisu, ani publikacji.
     */
    private function alergenyNiezatwierdzone(): bool
    {
        if (! $this->alergenyWlaczone()) {
            return false;
        }

        $deklaracja = new DeklaracjaAlergenow($this->alergeny, $this->alergenyPotwierdzone);

        if (! $deklaracja->wymagaPotwierdzenia()) {
            return false;
        }

        try {
            return $deklaracja->rozniSieOd($this->existingRecipe() ?? new Recipe);
        } catch (BladDlaCzlowieka) {
            // Przepis zniknął — błąd powie o tym właściwa ścieżka zapisu
            // (`validateExistingStepIds()`), nie walidacja alergenów.
            return false;
        }
    }

    private function validateAlergeny(): bool
    {
        $this->resetErrorBag('alergeny');

        if ($this->alergenyNiezatwierdzone()) {
            $this->addError('alergeny', OznaczAlergenyPrzepisu::KOMUNIKAT_POTWIERDZ);

            return false;
        }

        return true;
    }

    /**
     * Deklaracja do `PublishRecipe`: `null`, gdy flaga wyłączona albo gdy
     * człowiek jest w połowie (zaznaczenia bez potwierdzenia — autozapis
     * zapisuje resztę przepisu, a oznaczenie zostaje, jak było).
     */
    private function deklaracjaAlergenowDoZapisu(): ?DeklaracjaAlergenow
    {
        if (! $this->alergenyWlaczone() || $this->alergenyNiezatwierdzone()) {
            return null;
        }

        return new DeklaracjaAlergenow($this->alergeny, $this->alergenyPotwierdzone);
    }

    /**
     * Podpowiedzi słownika dla składników z formularza (etap 2). Tylko tekst
     * pod polami — nic nie jest zaznaczone ani zapisane. Pusta tablica nie
     * jest nigdzie komunikowana.
     *
     * @return array<string, list<string>>
     */
    public function podpowiedziAlergenow(): array
    {
        if (! $this->alergenyWlaczone()) {
            return [];
        }

        $teksty = [];
        foreach ($this->cleanIngredients() as $row) {
            $teksty[] = $row['text'];
            if (($row['substitutes'] ?? null) !== null) {
                $teksty[] = (string) $row['substitutes'];
            }
        }

        return SlownikAlergenow::podpowiedzi($teksty);
    }

    /**
     * „Składniki nadal się zgadzają” — ze stanu `needs_review`. Najpierw
     * zapisujemy szkic (składniki z formularza), potem potwierdzamy listę,
     * którą człowiek widzi zaznaczoną.
     */
    public function potwierdzAlergenyPonownie(): void
    {
        $this->resetErrorBag('alergeny');

        if (! $this->alergenyWlaczone() || $this->alergenyStan !== Recipe::ALERGENY_DO_PRZEGLADU) {
            return;
        }

        if (! $this->autozapis()) {
            return;
        }

        try {
            $recipe = $this->existingRecipe();
            if ($recipe === null) {
                return;
            }

            $recipe = app(OznaczAlergenyPrzepisu::class)->handle(
                auth()->user(),
                $recipe,
                new DeklaracjaAlergenow($this->alergeny, true),
                request()->ip(),
            );
        } catch (BladDlaCzlowieka $e) {
            $this->addError('alergeny', $e->getMessage());

            return;
        }

        $this->alergeny = $recipe->allergens;
        $this->alergenyPotwierdzone = true;
        $this->alergenyStan = (string) $recipe->allergen_status;
        // Akcja podbiła `content_revision` — bez odświeżenia następny zapis
        // dostałby „Ten przepis zmienił się od otwarcia formularza”.
        $this->contentRevision = $recipe->content_revision;
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
        // źródło: `KrokOPrzepisie`, a jego złożenie w błędy — `WalidacjaKreatora` (issue #1387).
        //
        // #900: dawny adres porównujemy z BAZĄ, nie ze stanem komponentu:
        // autozapis nie może zrobić z dopiero wpisanego FTP „historycznego
        // wyjątku”.
        $dawnyAdres = $this->recipeId === null ? null : Recipe::whereKey($this->recipeId)->value('source_url');

        // Wszystkie pola kroku są w `$form`. `WalidacjaKreatora` dostaje je pod
        // nazwami bez przedrostka, a klucze błędów wracają jako `form.<pole>`.
        $wynik = WalidacjaKreatora::krokOPrzepisie($this->form->pola(), $dawnyAdres);

        // Ponowna walidacja usuwa stare błędy tylko tych pól. Nie kasuje
        // komunikatu zdjęcia ani innego etapu; poprawka pola odblokowuje zapis.
        $this->resetErrorBag($wynik->sprawdzone);
        $this->dodajBledy($wynik);

        return $wynik->poprawny();
    }

    private function validateRows(bool $changeStep = true): bool
    {
        // Granice, komunikaty i normalizacja wierszy mają jedno nazwane
        // źródło: `WierszePrzepisu`, a ich złożenie i wybór kroku —
        // `WalidacjaKreatora` (issue #1387). Tu zostaje worek błędów i `$step`.
        $wynik = WalidacjaKreatora::wiersze($this->ingredients, $this->steps);

        $this->resetErrorBag($wynik->sprawdzone);
        $this->dodajBledy($wynik);

        if ($changeStep && ($krok = WalidacjaKreatora::krokZBledemWierszy($wynik)) !== null) {
            $this->step = $krok;
        }

        return $wynik->poprawny();
    }

    private function dodajBledy(WynikWalidacjiKreatora $wynik): void
    {
        foreach ($wynik->bledy as $klucz => $komunikaty) {
            foreach ($komunikaty as $komunikat) {
                $this->addError($klucz, $komunikat);
            }
        }
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
     bezpieczny, jeśli żaden się jeszcze nie zapisał.

     `x-on:input` zapisuje numer wersji przez `$wire.$set(…, false)`, a NIE przez
     `$wire.editRevision = …`. Bundel CSP (`livewire.csp_safe`) w Livewire 4.4.6
     (Alpine 3.17.4) przy przypisaniu do składowej sprawdza `obj.constructor`
     na `$wire`, a Proxy `$wire` odpowiada na to błędem „properties[name] is not
     a function”: przypisanie po cichu się nie wykonuje, numer wersji nie
     dociera na serwer i plakietka do końca mówi „Zmiany czekają na zapis.”,
     choć szkic jest zapisany. `$set(…, false)` robi to samo (zmienia stan
     lokalnie, bez własnego żądania) w obu wersjach. --}}
<div class="stack"
     data-kreator-zapis="{{ $recipeId === null ? 'brak' : ($juzOpublikowany ? 'opublikowany' : 'szkic') }}"
     x-data="{ revision: $wire.editRevision }"
     x-on:input="$wire.$set('editRevision', ++revision, false)">
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
                    @php $celId = \App\Support\KreatorPrzepisu\NawigacjaKreatora::idPola($key); @endphp
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
        <x-kreator.krok-o-przepisie
            :form="$form"
            :liczba-krokow="$this::STEPS"
            :juz-opublikowany="$juzOpublikowany"
            :zrodlo-importu="$zrodloImportu"
            :hero-media-id="$heroMediaId"
        />
    @elseif($step === 2)
        {{-- ==============================================================
             Krok 2 z 3 — składniki
        =============================================================== --}}
        <x-kreator.krok-skladniki
            :ingredients="$ingredients"
            :liczba-krokow="$this::STEPS"
            :z-odczytu="$zOdczytu"
            :skan="$zOdczytu ? $this->skanOdczytu() : null"
        />
        @if($this->alergenyWlaczone())
            <section class="panel-formularza mt-4">
                <x-alergeny.pola :wire="true" :wybrane="$alergeny" :potwierdzone="$alergenyPotwierdzone"
                                 :stan="$alergenyStan" :podpowiedzi="$this->podpowiedziAlergenow()"
                                 :przycisk-przegladu="$recipeId !== null" />
            </section>
        @endif
    @elseif($step === 3)
        {{-- ==============================================================
             Krok 3 z 3 — przygotowanie
        =============================================================== --}}
        <x-kreator.krok-przygotowanie
            :steps="$steps"
            :liczba-krokow="$this::STEPS"
            :z-odczytu="$zOdczytu"
            :skan="$zOdczytu ? $this->skanOdczytu() : null"
        />
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

            <x-kreator.podglad-przepisu
                :form="$form"
                :hero-media-id="$heroMediaId"
                :etykieta-porcji="$this->previewServingsLabel()"
                :czas-razem="$this->totalMinutes()"
                :etykieta-kosztu="$this->previewCostLabel()"
                :grupy-skladnikow="$this->groupedIngredients()"
                :kroki="$this->cleanSteps()"
                :etykieta-minutnika="fn ($minuty) => $this->previewTimerLabel($minuty)"
            />

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
