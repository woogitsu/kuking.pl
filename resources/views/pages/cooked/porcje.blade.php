@php
    // Tytuł strony trafia do karty przeglądarki i historii — nie przemycamy
    // do niego nazwy przepisu (może być cudza i za blokadą).
    $aktualne = $event->faktyczne_porcje !== null
        ? \App\Domain\Recipes\Gotowanie\PorcjeWykonania::doPola($event->faktyczne_porcje)
        : null;
@endphp
<x-layout title="Ile porcji wyszło" :noindex="true">
    <h1>Ile porcji wyszło</h1>
    <p class="mb-5">
        Ta liczba jest tylko dla Ciebie: nie zmienia przepisu, nie trafia do powiadomienia dla autora przepisu ani na publiczną kartę wykonania.
        Zostaw pole puste i zapisz, jeśli chcesz ją usunąć.
    </p>

    <x-error-summary />

    {{-- Prywatna liczba faktycznych porcji (#2540). Poprawa przy istniejącym
         wykonaniu: nie tworzy nowego „Ugotowałem", nie rusza daty ani
         powiadomienia. `novalidate` — błąd pokazuje serwer, przy polu
         i w podsumowaniu, a wpisana wartość zostaje w polu. --}}
    <form class="panel-formularza" method="POST" action="{{ route('cooked.porcje.update', $event) }}" novalidate>
        @csrf
        @method('PUT')

        <x-field name="faktyczne_porcje" label="Ile porcji wyszło (tylko dla Ciebie)" type="text"
                 inputmode="decimal" :value="$aktualne"
                 help="Na przykład 8 albo 2,5, od 0,5 do 100. Pole puste oznacza „nie podano”." />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
            <a class="btn btn-quiet" href="{{ route('cooked.show', $event) }}">Wróć do wykonania</a>
        </div>
    </form>
</x-layout>
