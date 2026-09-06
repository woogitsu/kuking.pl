<x-layout title="Ugotowałem" :noindex="true">
    <h1>Ugotowałem: {{ $recipe->title }}</h1>
    <p style="margin-bottom:var(--spacing-5);">
        {{ $recipe->author->displayName() }} dowie się, że ktoś ugotował z tego przepisu.
        <strong>Nie musisz wypełniać żadnego pola</strong> — wystarczy, że klikniesz „Wyślij”.
    </p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('cooked.store', $recipe->slug) }}" enctype="multipart/form-data">
        @csrf

        <div class="field">
            <label for="f-photos">Zdjęcie tego, co Ci wyszło</label>
            <span class="field-help" id="f-photos-help">
                To jest najmilsza część dla autora przepisu. Zdjęcie nie musi być ładne.
            </span>
            <input class="field-input" id="f-photos" type="file" name="photos[]"
                   accept="image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif"
                   multiple aria-describedby="f-photos-help">
            @error('photos')<span class="field-error">{{ $message }}</span>@enderror
            @error('photos.*')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <x-field name="note" label="Jak wyszło?" type="textarea" :rows="4"
                 help="Na przykład: „Wyszło pięknie, tylko dałam mniej soli.”" />

        <x-field name="changes_note" label="Zrobiłem coś po swojemu?" type="textarea" :rows="3"
                 help="Zamiana składnika, inny czas, inna forma. To najczęściej czytana część." />

        <x-field name="actual_minutes" label="Ile Ci to zajęło (w minutach)" type="number"
                 inputmode="numeric" :min="0" :max="10080" />

        <fieldset style="border:0; padding:0; margin-top:var(--spacing-6);">
            <legend style="font-weight:700; margin-bottom:var(--spacing-3);">Zrobisz to jeszcze raz?</legend>
            <div class="choice-grid">
                <label class="choice">
                    <input type="radio" name="would_make_again" value="1" @checked(old('would_make_again') === '1')>
                    <span class="choice-label">Tak, zrobię ponownie</span>
                </label>
                <label class="choice">
                    <input type="radio" name="would_make_again" value="0" @checked(old('would_make_again') === '0')>
                    <span class="choice-label">Raczej nie powtórzę</span>
                </label>
            </div>
        </fieldset>

        <fieldset style="border:0; padding:0; margin-top:var(--spacing-6);">
            <legend style="font-weight:700; margin-bottom:var(--spacing-3);">Jak trudne to było dla Ciebie?</legend>
            <div class="choice-grid">
                @foreach(\App\Models\Recipe::DIFFICULTY_LABELS as $value => $label)
                    <label class="choice">
                        <input type="radio" name="perceived_difficulty" value="{{ $value }}" @checked(old('perceived_difficulty') === $value)>
                        <span class="choice-label">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij</button>
            <a class="btn btn-quiet" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a>
        </div>
    </form>
</x-layout>
