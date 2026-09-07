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
    $ingredientRows = min(\App\Models\Recipe::MAX_INGREDIENTS, max(3, count($oldIngredients) + 1));
    $stepRows = min(\App\Models\Recipe::MAX_STEPS, max(3, count($oldSteps) + 1));
@endphp

<x-layout :title="$isEdit ? 'Edytuj przepis' : 'Dodaj przepis'" :noindex="true">
    <h1>{{ $isEdit ? 'Edytuj przepis' : 'Dodaj przepis' }}</h1>
    <p class="mb-5">
        Wszystko jest na jednej stronie — nie musisz nic przewijać ani szukać.
        Jeśli nie masz teraz czasu — zapisz szkic. Nic nie zginie i wrócisz do tego,
        kiedy zechcesz.
    </p>

    @if(! $isEdit)
        {{-- Ten formularz jest DROGĄ BEZ JAVASCRIPTU i taki zostaje.
             Kreator w krokach jest wygodniejszy, ale wymaga skryptu,
             więc nigdy nie może być jedyną drogą (AGENTS.md, punkt 5). --}}
        <p class="field-help mb-5">
            Wolisz przechodzić to krok po kroku, z zapisywaniem po drodze?
            <a href="{{ route('recipes.create') }}">Otwórz kreator w trzech krokach</a>.
        </p>
    @endif

    <x-error-summary />

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data">
        @csrf
        @if($isEdit) @method('PUT') @endif

        {{-- ---------------------------------------------------------------
             1. O przepisie
        ---------------------------------------------------------------- --}}
        <section class="form-section card">
            <h2 class="form-section-title">1. O przepisie</h2>

            <x-field name="title" label="Nazwa przepisu" required
                     :value="$isEdit ? $recipe->title : null"
                     placeholder="Rosół babci Zofii" />

            <div class="field">
                <label for="f-hero_photo">Zdjęcie gotowego dania</label>
                <span class="field-help" id="f-hero_photo-help">To zdjęcie zobaczą ludzie na liście przepisów.</span>
                <input class="field-input" id="f-hero_photo" type="file" name="hero_photo"
                       accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                       aria-describedby="f-hero_photo-help">
                @error('hero_photo')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <x-field name="summary" label="Krótko o przepisie" type="textarea" :rows="3"
                     :value="$isEdit ? $recipe->summary : null"
                     help="Jedno-dwa zdania. Na co ten przepis jest dobry, kiedy go robisz." />

            <div class="siatka-pol">
                <x-field name="servings" label="Na ile porcji" type="number" inputmode="decimal"
                         :value="$isEdit ? $recipe->servings : null" :min="0.5" :max="999" :step="0.5" />
                <x-field name="prep_minutes" label="Przygotowanie (minuty)" type="number" inputmode="numeric"
                         :value="$isEdit ? $recipe->prep_minutes : null" :min="0" :max="10080" />
                <x-field name="cook_minutes" label="Gotowanie / pieczenie (minuty)" type="number" inputmode="numeric"
                         :value="$isEdit ? $recipe->cook_minutes : null" :min="0" :max="10080" />
            </div>

            <fieldset class="border-0 p-0 mt-6">
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
            </fieldset>

            <fieldset class="border-0 p-0 mt-6">
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
                @error('visibility')<span class="field-error">{{ $message }}</span>@enderror
            </fieldset>
        </section>

        {{-- ---------------------------------------------------------------
             2. Skąd ten przepis — to jest serce Kuking, nie metadana
        ---------------------------------------------------------------- --}}
        <section class="form-section card">
            <h2 class="form-section-title">2. Skąd ten przepis</h2>
            <p class="meta mb-4">
                To najczęściej czytana część przepisu. Ludzie chcą wiedzieć, po kim on jest.
            </p>

            <fieldset class="border-0 p-0">
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
                @error('source_type')<span class="field-error">{{ $message }}</span>@enderror
            </fieldset>

            <x-field name="source_person" label="Po kim ten przepis" :value="$isEdit ? $recipe->source_person : null"
                     placeholder="po mamie, Halinie"
                     help="Zostanie podpisany nad tytułem: „przepis Haliny, spisany przez Ciebie”." />

            <x-field name="source_note" label="Historia tego przepisu" type="textarea" :rows="4"
                     :value="$isEdit ? $recipe->source_note : null"
                     help="Skąd go znasz, kiedy się go gotuje, co Ci się z nim wiąże. To zostaje w rodzinie." />

            <x-field name="family_since_year" label="W rodzinie od roku" type="number" inputmode="numeric"
                     :value="$isEdit ? $recipe->family_since_year : null" :min="1850" :max="2100"
                     placeholder="1974" />

            <div class="field">
                <label for="f-source_scan">Zdjęcie starej kartki albo zeszytu</label>
                <span class="field-help" id="f-source_scan-help">
                    Jeśli masz przepis zapisany ręcznie — zrób mu zdjęcie. Zostanie przy przepisie.
                </span>
                <input class="field-input" id="f-source_scan" type="file" name="source_scan"
                       accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                       aria-describedby="f-source_scan-help">
                @error('source_scan')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <x-field name="source_url" label="Adres strony, z której jest przepis" type="url"
                     :value="$isEdit ? $recipe->source_url : null"
                     help="Podaj, jeśli przepis pochodzi z bloga albo innej strony. Nie publikuj cudzych treści bez zgody." />
        </section>

        {{-- ---------------------------------------------------------------
             3. Składniki
        ---------------------------------------------------------------- --}}
        <section class="form-section card">
            <h2 class="form-section-title">3. Składniki</h2>
            <p class="meta mb-4">
                Pisz tak, jak mówisz: „szklanka mąki”, „2 duże cebule”, „mleko — ile weźmie”.
                Nie musisz nic przeliczać na gramy. Puste wiersze zostaną pominięte.
            </p>

            @for($i = 0; $i < $ingredientRows; $i++)
                <div class="field">
                    <label for="f-ingredients-{{ $i }}-text">Składnik {{ $i + 1 }}</label>
                    <input class="field-input" id="f-ingredients-{{ $i }}-text"
                           name="ingredients[{{ $i }}][text]" type="text" maxlength="240"
                           value="{{ $oldIngredients[$i]['text'] ?? '' }}"
                           @if($i === 0) placeholder="1 kurczak, najlepiej zagrodowy" @endif>
                    @error("ingredients.$i.text")<span class="field-error">{{ $message }}</span>@enderror

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
            @endfor

            <p class="field-help">
                @unless($isEdit && $recipe->isPublished())
                    Potrzebujesz więcej wierszy? Zapisz szkic — po zapisaniu pojawi się kolejne puste pole.
                @endunless
                W <a href="{{ route('recipes.create') }}">kreatorze w trzech krokach</a> wiersze
                dodaje się i usuwa od razu, bez zapisywania.
            </p>
        </section>

        {{-- ---------------------------------------------------------------
             4. Przygotowanie
        ---------------------------------------------------------------- --}}
        <section class="form-section card" id="f-steps">
            <h2 class="form-section-title">4. Przygotowanie</h2>
            <p class="meta mb-4">
                Jeden krok to jedna czynność. Krótkie kroki łatwiej czytać przy garnku.
                Przy każdym kroku możesz dopisać, ile minut ma trwać, i dodać zdjęcie —
                jedno i drugie jest nieobowiązkowe.
            </p>
            @error('steps')<p class="field-error">{{ $message }}</p>@enderror

            @for($i = 0; $i < $stepRows; $i++)
                @php
                    $idKroku = $oldSteps[$i]['id'] ?? null;
                    $zdjecieKroku = $idKroku === null ? null : $krokiWBazie->get((string) $idKroku)?->media;
                @endphp
                <fieldset class="wizard-row">
                    <legend class="font-bold mb-3">Krok {{ $i + 1 }}</legend>

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

                    <div class="field">
                        <label for="f-steps-{{ $i }}-photo">Zdjęcie do tego kroku <span class="meta">(nieobowiązkowe)</span></label>
                        <span class="field-help" id="f-steps-{{ $i }}-photo-help">
                            Przydaje się tam, gdzie trudno opisać słowami — jak zawinąć ciasto,
                            jak gęsty ma być sos. Za jednym razem można dodać najwyżej
                            {{ \App\Support\LimityZdjec::maksZdjecKrokowNaZapis() }}
                            {{-- Odmiana liczebnika z JEDNEGO miejsca (issue #86) — inaczej
                                 zmiana limitu dawałaby „5 zdjęcia do kroków". --}}
                            {{ \App\Support\Odmiana::rzeczownik(\App\Support\LimityZdjec::maksZdjecKrokowNaZapis(), 'zdjęcie', 'zdjęcia', 'zdjęć') }}
                            do kroków.
                        </span>

                        @if($zdjecieKroku)
                            {{-- Zdjęcie, które ten krok już ma. Zostaje przy nim
                                 samo — nie trzeba go wybierać drugi raz. --}}
                            <span class="krok-zdjecie">
                                <x-photo :media="$zdjecieKroku" variant="thumb" :zoom="false"
                                         class="krok-zdjecie-obraz"
                                         :alt="'Zdjęcie przy kroku '.($i + 1)"
                                         sizes="160px" />
                            </span>
                            <label class="choice">
                                <input type="checkbox" name="steps[{{ $i }}][remove_photo]" value="1">
                                <span>
                                    <span class="choice-label">Usuń to zdjęcie</span>
                                    <span class="choice-help">Zaznacz i zapisz przepis. Krok zostanie bez zdjęcia.</span>
                                </span>
                            </label>
                        @endif

                        <input class="field-input" id="f-steps-{{ $i }}-photo" type="file"
                               name="steps[{{ $i }}][photo]"
                               accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                               aria-describedby="f-steps-{{ $i }}-photo-help">
                        @error("steps.$i.photo")<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                </fieldset>
            @endfor
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
