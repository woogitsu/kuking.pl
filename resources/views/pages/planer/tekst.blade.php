{{--
    „Zmień tekst” własnej pozycji Planera (#2454, V2). Prywatny, `noindex`.

    Zwykły formularz bez skryptu: jedno pole z obecnym tekstem i dwa przyciski.
    Pozycja zostaje na swoim dniu — zmienia się tylko tekst. Ukryte pole `stan`
    niesie skrót tekstu, który widać na tej stronie: jeśli ktoś w innym oknie
    zmienił go wcześniej, akcja niczego nie zapisze, a wpisana poprawka zostaje
    w polu (`old()`).
--}}
@use('App\Domain\Planer\PlanerTygodnia')
<x-layout title="Zmień tekst pozycji" :noindex="true">
    <h1>Zmień tekst pozycji</h1>

    @php
        $celeBledow = ['label' => 'f-label', 'stan' => 'f-label'];
        $dzienPlanu = $dzien->toDateString();
    @endphp
    <x-error-summary :field-ids="$celeBledow" />

    <p class="mb-5">
        Teraz w planie na {{ PlanerTygodnia::naDzien($dzien) }}: <strong>{{ $wpis->label }}</strong><br>
        Pozycja zostaje na tym samym dniu — zmienia się tylko jej tekst.
    </p>

    <form class="panel-formularza" method="POST" action="{{ route('planer.text.update', $wpis) }}" novalidate>
        @csrf @method('PATCH')
        <input type="hidden" name="stan" value="{{ $znacznik }}">
        <x-field name="label" label="Tekst pozycji" :value="$wpis->label" :required="true" :bez-oznaczenia="true"
                 :help="'Najwyżej '.$maxZnakow.' znaków.'" />
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
            <a class="btn btn-quiet" href="{{ route('planer.show', ['tydzien' => $dzienPlanu]) }}#dzien-{{ $dzienPlanu }}">Anuluj, zostaw jak jest</a>
        </div>
    </form>
</x-layout>
