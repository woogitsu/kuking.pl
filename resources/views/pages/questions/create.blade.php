<x-layout title="Zadaj pytanie" :noindex="true">
    <p><a href="{{ route('questions.index') }}">Wróć do Poradźcie</a></p>
    <h1>Zadaj pytanie</h1>
    <p>Ktoś to już robił i chętnie powie, jak. Pytanie będzie widoczne dla wszystkich.</p>
    {{-- Błąd pojedynczego pliku ma klucz `photos.0`, a pole plików jest jedno:
         `f-photos` (issue #874). --}}
    <x-error-summary :field-ids="['photos.*' => 'f-photos', 'media_ids' => 'f-photos', 'media_ids.*' => 'f-photos']" />
    <form class="panel-formularza" method="POST" action="{{ route('questions.store') }}" enctype="multipart/form-data">
        @csrf
        @if($kluczWyslania !== null)
            <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">
        @endif
        <x-field name="title" label="O co chcesz zapytać?" help="Od 10 do 180 znaków. Na przykład: Jak uratować przesoloną zupę?" required />
        <div class="field" data-tagi-opis data-tagi-endpoint="{{ route('tags.suggestions') }}"
             data-tagi-min="{{ \App\Support\LimityTagow::minZnakow() }}"
             data-tagi-max="{{ config('kuking.tags.suggestions_query_max_length') }}">
            <x-field name="body" label="Napisz trochę więcej" type="textarea" :rows="5" help="Możesz dopisać, co już udało Ci się spróbować. Najwyżej 4000 znaków." />
            <x-tagi-formularz :tag-names="$tagNames" :sugestie-tagow="$sugestieTagow" :maks-tagow="3" :pytanie="true" />
        </div>
        @php
            $zachowane = \App\Domain\Media\ZachowaneZdjecia::wKolejnosci(old('media_ids', []), auth()->id());
        @endphp
        {{-- Gdy zdjęcie jest zachowane, pola pliku nie ma — celem linku
             z podsumowania błędów jest wtedy to zdjęcie (issue #874). --}}
        @foreach($zachowane as $zdjecie)
            <div class="notice" @if($loop->first) id="f-photos" tabindex="-1" @endif>
                <p>Zdjęcie jest zachowane. Nie musisz wybierać go ponownie.</p>
                <input type="hidden" name="media_ids[]" value="{{ $zdjecie->id }}">
                <x-photo :media="$zdjecie" variant="thumb" :zoom="false" />
                <button class="btn btn-quiet" type="submit" name="usun_zdjecie" value="{{ $zdjecie->id }}" formnovalidate>Usuń zdjęcie z pytania</button>
            </div>
        @endforeach
        @if($zachowane->isEmpty())
            <div class="field">
                <input class="visually-hidden pole-zdjecia-input" id="f-photos" type="file" name="photos[]"
                       accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}">
                <label class="pole-zdjecia" for="f-photos">
                    <span class="pole-zdjecia-tytul">Dodaj zdjęcie, jeśli pomoże</span>
                    <span class="field-help">Jedno zdjęcie, do {{ \App\Support\LimityZdjec::maksMegabajtowDoKomunikatu() }} MB.</span>
                </label>
            </div>
        @endif
        @error('photos')<p class="field-error">{{ $message }}</p>@enderror
        @error('photos.*')<p class="field-error">{{ $message }}</p>@enderror
        @error('media_ids.*')<p class="field-error">{{ $message }}</p>@enderror

        <button class="btn btn-primary" type="submit">Opublikuj pytanie</button>
    </form>
</x-layout>
