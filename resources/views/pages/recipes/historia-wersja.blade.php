<x-layout :title="'Wersja '.$wersja->version_number.': '.$recipe->title" :noindex="true">
    <p>
        <a class="btn btn-quiet" href="{{ route('recipes.history', $recipe->slug) }}">Wróć do historii zmian</a>
        <a class="btn btn-quiet" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a>
    </p>

    <h1>Wersja {{ $wersja->version_number }}</h1>
    <p class="meta meta-samodzielne">
        Zapisana {{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}@if($wersja->change_note) — {{ $wersja->change_note }}@endif.
        @if($czyNajnowsza) To najnowsza zapisana wersja. @endif
        Ekran pokazuje tekst i dane, bez zdjęć.
    </p>
    @if($wersja->czyUkryta())
        @include('pages.recipes.partials.historia-ukryta', ['wersja' => $wersja, 'recipe' => $recipe])
    @endif
    @include('pages.recipes.partials.historia-przycisk-ukrycia', ['wersja' => $wersja, 'czyNajnowsza' => $czyNajnowsza, 'uprawnienia' => $uprawnienia])

    <p class="historia-akcje">
        @if($starszy !== null)
            <a class="btn btn-secondary" href="{{ route('recipes.history.changes', [$recipe->slug, $wersja->version_number]) }}">Co się zmieniło względem wersji {{ $starszy }}</a>
        @endif
        @if($starszy !== null)
            <a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $starszy]) }}">Starsza wersja ({{ $starszy }})</a>
        @endif
        @if($nowszy !== null)
            <a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $nowszy]) }}">Nowsza wersja ({{ $nowszy }})</a>
        @endif
    </p>

    @include('pages.recipes.partials.migawka-tresc', ['migawka' => $migawka])
</x-layout>
