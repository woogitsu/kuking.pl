{{-- Prywatny powrót kucharza do wersji przepisu z jego gotowania (#2378).
     Tytuł strony NIE zawiera nazwy przepisu, dopóki wersja nie przeszła
     bramki (`WersjaWykonania`): bez dostępu nie ujawniamy ani tytułu, ani treści. --}}
@php
    // Nazwa Z MIGAWKI, nie z dzisiejszego przepisu: po zmianie tytułu ekran
    // ma pokazywać to, z czego gotowano (#2378), a nie zmyślać stan historyczny.
    $nazwaZMigawki = $migawka?->pole('title') ?? 'przepis';
@endphp
<x-layout :title="$wersja === null ? 'Wersja przepisu z tego gotowania' : 'Wersja '.$wersja->version_number.': '.$nazwaZMigawki" :noindex="true">
    <p>
        <a class="btn btn-quiet" href="{{ route('cooked.show', $event) }}">Wróć do wykonania</a>
    </p>

    @if($wersja === null)
        <h1>Wersja przepisu z tego gotowania</h1>
        <p class="notice">
            Tej wersji przepisu nie możemy Ci już pokazać. Mogła zostać usunięta przy porządkowaniu starych wersji
            albo przepis przestał być dla Ciebie dostępny. Twoje wykonanie, notatka i zdjęcia zostają bez zmian.
        </p>
    @else
        <h1>Wersja {{ $wersja->version_number }}: {{ $nazwaZMigawki }}</h1>
        <p class="meta meta-samodzielne">
            To wersja przepisu, która była otwarta przy zapisie tego wykonania. Widzisz ją tylko Ty.
            Zapisana {{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}@if($wersja->change_note) — {{ $wersja->change_note }}@endif.
            Ekran pokazuje tekst i dane, bez zdjęć.
        </p>
        <p>
            <a class="btn btn-secondary" href="{{ route('recipes.show', $recipe->slug) }}">Zobacz dzisiejszy przepis</a>
        </p>

        @include('pages.recipes.partials.migawka-tresc', ['migawka' => $migawka])
    @endif
</x-layout>
