<x-layout title="Dodaj zdjęcie" :noindex="true">
    <h1>Dodaj zdjęcie</h1>
    <p class="mb-5">Wybierz zdjęcie z telefonu, napisz kilka słów i kliknij „Opublikuj”. To wszystko.</p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="field @error('photos') has-error @enderror @error('photos.*') has-error @enderror">
            <label for="f-photos">Zdjęcie <span class="meta">(możesz wybrać kilka)</span></label>

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
                 `PhotoPicker`, docs/design/ekran-dodawania.css). Prawdziwy
                 <input type="file"> zostaje w środku, w pełni widoczny
                 i klikalny — to wciąż ta sama droga bez JavaScriptu. --}}
            <div class="pole-zdjecia">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                <p class="pole-zdjecia-tytul">Dodaj zdjęcie</p>
                <span class="field-help" id="f-photos-help">
                    Na telefonie kliknij tutaj, a potem wybierz „Galeria” albo „Zrób zdjęcie”.
                    Największy plik: {{ \App\Support\LimityZdjec::maksMegabajtowDoKomunikatu() }} MB.
                </span>
                <input class="field-input pole-zdjecia-input" id="f-photos" type="file" name="photos[]"
                       accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                       multiple aria-describedby="f-photos-help">
            </div>
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

        <x-tagi-formularz :tag-names="$tagNames" :sugestie-tagow="$sugestieTagow" />

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
