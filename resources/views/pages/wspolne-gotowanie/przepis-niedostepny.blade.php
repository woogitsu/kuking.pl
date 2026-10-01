<x-layout title="Przepis niedostępny" :noindex="true">
    {{-- Członek sesji, który przestał widzieć przepis (autor zmienił jego
         widoczność, zablokował kogoś, konto autora zamknięto): ani słowa
         z przepisu, nawet tytułu (#2385, projekt, sekcja 4). --}}
    <div class="stack max-w-[38rem] mx-auto">
        <h1>Ten przepis nie jest już dla Ciebie dostępny</h1>
        <p>Nie możesz teraz zobaczyć przepisu z tej sesji, więc nie pokazujemy też jego postępu.
            @if($jestGospodarzem)
                Jeśli to pomyłka, sprawdź widoczność przepisu. Możesz też zakończyć sesję.
            @else
                Możesz wyjść z sesji albo poprosić gospodarza o zmianę widoczności przepisu.
            @endif
        </p>
        <a class="btn btn-primary" href="{{ route('home') }}">Wróć na Start</a>
    </div>
</x-layout>
