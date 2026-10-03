{{--
    Dołączenie zdjęcia do ZAPISANEGO „Ugotowałem” (#2500, V2, D-333 — paczka E).

    To nie jest drugie gotowanie: formularz mówi to wprost. Zdjęcie dokłada
    się do istniejącego wykonania; data, wersja przepisu, notatka i rozmowa
    zostają, a publiczna karta dostaje znacznik „Zdjęcie uzupełnione”. Pole
    zdjęcia to ten sam obszar `.pole-zdjecia` co przy zapisie wykonania.
    Poprawnie przyjęte zdjęcia wracają po błędzie w ukrytych polach.
--}}
<x-layout title="Dołącz zdjęcie do wykonania" :noindex="true">
    <p>
        <a class="btn btn-quiet" href="{{ route('cooked.show', $event) }}">Wróć do wykonania</a>
    </p>

    <h1>Dołącz zdjęcie do tego wykonania</h1>
    <p>
        To wykonanie już jest zapisane
        (<time datetime="{{ $event->cooked_at->toIso8601String() }}">{{ \App\Support\Czas::data($event->cooked_at, 'j F Y') }}</time>).
        Dołożenie zdjęcia <strong>nie zgłasza drugiego gotowania</strong>: data, wersja przepisu, notatka i rozmowa zostają bez zmian,
        a autor przepisu nie dostaje nowego powiadomienia. Przy wykonaniu pojawi się dopisek „Zdjęcie uzupełnione”.
    </p>
    <p class="meta">Zdjęcie można dołożyć przez {{ $dni }} dni od zapisania wykonania. Dotychczasowych zdjęć ta strona nie zmienia ani nie usuwa.</p>

    <x-error-summary :field-ids="['photos' => 'f-photos', 'photos.*' => 'f-photos', 'media_ids' => 'f-photos', 'media_ids.*' => 'f-photos']" />

    @if($event->media->isNotEmpty())
        <section class="sekcja-strony" aria-labelledby="dotychczasowe-zdjecia">
            <h2 id="dotychczasowe-zdjecia">Dotychczasowe zdjęcia ({{ $event->media->count() }})</h2>
            <ul class="stack-tight lista-naga">
                @foreach($event->media as $zdjecie)
                    <li><x-photo :media="$zdjecie" variant="thumb" :zoom="false" /></li>
                @endforeach
            </ul>
        </section>
    @endif

    <form class="panel-formularza" method="POST" action="{{ route('cooked.photos.store', $event) }}" enctype="multipart/form-data" novalidate>
        @csrf

        <div class="field @error('photos') has-error @enderror @error('photos.*') has-error @enderror">
            <span class="pole-zdjecia-nazwa" id="f-photos-etykieta">Zdjęcie do dołączenia</span>

            @if(($zachowane ?? collect())->isNotEmpty())
                <div class="notice">
                    <strong>Twoje zdjęcia są zachowane.</strong>
                    Nie musisz wybierać ich jeszcze raz — popraw tylko to, co wypisaliśmy na górze formularza.
                    <ul class="stack-tight lista-naga mt-3">
                        @foreach($zachowane as $zdjecie)
                            <li>
                                <input type="hidden" name="media_ids[]" value="{{ $zdjecie->getKey() }}">
                                <x-photo :media="$zdjecie" variant="thumb" :zoom="false" />
                                <button class="btn btn-quiet" type="submit" name="usun_zdjecie" value="{{ $zdjecie->getKey() }}" formnovalidate>Usuń to zdjęcie z wyboru</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php
                $opisZdjec = implode(' ', array_keys(array_filter([
                    'f-photos-help' => true,
                    'f-photos-error' => $errors->has('photos'),
                    'f-photos-plik-error' => $errors->has('photos.*'),
                    'f-media-ids-error' => $errors->has('media_ids.*'),
                ])));
                $bladZdjec = $errors->has('photos') || $errors->has('photos.*') || $errors->has('media_ids.*');
            @endphp
            <input class="visually-hidden pole-zdjecia-input" id="f-photos" type="file" name="photos[]"
                   accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                   multiple
                   data-usuwanie-zdjec
                   aria-labelledby="f-photos-etykieta f-photos-tytul"
                   aria-describedby="{{ $opisZdjec }}" @if($bladZdjec) aria-invalid="true" @endif>
            <label class="pole-zdjecia" for="f-photos">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-photos-tytul">Wybierz zdjęcie</span>
                <span class="field-help" id="f-photos-help">
                    {{ \App\Support\LimityZdjec::pomocLiczbyZdjec(($zachowane ?? collect())->count() + $event->media->count()) }}
                    Największy plik: {{ \App\Support\LimityZdjec::maksMegabajtowDoKomunikatu() }} MB.
                </span>
            </label>
            @error('photos')<span class="field-error" id="f-photos-error">{{ $message }}</span>@enderror
            @error('photos.*')<span class="field-error" id="f-photos-plik-error">{{ $message }}</span>@enderror
            @error('media_ids.*')<span class="field-error" id="f-media-ids-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Dołącz zdjęcie</button>
            <a class="btn btn-secondary" href="{{ route('cooked.show', $event) }}">Nie dołączaj</a>
        </div>
    </form>
</x-layout>
