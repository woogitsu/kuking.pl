{{--
    „Popraw” pozycję listy zakupów (#2443, V2). Prywatny, `noindex`.

    Zwykły formularz bez skryptu: jedno pole z obecnym tekstem i dwa przyciski.
    Pozycja zostaje na swoim miejscu, z tym samym odhaczeniem i pochodzeniem —
    zmienia się tylko jej tekst. Przepis źródłowy nie jest ruszany. Ukryte pole
    `stan` niesie skrót tekstu, który widać na tej stronie: jeśli ktoś w innym
    oknie zmienił go wcześniej, akcja niczego nie zapisze, a wpisana poprawka
    zostaje w polu (`old()`).
--}}
<x-layout title="Popraw pozycję listy zakupów" :noindex="true">
    <h1>Popraw pozycję listy</h1>

    @php
        $celeBledow = ['text' => 'f-text', 'stan' => 'f-text'];
    @endphp
    <x-error-summary :field-ids="$celeBledow" />

    <p class="mb-5">
        Teraz na liście: <strong>{{ $pozycja->text }}</strong><br>
        Pozycja zostaje na swoim miejscu, a odhaczenie się nie zmienia.
        @if($pozycja->source === \App\Models\ShoppingListItem::SOURCE_RECIPE)
            Poprawiasz tekst tylko na swojej liście — przepis zostaje taki, jak napisał autor, a pozycja dostanie dopisek, że została poprawiona.
        @endif
    </p>

    <form class="panel-formularza" method="POST" action="{{ route('shopping.update', $pozycja) }}" novalidate>
        @csrf @method('PATCH')
        <input type="hidden" name="stan" value="{{ $znacznik }}">
        <x-field name="text" label="Tekst pozycji" :value="$pozycja->text" :required="true" :bez-oznaczenia="true" autocomplete="off"
                 :help="'Na przykład „2 mleka”. Najwyżej '.$maksZnakow.' znaków.'" />
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
            <a class="btn btn-quiet" href="{{ route('shopping.index') }}#pozycja-{{ $pozycja->getKey() }}">Anuluj, zostaw jak jest</a>
        </div>
    </form>
</x-layout>
