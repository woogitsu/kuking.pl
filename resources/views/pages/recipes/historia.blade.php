<x-layout :title="'Historia zmian: '.$recipe->title" :noindex="true">
    <p><a class="btn btn-quiet" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a></p>

    <h1>Historia zmian</h1>
    <p class="text-lead">{{ $recipe->title }}</p>
    <p class="meta">
        Tu są zapisane wersje przepisu z datami. Historia pokazuje tekst i dane przepisu,
        bez zdjęć. Strona przepisu może zawierać jeszcze drobne poprawki, których autor
        nie zapisał jako nowej wersji.
    </p>

    <ol class="historia-lista list-none p-0" aria-label="Wersje przepisu">
        @foreach($wersje as $wersja)
            <li class="historia-wersja card">
                <h2 class="historia-wersja-naglowek">
                    Wersja {{ $wersja->version_number }}
                    @if($wersja->version_number === $najnowsza)
                        <span class="badge badge-spokojny">Najnowsza zapisana</span>
                    @endif
                </h2>
                <p class="meta">
                    Zapisana {{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}@if($wersja->change_note) — {{ $wersja->change_note }}@endif
                </p>
                <p class="historia-akcje">
                    <a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $wersja->version_number]) }}">Zobacz wersję {{ $wersja->version_number }}</a>
                    @if($wersja->version_number > 1)
                        <a class="btn btn-secondary" href="{{ route('recipes.history.changes', [$recipe->slug, $wersja->version_number]) }}">Co się zmieniło względem wersji {{ $wersja->version_number - 1 }}</a>
                    @endif
                </p>
            </li>
        @endforeach
    </ol>

    @if($wersje->hasMorePages())
        <p><a class="btn btn-secondary" href="{{ $wersje->nextPageUrl() }}">Pokaż starsze wersje</a></p>
    @endif
</x-layout>
