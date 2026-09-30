<x-layout :title="'Historia zmian: '.$recipe->title" :noindex="true">
    <p><a class="btn btn-quiet" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a></p>

    <h1>Historia zmian</h1>
    <p class="text-lead">{{ $recipe->title }}</p>
    <p class="meta meta-samodzielne">
        Tu są zapisane wersje przepisu z datami. Historia pokazuje tekst i dane przepisu,
        bez zdjęć. Strona przepisu może zawierać jeszcze drobne poprawki, których autor
        nie zapisał jako nowej wersji.
    </p>
    <p class="meta">
        Wersje są publiczne tak samo jak przepis: widzi je każdy, kto widzi przepis. Zapisana
        wersja zachowuje treść z chwili zapisu, także tę, którą autor usunął później.
        Autor może ukryć pojedynczą wersję przyciskiem „Ukryj wersję” z numerem wersji (na przykład „Ukryj wersję 2”) — wtedy widzi ją już
        tylko autor i moderacja.
    </p>
    @if($zUkrytymi)
        <p class="notice">
            @if(auth()->id() === $recipe->author_id)
                Wersje z napisem „Ukryta” widzisz tylko Ty i moderacja
            @else
                Wersje z napisem „Ukryta” widzi tylko autor przepisu i moderacja
            @endif
            — dla innych ich nie ma, a porównanie zmian je pomija.
            Najnowszej wersji nie da się ukryć, bo to treść przepisu, którą widać na jego stronie:
            żeby usunąć z niej tekst, popraw przepis i zapisz zmiany.
        </p>
    @endif
    @php($miesiace = (int) config('kuking.przepisy.version_retention_months'))
    <p class="meta">
        Starsze wersje nie leżą tu w nieskończoność: wersja zapisana ponad {{ $miesiace }} {{ \App\Support\Odmiana::rzeczownik($miesiace, 'miesiąc', 'miesiące', 'miesięcy') }} temu
        znika z serwisu, ale {{ (int) config('kuking.przepisy.version_keep_latest') }} najnowsze wersje przepisu zostają zawsze.
    </p>

    <ol class="historia-lista list-none p-0" aria-label="Wersje przepisu">
        @foreach($wersje as $wersja)
            <li class="historia-wersja card">
                <h2 class="historia-wersja-naglowek">
                    Wersja {{ $wersja->version_number }}
                    @if($wersja->version_number === $najnowsza)
                        <span class="badge badge-spokojny">Najnowsza zapisana</span>
                    @endif
                    @if($wersja->czyUkryta())
                        <span class="badge badge-cichy">{{ $wersja->czyUkrytaPrzezModeracje() ? 'Ukryta przez moderację' : 'Ukryta' }}</span>
                    @endif
                </h2>
                <p class="meta">
                    Zapisana {{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}@if($wersja->change_note) — {{ $wersja->change_note }}@endif
                </p>
                @if($wersja->czyUkryta())
                    @include('pages.recipes.partials.historia-ukryta', ['wersja' => $wersja, 'recipe' => $recipe])
                @endif
                <p class="historia-akcje">
                    <a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $wersja->version_number]) }}">Zobacz wersję {{ $wersja->version_number }}</a>
                    @if(($poprzednicy[$wersja->version_number] ?? null) !== null)
                        <a class="btn btn-secondary" href="{{ route('recipes.history.changes', [$recipe->slug, $wersja->version_number]) }}">Co się zmieniło względem wersji {{ $poprzednicy[$wersja->version_number] }}</a>
                    @endif
                </p>
                @include('pages.recipes.partials.historia-przycisk-ukrycia', ['wersja' => $wersja, 'czyNajnowsza' => $wersja->version_number === $najnowsza, 'uprawnienia' => $uprawnienia])
            </li>
        @endforeach
    </ol>

    @if($wersje->hasMorePages())
        <p><a class="btn btn-secondary" href="{{ $wersje->nextPageUrl() }}">Pokaż starsze wersje</a></p>
    @endif
</x-layout>
