{{--
    POTWIERDZENIE „PRZYWRÓCIĆ WERSJĘ N?” (issue #2270). Przywrócenie znowu
    pokazuje publicznie tekst, który ktoś świadomie schował — więc też idzie
    przez osobny ekran z formularzem, bez JavaScriptu.
--}}
<x-layout :title="'Przywrócić wersję '.$wersja->version_number.'?'" :noindex="true">
    <div class="stack kolumna-czytania">
        <p><a class="btn btn-quiet" href="{{ route('recipes.history', $recipe->slug) }}">Wróć do historii zmian</a></p>

        <h1>Przywrócić wersję {{ $wersja->version_number }}?</h1>
        <p class="text-lead">{{ $recipe->title }}</p>

        <p class="notice" id="skutek-przywrocenia">
            Wersja {{ $wersja->version_number }} z {{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}
            wróci do historii zmian i zobaczy ją każdy, kto widzi przepis — razem z całym tekstem,
            jaki miała w chwili zapisu. Sprawdź ją, zanim przywrócisz.
        </p>

        <p><a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $wersja->version_number]) }}">Zobacz wersję {{ $wersja->version_number }}</a></p>
        <p><a class="btn btn-secondary" href="{{ route('recipes.history', $recipe->slug) }}">Zostaw ukrytą — wróć</a></p>

        <div class="danger-zone stack">
            <form method="POST" action="{{ route('recipes.history.restore.store', [$recipe->slug, $wersja->version_number]) }}">
                @csrf
                <button class="btn btn-primary" type="submit" aria-describedby="skutek-przywrocenia">Tak, przywróć wersję {{ $wersja->version_number }}</button>
            </form>
        </div>
    </div>
</x-layout>
