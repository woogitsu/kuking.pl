<x-layout title="Dodaj zdjęcie" :noindex="true">
    <h1>Dodaj zdjęcie</h1>
    <p class="mb-5">Wybierz zdjęcie z telefonu, napisz kilka słów i kliknij „Opublikuj”. To wszystko.</p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="field @error('photos') has-error @enderror @error('photos.*') has-error @enderror">
            <label for="f-photos">Zdjęcie <span class="meta">(możesz wybrać kilka)</span></label>
            <span class="field-help" id="f-photos-help">
                Na telefonie kliknij tutaj, a potem wybierz „Galeria” albo „Zrób zdjęcie”.
                Największy plik: {{ \App\Support\LimityZdjec::maksMegabajtowDoKomunikatu() }} MB.
            </span>
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
                    Nie musisz wybierać ich jeszcze raz — popraw tylko to, co jest zaznaczone na czerwono.
                    <ul class="stack-tight" style="margin:var(--spacing-3) 0 0; padding:0; list-style:none;">
                        @foreach($zachowane as $zdjecie)
                            <li>
                                <input type="hidden" name="media_ids[]" value="{{ $zdjecie->getKey() }}">
                                <x-photo :media="$zdjecie" variant="thumb" :zoom="false" class="post-photo" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <input class="field-input" id="f-photos" type="file" name="photos[]"
                   accept="image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif"
                   multiple aria-describedby="f-photos-help">
            @error('photos')<span class="field-error">{{ $message }}</span>@enderror
            @error('photos.*')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <x-field
            name="body"
            label="Napisz kilka słów"
            type="textarea"
            :rows="5"
            help="Na przykład: „Rosół na niedzielę, z kaczki od sąsiada. Wyszedł złoty.”"
        />

        <fieldset class="border-0 p-0 mt-6">
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
            @error('visibility')<span class="field-error">{{ $message }}</span>@enderror
        </fieldset>

        {{--
            Temat — OPCJONALNY i celowo na końcu formularza (issue #31).

            Cel produktowy to poniżej 60 sekund od wejścia do opublikowania,
            więc nic tutaj nie może zatrzymać osoby, która chce tylko wrzucić
            zdjęcie. Pole jest zwinięte, domyślnie puste i pierwsze zdanie
            mówi wprost, że można je pominąć.

            Zwykłe `<select>`, nie siatka kafelków jak przy widoczności:
            trzydzieści pozycji w kafelkach to ekran przewijany trzy razy,
            a wybór tematu nie jest decyzją, nad którą trzeba się zastanawiać.
            Bez JavaScriptu — `<details>` i `<select>` działają same z siebie.
        --}}
        <details class="temat-wybor mt-6" @if(old('topic_id')) open @endif>
            <summary>Dodaj temat (nieobowiązkowo)</summary>

            <p class="field-help mt-3">
                Temat pomaga innym znaleźć Twój wpis, a Tobie — trafić na ludzi,
                którzy gotują to samo. Możesz to pominąć.
            </p>

            <label for="topic_id">Temat</label>
            <select id="topic_id" name="topic_id">
                <option value="">— bez tematu —</option>
                @foreach($topics as $topic)
                    <option value="{{ $topic->id }}" @selected(old('topic_id') === $topic->id)>{{ $topic->name }}</option>
                @endforeach
            </select>
            @error('topic_id')<span class="field-error">{{ $message }}</span>@enderror
        </details>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Opublikuj</button>
            <a class="btn btn-quiet" href="{{ route('home') }}">Nie teraz</a>
        </div>
    </form>
</x-layout>
