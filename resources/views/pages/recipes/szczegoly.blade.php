@php
    /* Nazwy klas pełne, nie przez `use` — ten plik trzyma tę konwencję od
       początku (`\App\Models\Recipe::DIFFICULTY_LABELS` niżej). */
    $isEdit = $recipe !== null;
    $action = $isEdit ? route('recipes.update', $recipe->slug) : route('recipes.store');

    $oldIngredients = old('ingredients', $isEdit ? $recipe->ingredients->map(fn ($i) => ['text' => $i->ingredient_text, 'group_name' => $i->group_name, 'note' => $i->note, 'no_amount' => $i->no_amount])->all() : []);

    /*
     * KAŻDY WIERSZ KROKU NIESIE SWOJĄ TOŻSAMOŚĆ (audyt zewnętrzny T12/T24).
     *
     * `id` to identyfikator kroku, który JUŻ ISTNIEJE w bazie. Wraca do
     * serwera ukrytym polem, bo zdjęcia przypiętego do kroku przeglądarka nie
     * umie wysłać drugi raz — plik, którego człowiek nie wybrał w TYM
     * żądaniu, po prostu nie istnieje w POST-cie.
     *
     * Bez tego identyfikatora serwer musiałby dopasowywać zdjęcia PO POZYCJI
     * kroku w bazie. A pozycja w bazie NIE JEST numerem wiersza formularza:
     * puste wiersze są przy zapisie pomijane, więc wyczyszczenie kroku
     * drugiego przesuwa wszystkie następne o jedno miejsce w górę i zdjęcie
     * „obierz ziemniaki" ląduje przy „wyjmij z piekarnika". Nikt tego nie
     * zgłosi, bo to nie wygląda na awarię — przepis po prostu kłamie obrazkiem.
     *
     * `old('steps')` zwraca dokładnie to, co przyszło POST-em, razem z `id`,
     * więc po nieudanej walidacji wiersze wracają w TEJ SAMEJ kolejności,
     * z tymi samymi identyfikatorami i tymi samymi minutnikami.
     */
    $oldSteps = old('steps', $isEdit
        ? $recipe->steps->map(fn ($s) => [
            'id' => $s->getKey(),
            'instruction' => $s->instruction,
            // Baza trzyma sekundy (tego czyta tryb gotowania), człowiek
            // wpisuje minuty. Jeden przelicznik, ten sam co przy zapisie.
            'timer_minutes' => \App\Domain\Recipes\StepTimer::minutesFromSeconds($s->timer_seconds),
        ])->all()
        : []);

    /*
     * Kroki z bazy pod ich identyfikatorami — po to, żeby pokazać zdjęcie,
     * które dany krok JUŻ MA. Szukamy po `id` z wiersza, nie po numerze
     * wiersza: to ta sama reguła, co przy zapisie.
     */
    $krokiWBazie = $isEdit ? $recipe->steps->keyBy(fn ($s) => (string) $s->getKey()) : collect();

    /*
     * Trzy pierwsze puste wiersze składników i kroków są od razu widoczne —
     * pusta lista z jednym przyciskiem „Dodaj składnik” jest mniej zrozumiała
     * niż gotowe pola do wypełnienia.
     *
     * Sufit z `Recipe::MAX_*`, bo „liczba wierszy + 1" bez granicy renderuje
     * o jeden wiersz WIĘCEJ, niż przyjmuje walidacja — przy pełnym przepisie
     * formularz odbijałby własny POST komunikatem o zbyt wielu krokach.
     */
    // Klucz adresuje dane, zdjęcie i błąd; numer widoczny liczy osobno pętla.
    // Dopisujemy pierwszy wolny klucz, nie tworzymy pól aż do największego.
    $withEmptyRows = static function (array $rows, int $limit): array {
        $count = min($limit, max(3, count($rows) + 1));
        for ($key = 0; count($rows) < $count; $key++) {
            if (! array_key_exists($key, $rows)) {
                $rows[$key] = [];
            }
        }
        return $rows;
    };
    $oldIngredients = $withEmptyRows($oldIngredients, \App\Models\Recipe::MAX_INGREDIENTS);
    $oldSteps = $withEmptyRows($oldSteps, \App\Models\Recipe::MAX_STEPS);
@endphp

<x-layout :title="$isEdit ? 'Dopisz szczegóły' : 'Dodaj przepis ze szczegółami'" :noindex="true">
    <h1>{{ $isEdit ? 'Dopisz szczegóły' : 'Dodaj przepis ze szczegółami' }}</h1>
    {{-- ZDANIE „NIE MUSISZ NIC PRZEWIJAĆ ANI SZUKAĆ" ZNIKŁO, BO BYŁO NIEPRAWDĄ.

         Zmierzone 11 września 2026 Chromium 1243 na postawionej lokalnie
         instancji, konto `ania`, ten ekran (`h1` = „Dopisz szczegóły"):

         | stan | 360 px | 1280 px |
         |---|---|---|
         | sam tytuł (3 puste wiersze składników i kroków) | 10 249 px | 7 836 px |
         | 8 składników i 6 kroków | 16 586 px | 13 065 px |

         Przy oknie telefonu 640 px to jest od 16 do 26 ekranów przewijania,
         na monitorze przy oknie 800 px — od 10 do 16. Kontrolek renderuje się
         40 (stan pusty) do 70 (stan wypełniony); siedemnaście znaczników
         `<input|<textarea|<select>` w tym pliku to tylko te wypisane z ręki,
         resztę składają pętle i `<x-field>`.

         Tego się nie da naprawić zdaniem — to jest najdłuższy formularz
         w serwisie i przewijać na nim trzeba. Obietnica, że nie trzeba, u tej
         grupy kosztuje zaufanie w pierwszej sekundzie, i to na ekranie, na
         którym ktoś dopisuje szczegóły do przepisu po babci.

         ZOSTAJE INFORMACJA, PO KTÓRĄ CZŁOWIEK TU PRZYSZEDŁ: że dodatkowe szczegóły nie są
         obowiązkowe, że wypełnia tyle, ile chce, i że poprawnie wpisane dane
         nie zginą (`old()` — AGENTS.md §5). Pilnuje tego
         `tests/Feature/EkranSzczegolowNieObiecujeBrakuPrzewijaniaTest.php`
         — razem z tym, że poprawka nie zjadła tej informacji. --}}
    <p class="mb-5">
        Wszystko jest na jednej stronie. Nazwa przepisu jest wymagana. Do publikacji i zapisu opublikowanego przepisu potrzebny jest też co najmniej jeden krok przygotowania. Pozostałe szczegóły są opcjonalne:
        wypełnij tyle, ile chcesz, i zapisz. Poprawnie wpisane dane nie zginą.
    </p>

    @if($isEdit)
        {{-- Ten sam ekran w krokach — dla kogoś, komu jedna długa strona
             jest za długa. Kreator wymaga JavaScriptu, więc NIE jest jedyną
             drogą do szczegółów; ta strona działa bez skryptu. --}}
        <p class="field-help mb-5">
            Wolisz przechodzić to krok po kroku, z zapisywaniem po drodze?
            <a href="{{ route('recipes.details', $recipe->slug) }}">Otwórz kreator w trzech krokach</a>.
        </p>
    @endif

    <x-error-summary />

    {{-- PANEL JEST JEDEN I SIEDZI NA `<form>`, nie na czterech sekcjach.

         Dwa powody, oba zmierzone. Po pierwsze kaskada: `.form-section`
         (`app.css`) stoi PO `tokens.css` w tym samym `@layer components`, więc
         przy równej wadze selektora zabierał panelowi obwódkę kontrolki
         (2 px dekoracyjnej zamiast 1 px mocnej), a `:first-of-type` zerował
         pierwszej sekcji górną obwódkę i wcięcie całkowicie.

         Po drugie hierarchia: mocna obwódka znaczy „to jest do wypełnienia".
         Cztery takie obwódki na jednym ekranie nie odróżniają już niczego —
         `scripts/warstwy-pomiar.mjs` dawał tu 4 powierzchnie / 1 sygnaturę
         / 4 w największej grupie, czyli dokładnie wzorzec ekranu bez
         hierarchii z `docs/design/ROLE_KART.md`. To są cztery części JEDNEGO
         formularza, więc jedna rola i jedna powierzchnia; rozdziela je
         kreska i nagłówek z `.form-section`, tak jak było to pomyślane. --}}
    <form class="panel-formularza" method="POST" action="{{ $action }}" enctype="multipart/form-data">
        @csrf
        @if($isEdit) @method('PUT') @endif

        {{-- TOŻSAMOŚĆ TEGO WYSŁANIA (ADR docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md).
             Tylko przy DODAWANIU: edycja pracuje na przepisie, który już
             istnieje, i klucza nie przysyła — kontroler edycji nie wstawia tu
             żadnej wartości. Zwykłe ukryte pole, bez JavaScriptu; nazwa bez
             fragmentu „token", inaczej pole ginie na ekranie 419 (ADR §1.4.4). --}}
        @if(($kluczWyslania ?? null) !== null)
            <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">
        @endif

        {{-- ---------------------------------------------------------------
             1. O przepisie
        ---------------------------------------------------------------- --}}
        <section class="form-section">
            <h2 class="form-section-title">1. O przepisie</h2>

            <x-field name="title" label="Nazwa przepisu" required
                     :value="$isEdit ? $recipe->title : null"
                     placeholder="Rosół babci Zofii" />

            {{-- Duży obszar wyboru zdjęcia. Natywne pole pliku jest schowane
                 dla oka (D-035) — rysowało angielskie „Choose File / No file
                 chosen" w polskim formularzu. Zostaje pod klawiaturą i w
                 drzewie dostępności (`.visually-hidden`), klikalna jest
                 etykieta, a `<input>` MUSI stać bezpośrednio przed nią, bo
                 obwódkę fokusu rysuje reguła sąsiedztwa w
                 resources/css/ekran-dodawania.css. --}}
            <div class="field @error('hero_photo') has-error @enderror">
                <span class="pole-zdjecia-nazwa" id="f-hero_photo-etykieta">Zdjęcie gotowego dania</span>
                <input class="visually-hidden pole-zdjecia-input" id="f-hero_photo" type="file" name="hero_photo"
                       accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                       aria-labelledby="f-hero_photo-etykieta f-hero_photo-tytul"
                       aria-describedby="f-hero_photo-help">
                <label class="pole-zdjecia" for="f-hero_photo">
                    <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                    <span class="pole-zdjecia-tytul" id="f-hero_photo-tytul">Dodaj zdjęcie</span>
                    <span class="field-help" id="f-hero_photo-help">To zdjęcie zobaczą ludzie na liście przepisów.</span>
                </label>
                @error('hero_photo')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <x-field name="summary" label="Krótko o przepisie" type="textarea" :rows="3"
                     :value="$isEdit ? $recipe->summary : null"
                     help="Jedno-dwa zdania. Na co ten przepis jest dobry, kiedy go robisz." />

            <div class="siatka-pol">
                {{-- Krok 0,01 (setne) — decyzja właściciela z 20.09.2026 (#750).
                     Kolumna `servings` to decimal(6,2); `step` musi się zgadzać
                     z walidacją serwera (`RecipeController::validated()`),
                     inaczej przeglądarka odrzuca poprawną wartość jako
                     `stepMismatch`, zanim żądanie w ogóle wyjdzie. --}}
                <x-field name="servings" label="Na ile porcji" type="number" inputmode="decimal"
                         :value="$isEdit ? $recipe->servings : null" :min="0.5" :max="999" :step="0.01" />
                <x-field name="prep_minutes" label="Przygotowanie (minuty)" type="number" inputmode="numeric"
                         :value="$isEdit ? $recipe->prep_minutes : null" :min="0" :max="10080" />
                <x-field name="cook_minutes" label="Gotowanie / pieczenie (minuty)" type="number" inputmode="numeric"
                         :value="$isEdit ? $recipe->cook_minutes : null" :min="0" :max="10080" />
            </div>

            {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty
                 ARIA wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
            <fieldset class="border-0 p-0 mt-6" id="f-difficulty"
                      @error('difficulty') tabindex="-1" aria-invalid="true" aria-describedby="f-difficulty-error" @enderror>
                <legend class="font-bold mb-3">Jak trudny jest ten przepis?</legend>
                <div class="choice-grid">
                    @foreach(\App\Models\Recipe::DIFFICULTY_LABELS as $value => $label)
                        <label class="choice">
                            <input type="radio" name="difficulty" value="{{ $value }}"
                                   @checked(old('difficulty', $isEdit ? $recipe->difficulty : null) === $value)>
                            <span class="choice-label">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <x-blad-grupy name="difficulty" />
            </fieldset>

            {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty
                 ARIA wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
            <fieldset class="border-0 p-0 mt-6" id="f-visibility"
                      @error('visibility') tabindex="-1" aria-invalid="true" aria-describedby="f-visibility-error" @enderror>
                <legend class="font-bold mb-3">Kto ma widzieć ten przepis?</legend>
                <div class="choice-grid">
                    <label class="choice">
                        <input type="radio" name="visibility" value="public" @checked(old('visibility', $isEdit ? $recipe->visibility : 'public') === 'public')>
                        <span><span class="choice-label">Wszyscy</span><span class="choice-help">Także osoby bez konta. Przepis może pojawić się w Google.</span></span>
                    </label>
                    <label class="choice">
                        <input type="radio" name="visibility" value="followers" @checked(old('visibility', $isEdit ? $recipe->visibility : null) === 'followers')>
                        <span><span class="choice-label">Tylko osoby, które mnie obserwują</span></span>
                    </label>
                    <label class="choice">
                        <input type="radio" name="visibility" value="private" @checked(old('visibility', $isEdit ? $recipe->visibility : null) === 'private')>
                        <span><span class="choice-label">Tylko ja</span><span class="choice-help">Twój prywatny zeszyt.</span></span>
                    </label>
                </div>
                <x-blad-grupy name="visibility" />
            </fieldset>
        </section>

        {{-- ---------------------------------------------------------------
             2. Skąd ten przepis — to jest serce Kuking, nie metadana
        ---------------------------------------------------------------- --}}
        <section class="form-section">
            <h2 class="form-section-title">2. Skąd ten przepis</h2>
            {{-- ZDANIE MÓWI, CO TU WPISAĆ, A NIE JAK CZĘSTO TO KTOŚ CZYTA.
                 Stało tu „To najczęściej czytana część przepisu" — twierdzenie
                 o zachowaniu czytelników, którego nikt nigdy nie zmierzył. --}}
            <p class="meta mb-4">
                Tu napiszesz, skąd masz ten przepis i co Cię z nim wiąże.
            </p>

            {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty
                 ARIA wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
            <fieldset class="border-0 p-0" id="f-source_type"
                      @error('source_type') tabindex="-1" aria-invalid="true" aria-describedby="f-source_type-error" @enderror>
                <legend class="font-bold mb-3">Ten przepis jest…</legend>
                <div class="choice-grid">
                    @foreach(\App\Models\Recipe::SOURCE_LABELS as $value => $label)
                        <label class="choice">
                            <input type="radio" name="source_type" value="{{ $value }}"
                                   @checked(old('source_type', $isEdit ? $recipe->source_type : 'own') === $value)>
                            <span class="choice-label">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <x-blad-grupy name="source_type" />
            </fieldset>

            {{-- PYTAMY O FRAZĘ, KTÓRA STOI SAMODZIELNIE.
                 Do 11 września 2026 pole nazywało się „Po kim ten przepis"
                 i podpowiadało „po mamie, Halinie", a widok doklejał przed
                 odpowiedź własne „Po" — z „Nasze smaki" robiło się „Po Nasze
                 smaki.", a z „po mamie" „Po po mamie.". Odpowiedź na TO pytanie
                 czyta się i sama („Od mamy."), i po słowie „przepis"
                 („przepis od mamy"), więc nie trzeba jej odmieniać. --}}
            <x-field name="source_person" label="Od kogo albo skąd masz ten przepis" :value="$isEdit ? $recipe->source_person : null"
                     placeholder="od mamy · z gazety · z bloga Nasze smaki"
                     help="Napisz to tak, żeby dało się przeczytać samo: „od mamy”, „z gazety”, „od sąsiadki Haliny”. Pokażemy to przy przepisie dokładnie tak, jak wpiszesz." />

            {{-- POMOC JEST PRAWDZIWA PRZY KAŻDEJ Z TRZECH WIDOCZNOŚCI.
                 Stało tu „To zostaje w rodzinie." — nieprawda przy przepisie
                 publicznym, a taki jest tu domyślny (radio „Wszyscy" wyżej).
                 Ten formularz idzie zwykłym POST-em, więc zdanie zależne od
                 widoczności i tak nie zmieniłoby się przed wysłaniem. --}}
            <x-field name="source_note" label="Historia tego przepisu" type="textarea" :rows="4"
                     :value="$isEdit ? $recipe->source_note : null"
                     help="Skąd go znasz, kiedy się go gotuje, co Ci się z nim wiąże. Ta historia jest częścią przepisu — zobaczy ją każdy, kto zobaczy przepis." />

            <x-field name="family_since_year" label="W rodzinie od roku" type="number" inputmode="numeric"
                     :value="$isEdit ? $recipe->family_since_year : null" :min="1850" :max="2100"
                     placeholder="1974" />

            {{-- Ten sam wzorzec co przy „Zdjęcie gotowego dania" wyżej: pole
                 pliku schowane dla oka (D-035), klikalna etykieta, `<input>`
                 bezpośrednio przed nią. --}}
            <div class="field @error('source_scan') has-error @enderror">
                <span class="pole-zdjecia-nazwa" id="f-source_scan-etykieta">Zdjęcie starej kartki albo zeszytu</span>
                <input class="visually-hidden pole-zdjecia-input" id="f-source_scan" type="file" name="source_scan"
                       accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                       aria-labelledby="f-source_scan-etykieta f-source_scan-tytul"
                       aria-describedby="f-source_scan-help">
                <label class="pole-zdjecia" for="f-source_scan">
                    <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                    <span class="pole-zdjecia-tytul" id="f-source_scan-tytul">Dodaj zdjęcie</span>
                    <span class="field-help" id="f-source_scan-help">
                        Jeśli masz przepis zapisany ręcznie — zrób mu zdjęcie. Zostanie przy przepisie.
                    </span>
                </label>
                @error('source_scan')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <x-field name="source_url" label="Adres strony, z której jest przepis" type="url"
                     :value="$isEdit ? $recipe->source_url : null"
                     help="Podaj, jeśli przepis pochodzi z bloga albo innej strony. Nie publikuj cudzych treści bez zgody." />
        </section>

        {{-- ---------------------------------------------------------------
             3. Składniki
        ---------------------------------------------------------------- --}}
        <section class="form-section">
            <h2 class="form-section-title">3. Składniki</h2>
            <p class="meta mb-4">
                Pisz tak, jak mówisz: „szklanka mąki”, „2 duże cebule”, „mleko — ile weźmie”.
                Nie musisz nic przeliczać na gramy. Puste wiersze zostaną pominięte.
                {{-- Zdanie o grupach stoi RAZ, nad całą listą, a nie przy
                     każdym wierszu. Przy dziesięciu składnikach ta sama
                     podpowiedź powtórzona dziesięć razy jest już nie
                     pomocą, tylko ścianą tekstu — a czytnik ekranu
                     przeczytałby ją przy każdym polu. --}}
                Grupę wypełnij tylko wtedy, gdy przepis ma osobne części, na przykład „Ciasto” i „Nadzienie”.
            </p>

            @foreach($oldIngredients as $i => $ingredient)
                <div class="field">
                    <label for="f-ingredients-{{ $i }}-text">Składnik {{ $loop->iteration }}</label>
                    <input class="field-input" id="f-ingredients-{{ $i }}-text"
                           name="ingredients[{{ $i }}][text]" type="text" maxlength="240"
                           value="{{ $oldIngredients[$i]['text'] ?? '' }}"
                           @if($i === 0) placeholder="1 kurczak, najlepiej zagrodowy" @endif>
                    @error("ingredients.$i.text")<span class="field-error">{{ $message }}</span>@enderror

                    {{--
                        GRUPA SKŁADNIKÓW — „Ciasto”, „Farsz”, „Do podania”
                        (D-033). Ta sama nazwa pola co w kreatorze
                        (`ingredients[i][group_name]`), więc przepis
                        przechodzi między obiema drogami zapisu bez zmiany —
                        a bez tego pola formularz BEZ JavaScriptu kasowałby
                        przy edycji grupy wpisane w kreatorze (AGENTS.md §5:
                        ważna funkcja działa bez skryptu).

                        NIEOBOWIĄZKOWE I PUSTE Z DEFINICJI. Składnik bez grupy
                        to normalny przypadek — tak wygląda większość
                        przepisów — więc pole nie ma gwiazdki, nie ma
                        `required`, nie podświetla się na czerwono i nie
                        pojawia się w podsumowaniu błędów, gdy zostanie puste.

                        Ręczna rozpiska zamiast `x-field` z tego samego
                        powodu, co przy minutniku kroku niżej: `name` musi
                        mieć nawiasy (`ingredients[0][group_name]`), a `id`
                        i klucz błędu kropki — PHP zamienia kropki w nazwie
                        pola na podkreślenia i tablica `ingredients` nigdy by
                        się nie złożyła.
                    --}}
                    <label class="mt-3" for="f-ingredients-{{ $i }}-group_name">
                        Grupa składników <span class="meta">(nieobowiązkowe)</span>
                    </label>
                    <input class="field-input" id="f-ingredients-{{ $i }}-group_name"
                           name="ingredients[{{ $i }}][group_name]" type="text" maxlength="120"
                           value="{{ $oldIngredients[$i]['group_name'] ?? '' }}"
                           @if($i === 0) placeholder="Ciasto" @endif>
                    @error("ingredients.$i.group_name")<span class="field-error">{{ $message }}</span>@enderror

                    <label class="mt-3" for="f-ingredients-{{ $i }}-note">Uwagi do składnika <span class="meta">(nieobowiązkowe)</span></label>
                    <input class="field-input" id="f-ingredients-{{ $i }}-note"
                           name="ingredients[{{ $i }}][note]" type="text" maxlength="300"
                           value="{{ $ingredient['note'] ?? '' }}"
                           @error("ingredients.$i.note") aria-invalid="true" aria-describedby="f-ingredients-{{ $i }}-note-error" @enderror>
                    @error("ingredients.$i.note")<span class="field-error" id="f-ingredients-{{ $i }}-note-error">{{ $message }}</span>@enderror

                    {{-- „Bez ilości” — sól do smaku (issue #44). Zwykły
                         checkbox, działa bez JavaScriptu. Nieobowiązkowy
                         i domyślnie wyłączony: ma znaczenie dopiero przy
                         przeliczaniu przepisu na inną liczbę porcji. --}}
                    <label class="choice mt-2">
                        <input type="checkbox" name="ingredients[{{ $i }}][no_amount]" value="1"
                               @checked($oldIngredients[$i]['no_amount'] ?? false)>
                        <span>
                            <span class="choice-label">Bez ilości</span>
                            <span class="choice-help">Na przykład „do smaku”, „ile weźmie”, „szczypta”.</span>
                        </span>
                    </label>
                </div>
            @endforeach

            <p class="field-help">
                @unless($isEdit && $recipe->isPublished())
                    Potrzebujesz więcej wierszy? Zapisz szkic — po zapisaniu pojawi się kolejne puste pole.
                @endunless
                @if($isEdit)
                    W <a href="{{ route('recipes.details', $recipe->slug) }}">kreatorze w trzech krokach</a> wiersze
                    dodaje się i usuwa od razu, bez zapisywania.
                @endif
            </p>
        </section>

        {{-- ---------------------------------------------------------------
             4. Przygotowanie
        ---------------------------------------------------------------- --}}
        <section class="form-section" id="f-steps">
            <h2 class="form-section-title">4. Przygotowanie</h2>
            <p class="meta mb-4">
                Jeden krok to jedna czynność. Krótkie kroki łatwiej czytać przy garnku.
                Przy każdym kroku możesz dopisać, ile minut ma trwać, i dodać zdjęcie —
                jedno i drugie jest nieobowiązkowe.
            </p>
            @error('steps')<p class="field-error">{{ $message }}</p>@enderror

            @foreach($oldSteps as $i => $stepRow)
                @php
                    $idKroku = $oldSteps[$i]['id'] ?? null;
                    $zdjecieKroku = $idKroku === null ? null : $krokiWBazie->get((string) $idKroku)?->media;
                @endphp
                <fieldset class="wizard-row">
                    <legend class="font-bold mb-3">Krok {{ $loop->iteration }}</legend>

                    {{-- Tożsamość tego kroku. Wraca niezmieniona, żeby zdjęcie
                         zostało przy SWOIM kroku także po wyczyszczeniu innego
                         wiersza i po nieudanej walidacji. --}}
                    <input type="hidden" name="steps[{{ $i }}][id]" value="{{ $idKroku }}">

                    <div class="field">
                        <label for="f-steps-{{ $i }}-instruction">Co się robi w tym kroku</label>
                        <textarea class="field-input" id="f-steps-{{ $i }}-instruction"
                                  name="steps[{{ $i }}][instruction]" rows="3"
                                  @if($i === 0) placeholder="Kurczaka zalej zimną wodą i zagotuj. Zbierz szumowiny." @endif
                        >{{ $oldSteps[$i]['instruction'] ?? '' }}</textarea>
                        @error("steps.$i.instruction")<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    {{-- Ręczna rozpiska, a nie `x-field`, i to jest świadome.
                         `x-field` liczy atrybut `name` z tej samej wartości,
                         z której liczy `id` i klucz błędu — a tu te dwie
                         rzeczy MUSZĄ się różnić: PHP zamienia kropki w nazwie
                         pola na podkreślenia, więc pole nazwane
                         `steps.0.timer_minutes` przyszłoby jako
                         `steps_0_timer_minutes` i nie trafiłoby do tablicy
                         `steps`. Nawiasy w `name`, kropki w `id` i w `@error`
                         — dokładnie tak, jak stojące wyżej pola składników. --}}
                    <div class="field">
                        <label for="f-steps-{{ $i }}-timer_minutes">
                            Ile minut ma trwać ten krok? <span class="meta">(nieobowiązkowe)</span>
                        </label>
                        <span class="field-help" id="f-steps-{{ $i }}-timer_minutes-help">
                            Wpisz liczbę minut — na przykład 45. Przy gotowaniu pokażemy wtedy:
                            „Ustaw sobie kuchenny minutnik na 45 minut”.
                            Zostaw puste, jeśli ten krok nie potrzebuje odliczania.
                        </span>
                        <input class="field-input" id="f-steps-{{ $i }}-timer_minutes"
                               type="number" inputmode="numeric" name="steps[{{ $i }}][timer_minutes]"
                               min="0" max="{{ \App\Domain\Recipes\StepTimer::MAX_MINUTES }}" step="1"
                               value="{{ $oldSteps[$i]['timer_minutes'] ?? '' }}"
                               aria-describedby="f-steps-{{ $i }}-timer_minutes-help">
                        @error("steps.$i.timer_minutes")<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="field @error("steps.$i.photo") has-error @enderror">
                        <span class="pole-zdjecia-nazwa" id="f-steps-{{ $i }}-photo-etykieta">Zdjęcie do tego kroku <span class="meta">(nieobowiązkowe)</span></span>

                        @if($zdjecieKroku)
                            {{-- Zdjęcie, które ten krok już ma. Zostaje przy nim
                                 samo — nie trzeba go wybierać drugi raz. --}}
                            <span class="krok-zdjecie">
                                <x-photo :media="$zdjecieKroku" variant="thumb" :zoom="false"
                                         class="krok-zdjecie-obraz"
                                         :alt="'Zdjęcie przy kroku '.$loop->iteration"
                                         sizes="160px" />
                            </span>
                            <label class="choice mt-2">
                                <input type="checkbox" name="steps[{{ $i }}][remove_photo]" value="1">
                                <span>
                                    <span class="choice-label">Usuń to zdjęcie</span>
                                    <span class="choice-help">Zaznacz i zapisz przepis. Krok zostanie bez zdjęcia.</span>
                                </span>
                            </label>
                        @endif

                        {{-- Duży obszar wyboru zdjęcia — patrz komentarz przy
                             polu „Zdjęcie gotowego dania" wyżej w tym pliku.
                             Atrybuty pola (`id`, `name`, `accept`,
                             `aria-describedby`) są NIEZMIENIONE: to ten sam
                             identyfikator kroku w `name`, po którym serwer
                             i tak szuka pliku (komentarz na górze pliku,
                             „KAŻDY WIERSZ KROKU NIESIE SWOJĄ TOŻSAMOŚĆ").
                             Doszło tylko `aria-labelledby` i kolejność
                             wymuszona przez regułę fokusu (D-035). --}}
                        <input class="visually-hidden pole-zdjecia-input" id="f-steps-{{ $i }}-photo" type="file"
                               name="steps[{{ $i }}][photo]"
                               accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                               aria-labelledby="f-steps-{{ $i }}-photo-etykieta f-steps-{{ $i }}-photo-tytul"
                               aria-describedby="f-steps-{{ $i }}-photo-help">
                        <label class="pole-zdjecia" for="f-steps-{{ $i }}-photo">
                            <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                            <span class="pole-zdjecia-tytul" id="f-steps-{{ $i }}-photo-tytul">{{ $zdjecieKroku ? 'Zmień zdjęcie' : 'Dodaj zdjęcie' }}</span>
                            <span class="field-help" id="f-steps-{{ $i }}-photo-help">
                                Przydaje się tam, gdzie trudno opisać słowami — jak zawinąć ciasto,
                                jak gęsty ma być sos. Za jednym razem można dodać najwyżej
                                {{ \App\Support\LimityZdjec::maksZdjecKrokowNaZapis() }}
                                {{-- Odmiana liczebnika z JEDNEGO miejsca (issue #86) — inaczej
                                     zmiana limitu dawałaby „5 zdjęcia do kroków". --}}
                                {{ \App\Support\Odmiana::rzeczownik(\App\Support\LimityZdjec::maksZdjecKrokowNaZapis(), 'zdjęcie', 'zdjęcia', 'zdjęć') }}
                                do kroków.
                            </span>
                        </label>
                        @error("steps.$i.photo")<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                </fieldset>
            @endforeach
        </section>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit" name="action" value="publish">
                {{ $isEdit && $recipe->isPublished() ? 'Zapisz zmiany' : 'Opublikuj przepis' }}
            </button>
            {{-- „Zapisz szkic" tylko dla przepisu, który JESZCZE nie jest
                 opublikowany. Przy opublikowanym ten przycisk nie ma sensu:
                 nie ma stanu roboczego, do którego można by wrócić, a jego
                 nazwa obiecuje prywatny zapis, którym nie jest.

                 Serwer i tak nie pozwoli opróżnić opublikowanego przepisu
                 (PublishRecipe: warunek `$bedziePubliczny`) — ale przycisk,
                 który zawsze kończy się błędem, jest gorszy niż jego brak. --}}
            @unless($isEdit && $recipe->isPublished())
                <button class="btn btn-secondary" type="submit" name="action" value="draft">Zapisz szkic</button>
            @endunless
            <a class="btn btn-quiet" href="{{ route('home') }}">Nie teraz</a>
        </div>
    </form>
</x-layout>
