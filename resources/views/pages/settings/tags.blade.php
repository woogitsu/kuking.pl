{{--
    „Twoje tagi" — jeden ekran z całą listą (D-021, zastępuje
    `pages/settings/topics.blade.php`).

    Lista to SUMA tagów już obserwowanych i tagów promowanych (D-021, „tag
    promowany — lista gospodarza") — patrz komentarz w
    `TagFollowController::edit()`, dlaczego to musi być suma, nie sama lista
    promowana: wszechświat tagów nie jest zamknięty.

    Zwykły formularz, bez JavaScriptu: zaznaczenie i „Zapisz".
--}}
<x-layout title="Twoje tagi" :noindex="true">
    <h1>Twoje tagi</h1>

    <p class="text-lead">
        Gdy nie ma wpisów od obserwowanych osób, pokazujemy wpisy z Twoich tagów. Jeśli i tam jest pusto, zobaczysz najnowsze publiczne wpisy.
    </p>

    <x-error-summary />

    @if($tags->isEmpty())
        <div id="f-tags" tabindex="-1"><x-blad-grupy name="tags" /></div>
        <x-empty-state
            title="Nie obserwujesz jeszcze żadnego tagu"
            action="Zobacz wszystkie tagi"
            :href="route('tags.index')">
            <p class="mb-0">
                Wybierz tag i kliknij „Obserwuj ten tag" — albo wróć tutaj,
                gdy gospodarz doda pierwsze propozycje.
            </p>
        </x-empty-state>
    @else
        <form method="POST" action="{{ route('settings.tags.update') }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="form_scope" value="{{ $formScope }}">

            <fieldset id="f-tags" class="choice-fieldset" @error('tags') aria-invalid="true" aria-describedby="f-tags-error" tabindex="-1" @enderror>
                <legend class="sr-only">Wybierz tagi do obserwowania</legend>

                <div class="choice-grid">
                    @foreach($tags as $index => $tag)
                        <label class="choice" id="f-tags-{{ $index }}">
                            <input type="checkbox" name="tags[]" value="{{ $tag->getKey() }}"
                                   @checked(in_array($tag->getKey(), $wybrane, true))>
                            <span class="choice-label">{{ $tag->name }}</span>
                        </label>
                    @endforeach
                </div>

                <x-blad-grupy name="tags" />
            </fieldset>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Zapisz</button>
                <a class="btn btn-quiet" href="{{ route('home') }}">Wróć na stronę główną</a>
            </div>
        </form>
    @endif

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="tags" />
    </x-slot:rail>
</x-layout>
