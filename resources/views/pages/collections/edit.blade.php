<x-layout title="Edytuj zeszyt" :noindex="true">
    <div class="marka-zeszyt">
    <h1>Edytuj zeszyt „{{ $collection->name }}”</h1>
    <p class="mb-5">Zmień nazwę, opis albo to, kto widzi ten zeszyt. Przepisy i wpisy w nim zostają bez zmian.</p>

    <x-error-summary />

    {{--
        FORMULARZ WYPEŁNIONY DZISIEJSZYMI WARTOŚCIAMI, NIE PUSTY (issue #777).

        `old('pole', $collection->pole)` — po błędzie walidacji wraca to, co
        człowiek WPISAŁ (`old()`), a przy pierwszym wejściu to, co zeszyt MA
        dzisiaj. Ta sama zasada co przy zakładaniu zeszytu (D-126): poprawnie
        wpisane dane nigdy nie znikają, a zmiana widoczności bez zamiaru jest
        groźniejsza niż utrata tekstu, bo cichsza.
    --}}
    <form method="POST" action="{{ route('collections.update', $collection) }}">
        @csrf
        @method('PATCH')
        <x-field name="name" label="Nazwa zeszytu" required :value="$collection->name" />
        <x-field name="description" label="Krótki opis" type="textarea" :rows="2" :value="$collection->description" />
        <fieldset class="border-0 p-0 mt-4" id="f-visibility"
                  @error('visibility') tabindex="-1" aria-invalid="true" aria-describedby="f-visibility-error" @enderror>
            <legend class="font-bold mb-3">Kto ma widzieć ten zeszyt?</legend>
            <div class="choice-grid">
                <label class="choice">
                    <input type="radio" name="visibility" value="private"
                           @checked(old('visibility', $collection->visibility) === 'private')>
                    <span class="choice-label">Tylko ja</span>
                </label>
                <label class="choice">
                    <input type="radio" name="visibility" value="public"
                           @checked(old('visibility', $collection->visibility) === 'public')>
                    <span class="choice-label">Wszyscy</span>
                </label>
            </div>
            @if($collection->is_default)
                {{--
                    SKUTEK DLA PRZYSZŁYCH ZAPISÓW, NIE TYLKO DZISIEJSZYCH (issue #1400).
                    Szybkie „Zapisuję” bez wyboru zeszytu trafia właśnie tutaj,
                    więc „Wszyscy” pokazuje też to, co człowiek zapisze jutro.
                --}}
                <p class="notice mt-2" id="skutek-domyslnego-zeszytu">To Twój główny zeszyt. Przycisk „Zapisuję” wkłada tu każdy przepis i wpis, jeśli nie wybierzesz innego zeszytu. Gdy wybierzesz „Wszyscy”, inne zalogowane osoby zobaczą to, co już tu jest, i wszystko, co zapiszesz tu później.</p>
            @endif
            @if($collection->isPublic())
                <p class="meta mt-2">Zmiana na „Tylko ja” od razu zamyka dotychczasowy bezpośredni adres dla innych osób.</p>
            @endif
            <x-blad-grupy name="visibility" />
        </fieldset>
        <button class="btn btn-primary mt-4" type="submit">Zapisz zmiany</button>
        <a class="btn btn-secondary mt-4" href="{{ route('collections.show', $collection) }}">Anuluj</a>
    </form>
    </div>
</x-layout>
