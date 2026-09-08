<x-layout title="Ugotowałem" :noindex="true">
    <h1>Ugotowałem: {{ $recipe->title }}</h1>
    <p class="mb-5">
        {{ $recipe->author->displayName() }} dowie się, że ktoś ugotował z tego przepisu.
        <strong>Nie musisz wypełniać żadnego pola</strong> — wystarczy, że klikniesz „Wyślij”.
    </p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('cooked.store', $recipe->slug) }}" enctype="multipart/form-data">
        @csrf

        {{-- Tożsamość TEGO wysłania formularza (ADR
             docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md). Bez niej dwa
             kliknięcia „Wyślij" dawały dwa wykonania i DWA powiadomienia
             u autora — a powiadomienia nie da się cofnąć.

             To NIE jest unikalność na parze (osoba, przepis): drugie
             prawdziwe gotowanie przychodzi z nowego formularza, więc z nowym
             kluczem, i zapisuje się normalnie (D-005).

             Zwykłe ukryte pole, bez JavaScriptu. Nazwa bez fragmentu
             „token", inaczej pole ginie na ekranie 419 (ADR §1.4.4). --}}
        @if(($kluczWyslania ?? null) !== null)
            <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">
        @endif

        {{-- Ten sam obszar wyboru zdjęcia co na „Dodaj zdjęcie" i w formularzu
             przepisu (`.pole-zdjecia`, resources/css/ekran-dodawania.css).
             Do tej zmiany stał tu goły `<input type="file">` z angielskim
             „Choose File / No file chosen". Po D-035 pole jest schowane dla
             oka, ale zostaje pod klawiaturą i w drzewie dostępności, a klikalna
             jest etykieta. `<input>` MUSI stać bezpośrednio przed `<label>` —
             obwódkę fokusu rysuje reguła sąsiedztwa. --}}
        <div class="field @error('photos') has-error @enderror @error('photos.*') has-error @enderror">
            <span class="pole-zdjecia-nazwa" id="f-photos-etykieta">Zdjęcie tego, co Ci wyszło</span>
            <input class="visually-hidden pole-zdjecia-input" id="f-photos" type="file" name="photos[]"
                   accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                   multiple
                   aria-labelledby="f-photos-etykieta f-photos-tytul"
                   aria-describedby="f-photos-help">
            <label class="pole-zdjecia" for="f-photos">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-photos-tytul">Dodaj zdjęcie</span>
                <span class="field-help" id="f-photos-help">
                    To jest najmilsza część dla autora przepisu. Zdjęcie nie musi być ładne.
                </span>
            </label>
            @error('photos')<span class="field-error">{{ $message }}</span>@enderror
            @error('photos.*')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <x-field name="note" label="Jak wyszło?" type="textarea" :rows="4"
                 help="Na przykład: „Wyszło pięknie, tylko dałam mniej soli.”" />

        <x-field name="changes_note" label="Zrobiłem coś po swojemu?" type="textarea" :rows="3"
                 help="Zamiana składnika, inny czas, inna forma. To najczęściej czytana część." />

        <x-field name="actual_minutes" label="Ile Ci to zajęło (w minutach)" type="number"
                 inputmode="numeric" :min="0" :max="10080" />

        <fieldset class="border-0 p-0 mt-6">
            <legend class="font-bold mb-3">Zrobisz to jeszcze raz?</legend>
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

        <fieldset class="border-0 p-0 mt-6">
            <legend class="font-bold mb-3">Jak trudne to było dla Ciebie?</legend>
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
