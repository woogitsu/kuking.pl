<x-layout title="Zeszyt" :noindex="true" :szynaWTresci="true">
    <div class="marka-zeszyt">

    <h1>Twój zeszyt</h1>
    {{-- „Przepisy i wpisy", nie same przepisy: od 6 września Zeszyt przyjmuje
         też cudze wpisy (migracja `collection_items_accept_posts`, przycisk
         „Zapisuję" na karcie wpisu). Ten akapit i pusty stan niżej mówiły
         dalej o samych przepisach — obietnica węższa niż produkt, i akurat
         w tę stronę, w którą człowiek nie sprawdzi, bo nie spróbuje. --}}
    <p class="mb-5">Przepisy i wpisy, które chcesz zachować na potem. Tylko Ty je widzisz, chyba że ustawisz inaczej.</p>

    {{-- Błąd przy polu ORAZ w podsumowaniu (docs/UX_50_PLUS.md). Bez tego
         komunikat „Masz już zeszyt o tej nazwie” nie miał gdzie się pokazać:
         jedyny formularz na tej stronie siedzi w zwiniętym <details>. --}}
    <x-error-summary />

    @if($collections->isEmpty())
        <x-empty-state title="Zeszyt jest jeszcze pusty" action="Poszukaj przepisów" :href="route('search', ['sekcja' => 'przepisy'])">
            Kiedy znajdziesz przepis albo czyjeś danie, które chcesz zachować,
            kliknij przy nim „Zapisuję”. Zapisane rzeczy znajdziesz w swoim zeszycie.
        </x-empty-state>
    @else
        <div class="marka-zeszyty">
            @foreach($collections as $collection)
                <article class="card blok-ciemny marka-zeszyt-karta">
                    <h2 class="mt-0">
                        <a class="text-ink" href="{{ route('collections.show', $collection) }}">{{ $collection->name }}</a>
                    </h2>
                    @php
                        // JEDNO ZNACZENIE LICZBY, TAKIE SAMO JAK WEWNĄTRZ ZESZYTU
                        // (issue #774): karta liczy WIDOCZNE zapisy — dokładnie
                        // tyle, ile człowiek zobaczy po kliknięciu — a różnicę
                        // wobec wszystkich zachowanych (prywatne u innych,
                        // zablokowani autorzy, treść usunięta miękko) nazywa
                        // osobnym zdaniem, tym samym wzorcem co `show()`.
                        $niedostepneWTymZeszycie = max(0, ($collection->recipes_total_count ?? 0) - $collection->recipes_count)
                            + max(0, ($collection->posts_total_count ?? 0) - ($collection->posts_count ?? 0));
                    @endphp
                    <p class="meta m-0">
                        {{-- Odmiana przez App\Support\Odmiana: dwustanowa
                             pisała „3 przepisów" (B5). --}}
                        {{ $collection->recipes_count }} {{ \App\Support\Odmiana::rzeczownik($collection->recipes_count, 'przepis', 'przepisy', 'przepisów') }}
                        @if(($collection->posts_count ?? 0) > 0)
                            · {{ $collection->posts_count }} {{ \App\Support\Odmiana::rzeczownik($collection->posts_count, 'wpis', 'wpisy', 'wpisów') }}
                        @endif
                        · {{ $collection->isPublic() ? 'Widoczny dla wszystkich' : 'Tylko dla Ciebie' }}
                    </p>
                    @if($niedostepneWTymZeszycie > 0)
                        <p class="meta m-0" data-niedostepne-zapisy>
                            {{ $niedostepneWTymZeszycie }}
                            {{ \App\Support\Odmiana::rzeczownik($niedostepneWTymZeszycie, 'zapis nie jest dla Ciebie dostępny', 'zapisy nie są dla Ciebie dostępne', 'zapisów nie jest dla Ciebie dostępnych') }}.
                        </p>
                    @endif
                    @if($collection->description)
                        <p class="mt-3">{{ $collection->description }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    {{-- Jedna lista ostatnich zapisów w głównej treści (D-211), przed
         formularzem. Kolejność i dostępność nadal ustala kontroler. --}}
    <x-szyna-ostatnio-zapisane :pozycje="$ostatnioZapisane" />

    {{-- Po nieudanej walidacji formularz zostaje ROZWINIĘTY — inaczej człowiek
         wraca na stronę, na której nic się nie stało, a jego tekst jest
         schowany pod zwiniętym „Załóż nowy zeszyt”.

         KLASA ZOSTAJE JEDNA, A WARSTWY SĄ DWIE — i to nie jest przeoczenie.
         Mocna obwódka panelu obiecuje pola do wypełnienia (D-126), a w stanie
         zwiniętym otacza sam przycisk. `@class([...])`, którym tę warstwę
         wybiera się na czterech innych ekranach, tutaj nie zadziała: o tym,
         czy pola widać, decyduje atrybut `open`, przestawiany kliknięciem już
         po wyjściu odpowiedzi z serwera. Robi to więc arkusz —
         `details.panel-formularza:not([open])` w `tokens.css` — bo tylko on
         czyta ten stan na żywo i bez JavaScriptu. --}}
    <details class="panel-formularza mt-8" {{ $errors->any() || $saveContext !== [] ? 'open' : '' }}>
        <summary class="btn btn-secondary inline-flex">Załóż nowy zeszyt</summary>
        <form class="mt-4" method="POST" action="{{ route('collections.store') }}">
            @csrf
            @foreach($saveContext as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            @if($saveContent)
                <p>Po założeniu zeszytu możesz dokończyć zapis. Samo założenie zeszytu nie zapisuje w nim treści.</p>
                <a class="btn btn-secondary" href="{{ $saveContent->url() }}">Anuluj i wróć {{ $saveContent instanceof \App\Models\Recipe ? 'do przepisu' : 'do wpisu' }}</a>
            @elseif($saveContext !== [])
                <p>Ta treść nie jest już dostępna. Możesz założyć pusty zeszyt albo wrócić do szukania.</p>
                <a class="btn btn-secondary" href="{{ route('search') }}">Szukaj</a>
            @endif
            <x-field name="name" label="Nazwa zeszytu" required placeholder="Na święta" />
            <x-field name="description" label="Krótki opis" type="textarea" :rows="2" />
            {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty
                 ARIA wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
            <fieldset class="border-0 p-0 mt-4" id="f-visibility"
                      @error('visibility') tabindex="-1" aria-invalid="true" aria-describedby="f-visibility-error" @enderror>
                <legend class="font-bold mb-3">Kto ma widzieć ten zeszyt?</legend>
                <div class="choice-grid">
                    {{-- `old()` ZAMIAST `checked` NA SZTYWNO.

                         Reguła UX 50+ „poprawnie wpisane dane nigdy nie znikają"
                         pękała tu w jedną stronę i tylko tu: `checked` stało
                         wpisane przy „Tylko ja", więc kto wybrał „Wszyscy"
                         i pomylił się w nazwie zeszytu, dostawał formularz
                         z powrotem z zaznaczonym „Tylko ja". Zmiana widoczności
                         bez zamiaru jest groźniejsza niż utrata tekstu, bo cichsza. --}}
                    <label class="choice">
                        <input type="radio" name="visibility" value="private"
                               @checked(old('visibility', 'private') === 'private')>
                        <span class="choice-label">Tylko ja</span>
                    </label>
                    @can('create', [\App\Models\Collection::class, 'public'])
                    <label class="choice">
                        <input type="radio" name="visibility" value="public"
                               @checked(old('visibility') === 'public')>
                        <span class="choice-label">Wszyscy</span>
                    </label>
                    @endcan
                </div>
                <x-blad-grupy name="visibility" />
            </fieldset>
            <button class="btn btn-primary mt-4" type="submit">Załóż zeszyt</button>
        </form>
    </details>
    </div>
</x-layout>
