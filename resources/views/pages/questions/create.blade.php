<x-layout title="Zadaj pytanie" :noindex="true">
    <p><a href="{{ route('questions.index') }}">Wróć do Poradźcie</a></p>
    <h1>Zadaj pytanie</h1>
    <p>Ktoś to już robił i chętnie powie, jak. Pytanie będzie widoczne dla wszystkich.</p>
    <x-error-summary />
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
            <p class="field-help">Wpisz # i nazwę, na przykład #zupa. Tagi możesz też znaleźć poniżej.</p>
        </div>
        @php
            $zachowane = \App\Models\Media::query()->whereIn('id', (array) old('media_ids', []))
                ->where('owner_id', auth()->id())->whereDoesntHave('posts')->get();
        @endphp
        @foreach($zachowane as $zdjecie)
            <div class="notice">
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
        <x-tagi-formularz :tag-names="$tagNames" :sugestie-tagow="$sugestieTagow" :maks-tagow="3" :pytanie="true" />
        <button class="btn btn-primary" type="submit">Opublikuj pytanie</button>
    </form>
</x-layout>
