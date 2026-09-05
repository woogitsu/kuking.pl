@php
    $isEdit = $recipe !== null;
    $action = $isEdit ? route('recipes.update', $recipe->slug) : route('recipes.store');
    // Trzy pierwsze puste wiersze składników i kroków są od razu widoczne —
    // pusta lista z jednym przyciskiem „Dodaj składnik” jest mniej zrozumiała
    // niż gotowe pola do wypełnienia.
    $ingredientRows = max(3, count(old('ingredients', $isEdit ? $recipe->ingredients->all() : [])) + 1);
    $stepRows = max(3, count(old('steps', $isEdit ? $recipe->steps->all() : [])) + 1);
    $oldIngredients = old('ingredients', $isEdit ? $recipe->ingredients->map(fn ($i) => ['text' => $i->ingredient_text, 'group_name' => $i->group_name, 'note' => $i->note])->all() : []);
    $oldSteps = old('steps', $isEdit ? $recipe->steps->map(fn ($s) => ['instruction' => $s->instruction])->all() : []);
@endphp

<x-layout :title="$isEdit ? 'Edytuj przepis' : 'Dodaj przepis'" :noindex="true">
    <h1>{{ $isEdit ? 'Edytuj przepis' : 'Dodaj przepis' }}</h1>
    <p style="margin-bottom:var(--spacing-5);">
        Wszystko jest na jednej stronie, żebyś nie musiał nic przewijać ani szukać.
        Jeśli nie masz teraz czasu, kliknij na dole <strong>„Zapisz szkic”</strong> —
        nic nie zginie i wrócisz do tego, kiedy zechcesz.
    </p>

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
                       accept="image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif"
                       aria-describedby="f-hero_photo-help">
                @error('hero_photo')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <x-field name="summary" label="Krótko o przepisie" type="textarea" :rows="3"
                     :value="$isEdit ? $recipe->summary : null"
                     help="Jedno-dwa zdania. Na co ten przepis jest dobry, kiedy go robisz." />

            <div style="display:grid; gap:var(--spacing-4); grid-template-columns:repeat(auto-fit, minmax(12rem, 1fr));">
                <x-field name="servings" label="Na ile porcji" type="number" inputmode="decimal"
                         :value="$isEdit ? $recipe->servings : null" :min="0.5" :max="999" :step="0.5" />
                <x-field name="prep_minutes" label="Przygotowanie (minuty)" type="number" inputmode="numeric"
                         :value="$isEdit ? $recipe->prep_minutes : null" :min="0" :max="10080" />
                <x-field name="cook_minutes" label="Gotowanie / pieczenie (minuty)" type="number" inputmode="numeric"
                         :value="$isEdit ? $recipe->cook_minutes : null" :min="0" :max="10080" />
            </div>

            <fieldset style="border:0; padding:0; margin-top:var(--spacing-6);">
                <legend style="font-weight:700; margin-bottom:var(--spacing-3);">Jak trudny jest ten przepis?</legend>
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

            <fieldset style="border:0; padding:0; margin-top:var(--spacing-6);">
                <legend style="font-weight:700; margin-bottom:var(--spacing-3);">Kto ma widzieć ten przepis?</legend>
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
            <p class="meta" style="margin-bottom:var(--spacing-4);">
                To najczęściej czytana część przepisu. Ludzie chcą wiedzieć, po kim on jest.
            </p>

            <fieldset style="border:0; padding:0;">
                <legend style="font-weight:700; margin-bottom:var(--spacing-3);">Ten przepis jest…</legend>
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
                       accept="image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif"
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
            <p class="meta" style="margin-bottom:var(--spacing-4);">
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
                </div>
            @endfor

            <p class="field-help">
                Potrzebujesz więcej wierszy? Zapisz szkic — po zapisaniu pojawi się kolejne puste pole.
            </p>
        </section>

        {{-- ---------------------------------------------------------------
             4. Przygotowanie
        ---------------------------------------------------------------- --}}
        <section class="form-section card">
            <h2 class="form-section-title">4. Przygotowanie</h2>
            <p class="meta" style="margin-bottom:var(--spacing-4);">
                Jeden krok = jedna czynność. Krótkie kroki są łatwiejsze do czytania przy garnku.
            </p>

            @for($i = 0; $i < $stepRows; $i++)
                <div class="field">
                    <label for="f-steps-{{ $i }}-instruction">Krok {{ $i + 1 }}</label>
                    <textarea class="field-input" id="f-steps-{{ $i }}-instruction"
                              name="steps[{{ $i }}][instruction]" rows="3"
                              @if($i === 0) placeholder="Kurczaka zalej zimną wodą i zagotuj. Zbierz szumowiny." @endif
                    >{{ $oldSteps[$i]['instruction'] ?? '' }}</textarea>
                    @error("steps.$i.instruction")<span class="field-error">{{ $message }}</span>@enderror
                </div>
            @endfor
        </section>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit" name="action" value="publish">
                {{ $isEdit && $recipe->isPublished() ? 'Zapisz zmiany' : 'Opublikuj przepis' }}
            </button>
            <button class="btn btn-secondary" type="submit" name="action" value="draft">Zapisz szkic</button>
            <a class="btn btn-quiet" href="{{ route('home') }}">Nie teraz</a>
        </div>
    </form>
</x-layout>
