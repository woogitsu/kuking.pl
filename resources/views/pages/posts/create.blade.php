<x-layout title="Dodaj zdjęcie" :noindex="true">
    {{-- Zakładki „Zdjęcie i kilka słów” / „Cały przepis” (issue #366).

         STOJĄ PRZED NAGŁÓWKIEM I TO JEST CAŁA ICH ROBOTA. Osiem z jedenastu
         drzwi do dodawania prowadzi prosto tutaj — kafel na `/home`, pusty
         stan feedu, „Dodaj zdjęcie” na profilu, pusty stan profilu, `/tag/…`,
         pusty stan `/odkryj`, koniec onboardingu i „Dodaj kolejne zdjęcie”
         pod wpisem. Wchodzący którymikolwiek z nich ma zobaczyć, że jest też
         druga możliwość, ZANIM zacznie wypełniać ten formularz.

         To odnośnik, nie przełącznik przebudowujący pola: uzasadnienie stoi
         w komentarzu samego komponentu. --}}
    <x-zakladki-dodawania aktywna="zdjecie" />

    <h1>Dodaj zdjęcie</h1>
    <p class="mb-5">Wybierz zdjęcie z telefonu, napisz kilka słów i kliknij „Opublikuj”. To wszystko.</p>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data">
        @csrf

        {{-- Tożsamość TEGO wysłania formularza (ADR
             docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md). Dzięki niej drugie
             kliknięcie „Opublikuj" — a w grupie 50+ jest ono normalnym
             sposobem obsługi komputera — nie tworzy drugiego wpisu.

             Zwykłe ukryte pole, bez linijki JavaScriptu: publikacja musi
             działać bez skryptu, bo przy słabym łączu skrypt się nie dociąga,
             a to jest ten sam moment, w którym strona myśli i klika się drugi
             raz (AGENTS.md §5, D-007).

             Nazwa NIE MOŻE zawierać fragmentu „token": OdzyskiwalneDane
             wycina takie pola, więc na ekranie 419 klucz by nie wrócił
             (zmierzone, ADR §1.4.4). --}}
        @if(($kluczWyslania ?? null) !== null)
            <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">
        @endif

        <div class="field @error('photos') has-error @enderror @error('photos.*') has-error @enderror">
            {{-- Nazwa pola jest `<span>`, a nie `<label>`: jedyną etykietą tego
                 pola jest duży obszar wyboru niżej (D-035). Powód — jedno pole,
                 jedna etykieta — stoi w resources/css/ekran-dodawania.css przy
                 `.pole-zdjecia-nazwa`. Nazwa wraca do pola przez
                 `aria-labelledby`, więc czytnik ekranu dalej ją czyta. --}}
            <span class="pole-zdjecia-nazwa" id="f-photos-etykieta">Zdjęcie <span class="meta">(możesz wybrać kilka)</span></span>

            @php
                // Zdjęcia, które przetrwały nieudaną walidację (audyt C1).
                // Wracają jako identyfikatory, bo przeglądarka nie pozwala
                // wypełnić pola pliku z serwera — i dobrze robi, inaczej strona
                // mogłaby podkraść plik z dysku.
                $zachowane = \App\Models\Media::query()
                    ->whereIn('id', (array) old('media_ids', []))
                    ->where('owner_id', auth()->id())
                    ->get();
            @endphp

            @if($zachowane->isNotEmpty())
                <div class="notice">
                    <strong>Twoje zdjęcia są zachowane.</strong>
                    Nie musisz wybierać ich jeszcze raz — popraw tylko to, co wypisaliśmy na górze formularza.
                    <ul class="stack-tight lista-naga mt-3">
                        @foreach($zachowane as $zdjecie)
                            <li>
                                <input type="hidden" name="media_ids[]" value="{{ $zdjecie->getKey() }}">
                                <x-photo :media="$zdjecie" variant="thumb" :zoom="false" class="post-photo" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Duży obszar wyboru zdjęcia (UI kit v2, 08_mobile_add.html →
                 `PhotoPicker`, resources/css/ekran-dodawania.css). Natywne pole
                 pliku jest tu SCHOWANE DLA OKA (decyzja właściciela D-035):
                 przeglądarka rysowała w nim angielskie „Choose File / No file
                 chosen" w środku polskiego formularza i nie da się tego zmienić
                 żadnym atrybutem. Klikalna zostaje etykieta — to natywne
                 zachowanie HTML, działa bez JavaScriptu.

                 Pole ZOSTAJE w drzewie dostępności i pod klawiaturą: chowa je
                 `.visually-hidden`, nigdy `display: none` ani
                 `visibility: hidden`. Fokus na nim rysuje obwódkę wokół
                 obszaru.

                 KOLEJNOŚĆ JEST WYMUSZONA: `<input>` stoi BEZPOŚREDNIO PRZED
                 `<label>`, bo obwódkę fokusu rysuje reguła
                 `.pole-zdjecia-input:focus-visible + .pole-zdjecia`. --}}
            <input class="visually-hidden pole-zdjecia-input" id="f-photos" type="file" name="photos[]"
                   accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                   multiple
                   aria-labelledby="f-photos-etykieta f-photos-tytul"
                   aria-describedby="f-photos-help">
            <label class="pole-zdjecia" for="f-photos">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-photos-tytul">Dodaj zdjęcie</span>
                <span class="field-help" id="f-photos-help">
                    Na telefonie kliknij tutaj, a potem wybierz „Galeria” albo „Zrób zdjęcie”.
                    {{ \App\Support\LimityZdjec::pomocLiczbyZdjec($zachowane->count()) }}
                    Największy plik: {{ \App\Support\LimityZdjec::maksMegabajtowDoKomunikatu() }} MB.
                </span>
            </label>
            @error('photos')<span class="field-error">{{ $message }}</span>@enderror
            @error('photos.*')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div data-tagi-opis data-tagi-endpoint="{{ route('tags.suggestions') }}"
             data-tagi-min="{{ \App\Support\LimityTagow::minZnakow() }}"
             data-tagi-max="{{ config('kuking.tags.suggestions_query_max_length') }}">
        <x-field
            name="body"
            label="Napisz kilka słów"
            type="textarea"
            :rows="5"
            help="Na przykład: „Rosół na niedzielę, z kaczki od sąsiada. Wyszedł złoty.”"
        />
            <x-tagi-formularz :tag-names="$tagNames" :sugestie-tagow="$sugestieTagow" />
        </div>

        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0 mt-6" id="f-visibility"
                  @error('visibility') tabindex="-1" aria-invalid="true" aria-describedby="f-visibility-error" @enderror>
            <legend class="font-bold mb-3">Kto ma to widzieć?</legend>

            <div class="choice-grid">
                <label class="choice">
                    <input type="radio" name="visibility" value="public" @checked(old('visibility', 'public') === 'public')>
                    <span>
                        <span class="choice-label">Wszyscy</span>
                        <span class="choice-help">Także osoby bez konta. Wpis może pojawić się w Google.</span>
                    </span>
                </label>

                <label class="choice">
                    <input type="radio" name="visibility" value="followers" @checked(old('visibility') === 'followers')>
                    <span>
                        <span class="choice-label">Tylko osoby, które mnie obserwują</span>
                        <span class="choice-help">Nie trafi do Google ani do osób bez konta.</span>
                    </span>
                </label>

                <label class="choice">
                    <input type="radio" name="visibility" value="private" @checked(old('visibility') === 'private')>
                    <span>
                        <span class="choice-label">Tylko ja</span>
                        <span class="choice-help">Twoje prywatne archiwum. Nikt inny tego nie zobaczy.</span>
                    </span>
                </label>
            </div>
            <x-blad-grupy name="visibility" />
        </fieldset>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Opublikuj</button>
            <a class="btn btn-quiet" href="{{ route('home') }}">Nie teraz</a>
        </div>

        {{-- Zapewnienie z kitu (08_mobile_add.html, `.m-info`) — dwa fakty,
             oba prawdziwe: wpis da się później zmienić/usunąć (posts.edit,
             posts.destroy) i pipeline zdjęć zdejmuje EXIF/GPS przy zapisie
             (AGENTS.md §7, „Pipeline zdjęć"). --}}
        <p class="field-help text-center mt-4">
            Możesz zmienić lub usunąć wpis później. Zdjęcia publikujemy bez danych EXIF i GPS.
        </p>
    </form>
</x-layout>
