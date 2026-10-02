{{--
    „Zmień nazwę” produktu z listy „Co mam w domu” (#2448, V2). Prywatny, `noindex`.

    Zwykły formularz bez skryptu: jedno pole z obecną nazwą i dwa przyciski.
    Zmienia się tylko nazwa — ilość, termin i „mrożone” zostają, bo ten
    formularz ich w ogóle nie niesie. Ukryte pole `stara_nazwa` to nazwa widoczna
    na tej stronie: jeśli ktoś w innym oknie zmienił ją wcześniej, akcja niczego
    nie zapisze, a wpisana poprawka zostaje w polu (`old()`).
--}}
<x-layout :title="'Zmień nazwę: '.$produkt->name" :noindex="true">
    <h1>Zmień nazwę produktu</h1>

    <x-error-summary :field-ids="['nazwa' => 'f-nazwa', 'stara_nazwa' => 'f-nazwa']" />

    <p class="mb-5">
        Teraz na liście: <strong>{{ $produkt->name }}</strong><br>
        Zmieniamy tylko nazwę. Ilość, termin i oznaczenie „mrożone” zostają takie, jakie były.
        Tę informację widzisz tylko Ty.
    </p>

    <form class="panel-formularza" method="POST" action="{{ route('pantry.name.update', $produkt) }}" novalidate>
        @csrf
        @method('PUT')
        <input type="hidden" name="stara_nazwa" value="{{ $produkt->name }}">
        <x-field name="nazwa" label="Nazwa produktu" :value="$produkt->name" :required="true" :bez-oznaczenia="true" autocomplete="off"
                 :help="'Na przykład „mleko kokosowe”. Najwyżej '.$maksZnakow.' znaków.'" />
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz nazwę</button>
            <a class="btn btn-quiet" href="{{ route('pantry.index') }}">Anuluj, zostaw jak jest</a>
        </div>
    </form>
</x-layout>
