<x-layout title="Ugotowałem" :noindex="true">
    <h1>Ugotowałem: {{ $recipe->title }}</h1>
    <p class="mb-5">
        @if($recipe->author_id !== auth()->id() && $recipe->author->mozeCzytac())
            {{ $recipe->author->displayName() }} dowie się, że ktoś ugotował z tego przepisu.
        @else
            Zapisz wykonanie tego przepisu.
        @endif
        <strong>Nie musisz wypełniać żadnego pola</strong> — wystarczy, że klikniesz „Wyślij”.
    </p>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('cooked.store', $recipe->slug) }}" enctype="multipart/form-data">
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
            {{-- Przy błędzie opis pola rośnie o TREŚĆ BŁĘDU (issue #1572),
                 żeby czytnik ekranu po przejściu z podsumowania do pola
                 przeczytał, co jest nie tak. Pomoc zostaje pierwsza. --}}
            @php
                $opisZdjec = implode(' ', array_keys(array_filter([
                    'f-photos-help' => true,
                    'f-photos-error' => $errors->has('photos'),
                    'f-photos-plik-error' => $errors->has('photos.*'),
                ])));
                $bladZdjec = $errors->has('photos') || $errors->has('photos.*');
            @endphp
            <input class="visually-hidden pole-zdjecia-input" id="f-photos" type="file" name="photos[]"
                   accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                   multiple
                   aria-labelledby="f-photos-etykieta f-photos-tytul"
                   aria-describedby="{{ $opisZdjec }}" @if($bladZdjec) aria-invalid="true" @endif>
            <label class="pole-zdjecia" for="f-photos">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-photos-tytul">Dodaj zdjęcie</span>
                <span class="field-help" id="f-photos-help">
                    To jest najmilsza część dla autora przepisu. Zdjęcie nie musi być ładne.
                </span>
            </label>
            @error('photos')<span class="field-error" id="f-photos-error">{{ $message }}</span>@enderror
            @error('photos.*')<span class="field-error" id="f-photos-plik-error">{{ $message }}</span>@enderror
        </div>

        <x-field name="note" label="Jak wyszło?" type="textarea" :rows="4"
                 help="Na przykład: „Wyszło pięknie, tylko soli mniej.”" />

        {{-- POMOC MÓWI, CO TU WPISAĆ I GDZIE TO TRAFI, A NIE JAK CZĘSTO
             KTOŚ TO CZYTA.

             Stało tu „To najczęściej czytana część." — trzecie i ostatnie
             miejsce tego samego niezmierzonego twierdzenia (dwa pozostałe:
             `components/recipe-wizard.blade.php` i
             `pages/recipes/create.blade.php`). Nikt nigdy nie mierzył, co
             w cudzym wykonaniu czyta się najczęściej, a tutaj zdanie było
             dodatkowo mylące: „część" znaczyło raz część przepisu, raz część
             tego formularza.

             Nowe zdanie mówi rzecz sprawdzalną przy kodzie: notatka trafia na
             kartę wykonania (`components/cooked-card.blade.php`), podpisana
             dokładnie tak. --}}
        <x-field name="changes_note" label="Coś po swojemu?" type="textarea" :rows="3"
                 help="Zamiana składnika, inny czas, inna forma. Pokażemy to przy Twoim wykonaniu, podpisane „Po swojemu”." />

        <x-field name="actual_minutes" label="Ile Ci to zajęło (w minutach)" type="number"
                 inputmode="numeric" :min="0" :max="10080" />

        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0 mt-6" id="f-would_make_again"
                  @error('would_make_again') tabindex="-1" aria-invalid="true" aria-describedby="f-would_make_again-error" @enderror>
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
                <label class="choice">
                    <input type="radio" name="would_make_again" value="" @checked(old('would_make_again') === '' || old('would_make_again') === null)>
                    <span class="choice-label">Nie podaję</span>
                </label>
            </div>
            <x-blad-grupy name="would_make_again" />
        </fieldset>

        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0 mt-6" id="f-perceived_difficulty"
                  @error('perceived_difficulty') tabindex="-1" aria-invalid="true" aria-describedby="f-perceived_difficulty-error" @enderror>
            <legend class="font-bold mb-3">Jak trudne to było dla Ciebie?</legend>
            <div class="choice-grid">
                @foreach(\App\Models\Recipe::DIFFICULTY_LABELS as $value => $label)
                    <label class="choice">
                        <input type="radio" name="perceived_difficulty" value="{{ $value }}" @checked(old('perceived_difficulty') === $value)>
                        <span class="choice-label">{{ $label }}</span>
                    </label>
                @endforeach
                <label class="choice">
                    <input type="radio" name="perceived_difficulty" value="" @checked(empty(old('perceived_difficulty')))>
                    <span class="choice-label">Nie podaję</span>
                </label>
            </div>
            <x-blad-grupy name="perceived_difficulty" />
        </fieldset>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij</button>
            <a class="btn btn-quiet" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a>
        </div>
    </form>
</x-layout>
