<x-layout title="Odczyt przepisu" :noindex="true">
    <h1>Przepis z kartki</h1>

    <x-error-summary />

    {{--
        POSTĘP SŁOWAMI, NIE KRĘCIOŁKIEM (projekt §6.2).

        `aria-live="polite"` ogłasza zmianę stanu czytnikowi ekranu. Moduł
        `resources/js/postep-importu.js` co 5 s podmienia TYLKO ten blok (`?fragment=1`) i przestaje,
        gdy stan jest końcowy. Bez skryptu działa odnośnik „Sprawdź, czy już
        gotowe” — żaden przycisk nie jest martwy (D-053). Bez `wire:poll`
        (AGENTS.md §3): to jest jedyny ekran, który odświeża się sam.
    --}}
    <div id="postep-importu" data-postep-importu aria-live="polite"
         data-adres="{{ route('import.show', ['import' => $import, 'fragment' => 1]) }}">
        @include('pages.import.partials.postep')
    </div>

    <p class="field-help mt-6">
        Nie musisz czekać. Możesz zamknąć tę stronę — szkic znajdziesz w
        <a href="{{ route('recipes.drafts') }}">Moich szkicach</a>, a zdjęcie kartki jest już przy nim zapisane.
    </p>

</x-layout>
