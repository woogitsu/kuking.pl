@props(['elementy'])

{{--
    Widoczne okruszki (#1033). Tę samą listę dostaje `Okruszki::jsonLd()`,
    więc człowiek i wyszukiwarka widzą jedną ścieżkę. Ostatni element to
    bieżąca strona — nie powtarzamy go, bo stoi tuż niżej jako treść.
    Rozmiar pisma i cel dotknięcia: reguły `.okruchy` w app.css.
--}}
@if(count($elementy) > 1)
    <nav aria-label="Gdzie jesteś">
        <ol class="okruchy">
            @foreach(array_slice($elementy, 0, -1) as $element)
                <li><a href="{{ $element['url'] }}">{{ $element['nazwa'] }}</a></li>
            @endforeach
        </ol>
    </nav>
@endif
