{{--
    POTWIERDZENIE „UKRYĆ WERSJĘ N?” (issue #2270). Zwykła strona z formularzem,
    bez JavaScriptu i bez okienka „czy na pewno”. Przycisk ukrycia stoi osobno,
    w strefie akcji zmieniających widok innych (AGENTS.md §5).
--}}
<x-layout :title="'Ukryć wersję '.$wersja->version_number.'?'" :noindex="true">
    <div class="stack kolumna-czytania">
        <p><a class="btn btn-quiet" href="{{ route('recipes.history', $recipe->slug) }}">Wróć do historii zmian</a></p>

        <h1>Ukryć wersję {{ $wersja->version_number }}?</h1>
        <p class="text-lead">{{ $recipe->title }}</p>

        <div class="notice stack" id="skutek-ukrycia">
            <p class="m-0">
                Wersja {{ $wersja->version_number }} z {{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}
                zniknie z historii zmian dla wszystkich poza autorem przepisu i moderacją.
                Porównanie zmian ją pominie i powie, że coś pominęło.
            </p>
            <p class="m-0">Sam przepis i pozostałe wersje się nie zmienią.</p>
            @if($strona === \App\Models\RecipeVersion::UKRYLA_MODERACJA)
                <p class="m-0">
                    Ukrywasz ją jako moderacja: autor zobaczy ją z napisem „Ukryta przez moderację”
                    i nie przywróci jej sam. Przywrócić ją może tylko moderacja.
                </p>
            @else
                <p class="m-0">W każdej chwili możesz ją przywrócić z historii zmian.</p>
            @endif
        </div>

        <p><a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $wersja->version_number]) }}">Najpierw zobacz wersję {{ $wersja->version_number }}</a></p>
        <p><a class="btn btn-secondary" href="{{ route('recipes.history', $recipe->slug) }}">Nie ukrywaj — wróć</a></p>

        <div class="danger-zone stack">
            <form method="POST" action="{{ route('recipes.history.hide.store', [$recipe->slug, $wersja->version_number]) }}">
                @csrf
                <button class="btn btn-danger" type="submit" aria-describedby="skutek-ukrycia">Tak, ukryj wersję {{ $wersja->version_number }}</button>
            </form>
        </div>
    </div>
</x-layout>
