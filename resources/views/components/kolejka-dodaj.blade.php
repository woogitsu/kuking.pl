{{--
    „Dodaj do kolejki gotowania” (#2379, D-333).

    Kolejka żyje w przeglądarce, więc bez JavaScriptu nie ma co jej dopisać:
    blok ma `hidden` i odkrywa go dopiero skrypt (`kolejka-gotowania.js`) —
    żadnego martwego przycisku (D-053). Bez skryptu zostaje „Gotuję”
    w trybie jednego przepisu, jak dotąd.
--}}
@props(['recipe'])
<div class="stack" data-kolejka-dodaj
     data-slug="{{ $recipe->slug }}" data-tytul="{{ $recipe->title }}"
     data-adres="{{ route('kolejka-gotowania') }}" hidden>
    <button type="button" class="btn btn-secondary" data-kolejka-dodaj-przycisk>Dodaj do kolejki gotowania</button>
    <p class="m-0" role="status" data-kolejka-dodaj-komunikat></p>
    <a class="btn btn-secondary" href="{{ route('kolejka-gotowania') }}" data-kolejka-dodaj-link hidden>Otwórz kolejkę gotowania</a>
</div>
