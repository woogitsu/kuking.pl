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

    @if(auth()->id() === $event->user_id)
        <div class="danger-zone">
            <x-confirm-button
                :action="route('cooked.destroy', $event)"
                label="Usuń to wykonanie"
                question="Na pewno usunąć? Zniknie także zdjęcie." />
        </div>
    @endif

    <x-comment-thread :comments="$event->comments" :action="route('cooked.comment', $event)" />
</x-layout>
