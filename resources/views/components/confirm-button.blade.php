{{--
    Akcja destrukcyjna.

    Wymaga potwierdzenia i jest wizualnie odsunięta od zwykłych akcji.
    Działa bez JavaScriptu (formularz + osobna strona potwierdzenia nie jest
    tu potrzebna, bo `onsubmit` to tylko dodatkowa warstwa — brak JS oznacza
    po prostu, że formularz wysyła się od razu, dlatego przycisk ma jasny
    napis, a nie samą ikonę kosza).
--}}
@props(['action', 'method' => 'DELETE', 'label', 'question'])
<form method="POST" action="{{ $action }}" style="display:inline"
      onsubmit="return confirm(@js($question))">
    @csrf
    @method($method)
    <button class="btn btn-danger" type="submit">{{ $label }}</button>
</form>
