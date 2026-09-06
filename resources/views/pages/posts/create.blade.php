<x-layout title="Dodaj zdjęcie" :noindex="true">
    <h1>Dodaj zdjęcie</h1>
    <p style="margin-bottom:var(--spacing-5);">Wybierz zdjęcie z telefonu, napisz kilka słów i kliknij „Opublikuj”. To wszystko.</p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="field @error('photos') has-error @enderror @error('photos.*') has-error @enderror">
            <label for="f-photos">Zdjęcie</label>
            <span class="field-help" id="f-photos-help">
                Na telefonie kliknij tutaj, a potem wybierz „Galeria” albo „Zrób zdjęcie”.
                Największy plik: {{ \App\Support\LimityZdjec::maksMegabajtowDoKomunikatu() }} MB.
            </span>
            {{--
                BEZ atrybutu `multiple` (audyt A31): jedna wysyłka to jedno
                zdjęcie — patrz komentarz przy `max_per_post`
                w `config/kuking.php`. Pole zostaje nazwane `photos[]`,
                bo tak czyta je kontroler (tablica o długości 0 albo 1);
                zmiana nazwy pola byłaby zmianą bez powodu.
            --}}
            <input class="field-input" id="f-photos" type="file" name="photos[]"
                   accept="image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif"
                   aria-describedby="f-photos-help">
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

        <fieldset style="border:0; padding:0; margin-top:var(--spacing-6);">
            <legend style="font-weight:700; margin-bottom:var(--spacing-3);">Kto ma to widzieć?</legend>

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
        <details class="temat-wybor" style="margin-top:var(--spacing-6);" @if(old('topic_id')) open @endif>
            <summary>Dodaj temat (nieobowiązkowo)</summary>

            <p class="field-help" style="margin-top:var(--spacing-3);">
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
