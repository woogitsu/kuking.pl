{{--
    POTWIERDZENIE PRZED WYJĘCIEM PRZEPISU ZE WSZYSTKICH ZESZYTÓW (issue #775).

    Tę stronę oddaje samo `DELETE collections.unsave`, gdy przepis leży
    w więcej niż jednym zeszycie tej osoby, a formularz nie wskazał, z którego
    wyjmujemy. Bez JavaScriptu, bez okienka „czy na pewno" — zwykła strona
    z trzema drogami: wyjąć z jednego zeszytu, wyjąć ze wszystkich, nie
    ruszać niczego. Akcja „ze wszystkich" jest ostatnia i odsunięta od reszty
    (AGENTS.md §5: akcja destrukcyjna wymaga potwierdzenia i stoi osobno).
--}}
@php
    $ile = $zeszyty->count();
    $co = $widoczny ? '„'.$recipe->title.'”' : 'ten przepis';
@endphp
<x-layout title="Usunąć przepis ze wszystkich zeszytów?" :noindex="true">
    <div class="marka-zeszyt stack kolumna-czytania">
        <h1>Usunąć przepis ze wszystkich {{ $ile }} zeszytów?</h1>

        <p class="notice" id="zakres-wyjecia-wszystkie">
            Masz {{ $co }} w {{ $ile }} zeszytach. Ten przycisk zdejmie go <strong>ze wszystkich</strong>@if($notatek === 1) — razem z Twoją notatką, zapisaną w jednym z nich.@elseif($notatek > 1) — razem z Twoimi notatkami, zapisanymi w {{ $notatek }} z nich.@else. Notatek przy nim w tych zeszytach nie masz.@endif
            Sam przepis zostaje w serwisie. Zaraz po usunięciu pokażemy przycisk „Przywróć do zeszytu”.
        </p>

        <section class="stack" aria-labelledby="tylko-z-jednego">
            <h2 id="tylko-z-jednego" class="m-0">Wolisz usunąć go tylko z jednego zeszytu?</h2>
            <ul class="stack list-none p-0 m-0">
                @foreach($zeszyty as $zeszyt)
                    <li>
                        <form method="POST" action="{{ route('collections.unsave', $recipe->slug) }}">
                            @csrf @method('DELETE')
                            <input type="hidden" name="collection_id" value="{{ $zeszyt->getKey() }}">
                            <button class="btn btn-secondary" type="submit">Usuń tylko z zeszytu „{{ $zeszyt->name }}”</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>

        <p><a class="btn btn-secondary" href="{{ $powrot }}">Nie usuwaj — wróć</a></p>

        <div class="danger-zone stack">
            <form method="POST" action="{{ route('collections.unsave', $recipe->slug) }}">
                @csrf @method('DELETE')
                <input type="hidden" name="potwierdzam_wszystkie" value="1">
                <button class="btn btn-danger" type="submit" aria-describedby="zakres-wyjecia-wszystkie">Tak, usuń ze wszystkich {{ $ile }} zeszytów</button>
            </form>
        </div>
    </div>
</x-layout>
