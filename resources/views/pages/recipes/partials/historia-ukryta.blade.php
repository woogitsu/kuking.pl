{{-- Oznaczenie wersji ukrytej (issue #2270). Rysuje się wyłącznie autorowi
     i moderacji — nikt inny wersji ukrytej w ogóle nie dostaje. --}}
<p class="notice">
    Ta wersja jest ukryta
    @if($wersja->czyUkrytaPrzezModeracje()) przez moderację @else przez autora @endif
    ({{ $wersja->hidden_at->locale('pl')->isoFormat('D MMMM YYYY') }}).
    Widzi ją tylko autor przepisu i moderacja.
    @if($wersja->czyUkrytaPrzezModeracje() && auth()->id() === $recipe->author_id)
        Przywrócić ją może tylko moderacja. Jeśli uważasz, że to pomyłka, <a href="{{ route('kontakt') }}">napisz do nas</a>.
    @endif
</p>
