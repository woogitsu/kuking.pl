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

    @if(auth()->id() === $event->user_id)
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
