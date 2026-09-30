{{-- Oznaczenie wersji ukrytej (issue #2270). Rysuje się wyłącznie autorowi
     i moderacji — nikt inny wersji ukrytej w ogóle nie dostaje.

     Autor wersji ukrytej przez moderację dostaje drogę odwołania (DSA art. 20),
     nie „napisz do nas”: decyzję, od której się odwołuje, wskazuje wiersz
     `moderation_actions` tej wersji (`target_type = recipe_version`).
     Dostęp do odwołania pilnuje `AppealController` (tylko osoba, której
     dotyczy decyzja), a termin i „raz od jednej decyzji” — `FileAppeal`. --}}
@php
    $decyzjaOWersji = null;
    if ($wersja->czyUkrytaPrzezModeracje() && auth()->id() === $recipe->author_id) {
        $decyzjaOWersji = \App\Models\ModerationAction::query()
            ->where('target_type', \App\Domain\Moderation\CofniecieUkryciaWersji::TYP)
            ->where('target_id', $wersja->getKey())
            ->where('action', \App\Models\ModerationAction::ACTION_HIDE)
            ->where('subject_user_id', $recipe->author_id)
            ->latest('created_at')
            ->first();
    }
@endphp
<p class="notice">
    Ta wersja jest ukryta
    @if($wersja->czyUkrytaPrzezModeracje()) przez moderację @else przez autora @endif
    ({{ $wersja->hidden_at->locale('pl')->isoFormat('D MMMM YYYY') }}).
    Widzi ją tylko autor przepisu i moderacja.
    @if($wersja->czyUkrytaPrzezModeracje() && auth()->id() === $recipe->author_id)
        Przywrócić ją może tylko moderacja.
        @if($decyzjaOWersji === null)
            Jeśli uważasz, że to pomyłka, odwołanie złożysz z powiadomienia o decyzji moderacji — znajdziesz je w powiadomieniach.
        @else
            Jeśli uważasz, że to pomyłka, możesz odwołać się od tej decyzji.
        @endif
    @endif
</p>
@if($decyzjaOWersji !== null)
    <p>
        <a class="btn btn-secondary" href="{{ route('appeals.show', $decyzjaOWersji) }}">
            {{ $decyzjaOWersji->authorAppeal !== null ? 'Zobacz swoje odwołanie' : ($decyzjaOWersji->isAppealable() ? 'Odwołaj się' : 'Zobacz decyzję') }}
        </a>
    </p>
@endif
