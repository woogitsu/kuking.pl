{{--
    „Dodaj kupione do »Co mam w domu«” (V2, #2481).

    Lista odhaczonych pozycji z polem NAZWY produktu przy każdej (wstępnie
    wypełnionym tekstem pozycji — do poprawienia, bo „2 szklanki mąki” to nie
    jest nazwa produktu, a my niczego nie zgadujemy). Domyślnie nic nie jest
    zaznaczone: zaznaczasz tylko to, co chcesz dopisać. Lista zakupów i jej
    odhaczenia zostają bez zmian; produkt, który już jest w „Co mam w domu”,
    zostaje nietknięty (ilości, terminy, zamrożenie).
--}}
<x-layout title="Dodaj kupione do „Co mam w domu”" :noindex="true">
    <p>
        <a class="btn btn-quiet" href="{{ route('shopping.index') }}">Wróć do listy zakupów</a>
    </p>

    <h1>Dodaj kupione do „Co mam w domu”</h1>
    <p class="meta meta-samodzielne">
        Zaznacz te odhaczone pozycje, które chcesz dopisać, i popraw nazwę produktu, jeśli trzeba — na przykład z „2 szklanki mąki” zrób „mąka”.
        Nie zapisujemy ilości ani terminów. Produkt, który już masz na liście, zostaje bez zmian. Lista zakupów też zostaje taka, jak jest.
        Teraz w „Co mam w domu” jest {{ $wSpizarni }} z {{ $maksProduktow }} możliwych produktów.
    </p>

    <form class="panel-formularza" method="POST" action="{{ route('shopping.pantry.store') }}" novalidate>
        @csrf

        @if($errors->any())
            <div class="error-summary" role="alert" tabindex="-1">
                <p class="error-summary-title">Sprawdź formularz</p>
                <ul>
                    @foreach($errors->all() as $komunikat)
                        <li>{{ $komunikat }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <fieldset class="field">
            <legend>Odhaczone pozycje</legend>
            @foreach($pozycje as $pozycja)
                @php
                    $id = (string) $pozycja->getKey();
                    $bladNazwy = $errors->first('nazwy.'.$id);
                    $zaznaczona = in_array($id, array_map('strval', (array) old('pozycje', [])), true);
                @endphp
                <div class="card mb-3" data-klucz="pozycja-{{ $id }}">
                    <label class="choice">
                        <input type="checkbox" name="pozycje[]" value="{{ $id }}" @checked($zaznaczona)>
                        <span class="choice-label">Dodaj: {{ $pozycja->text }}</span>
                    </label>
                    <div class="field mt-3 @if($bladNazwy !== '') has-error @endif">
                        <label for="nazwa-{{ $id }}">Nazwa produktu dla pozycji „{{ $pozycja->text }}”</label>
                        <input class="field-input" id="nazwa-{{ $id }}" type="text" name="nazwy[{{ $id }}]"
                               value="{{ old('nazwy.'.$id, $pozycja->text) }}" autocomplete="off"
                               @if($bladNazwy !== '') aria-invalid="true" aria-describedby="nazwa-{{ $id }}-blad" @endif>
                        @if($bladNazwy !== '')
                            <span class="field-error" id="nazwa-{{ $id }}-blad">{{ $bladNazwy }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </fieldset>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Dodaj zaznaczone do „Co mam w domu”</button>
            <a class="btn btn-secondary" href="{{ route('shopping.index') }}">Anuluj</a>
        </div>
    </form>
</x-layout>
