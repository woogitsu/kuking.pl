@php
    // Przepis mógł zostać usunięty przez autora (audyt A23). Tytuł strony jest
    // widoczny w karcie przeglądarki i w historii — nie wolno w nim przemycić
    // nazwy przepisu, którego już nie ma.
    //
    // To samo przy blokadzie z autorem przepisu (issue #1394): kucharz wchodzi
    // na własne wykonanie, ale tytuł przepisu jest treścią autora.
    $przepisZaBlokada = $event->recipe !== null
        && (auth()->user()?->hasBlockRelationWith($event->recipe->author) ?? false);
    $tytulStrony = $event->recipe && ! $przepisZaBlokada
        ? $event->user->displayName().' — ugotowane: '.$event->recipe->title
        : $event->user->displayName().' — wykonanie przepisu';
@endphp
<x-layout :title="$tytulStrony" :noindex="true">
    <x-cooked-card :event="$event" :showRecipe="true" :przepisZaBlokada="$przepisZaBlokada" />

    {{-- Wskazówki od gotujących (#2352): prośba o zgodę dla kucharza, „Poproś o zgodę" dla autora przepisu. --}}
    <x-wskazowka-przy-wykonaniu :event="$event" :wskazowka="$wskazowka" :przepisZaBlokada="$przepisZaBlokada" />
    {{-- Wersja przepisu z tego gotowania (#2378) — wyłącznie dla kucharza.
         Publiczna karta wyżej nic o niej nie mówi. --}}
    @if(($maPrzypietaWersje ?? false))
        <section class="sekcja-strony" aria-labelledby="wersja-gotowania">
            <h2 id="wersja-gotowania">Z której wersji przepisu</h2>
            @if($wersjaWykonania !== null)
                <p>Wersja przepisu otwarta przy tym wykonaniu. Widzisz ją tylko Ty.</p>
                <p><a class="btn btn-secondary" href="{{ route('cooked.version', $event) }}">Zobacz wersję {{ $wersjaWykonania->version_number }}</a></p>
            @else
                <p class="meta meta-samodzielne">Wersji przepisu z tego gotowania nie możemy już pokazać. Twoje wykonanie zostaje bez zmian.</p>
            @endif
        </section>
    @endif

    {{-- „Moje próby tego przepisu” (#2412) — prywatna historia własnych wykonań. --}}
    @if(($mojeProbyLink ?? false))
        <p><a class="btn btn-secondary" href="{{ route('cooked.proby', $event->recipe->slug) }}">Moje próby tego przepisu</a></p>
    @endif
    {{-- Korekta własnej uwagi, opisu zmian i czasu (#2459). Zawieszone konto
         czyta, ale nie poprawia (`CookedEventPolicy::update`). --}}
    @can('update', $event)
        <p class="mt-3">
            <a class="btn btn-secondary" href="{{ route('cooked.edit', $event) }}">Popraw uwagę lub czas</a>
        </p>
    @endcan

    {{-- Prywatna liczba faktycznych porcji (#2540) — wyłącznie kucharz.
         Poprawa i usunięcie bez tworzenia nowego wykonania. --}}
    @if(auth()->id() === $event->user_id)
        <section class="sekcja-strony" aria-labelledby="porcje-wykonania">
            <h2 id="porcje-wykonania">Ile porcji wyszło</h2>
            @if($event->faktyczne_porcje !== null)
                <p>{{ \App\Domain\Recipes\Gotowanie\PorcjeWykonania::etykieta($event->faktyczne_porcje) }}. Widzisz to tylko Ty.</p>
                <p><a class="btn btn-secondary" href="{{ route('cooked.porcje.edit', $event) }}">Popraw lub usuń liczbę porcji</a></p>
            @else
                <p>Nie podano. Jeśli gotowano na inną liczbę porcji niż w przepisie, możesz ją zapisać dla siebie. Widzisz to tylko Ty.</p>
                <p><a class="btn btn-secondary" href="{{ route('cooked.porcje.edit', $event) }}">Dodaj liczbę porcji</a></p>
            @endif
        </section>

        <div class="danger-zone">
            <x-confirm-button
                :action="route('cooked.destroy', $event)"
                label="Usuń to wykonanie"
                :question="'Na pewno usunąć? Zniknie także zdjęcie.'.($wskazowka?->jestPrzyjeta() ? ' Zniknie też wskazówka przy przepisie, na którą się zgodzono.' : '')" />
        </div>
    @endif

    {{-- F2 (D-333): plakietka „Autor przepisu” przy komentarzach autora przepisu, z którego gotowano. --}}
    <x-comment-thread :comments="$komentarze" :ile="$komentarzyRazem" :action="route('cooked.comment', $event)" :autor-przepisu="$event->recipe?->author_id" />
</x-layout>
