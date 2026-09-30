{{-- „Ukryj wersję N” / „Przywróć wersję N” (issue #2270). To są zwykłe
     linki do ekranu potwierdzenia (GET) — sama zmiana idzie dopiero z tamtego
     formularza, więc działa bez JavaScriptu i nie da się jej zrobić jednym
     przypadkowym kliknięciem. `$uprawnienia` liczy kontroler raz na stronę
     tą samą `RecipeVersionPolicy`, która pilnuje zapisu (bez zapytań na
     każdą wersję listy). Najnowszej wersji nie ukrywa się wcale. --}}
@if($wersja->czyUkryta())
    @if($uprawnienia[$wersja->hidden_by_role] ?? false)
        <p class="historia-akcje">
            <a class="btn btn-secondary" href="{{ route('recipes.history.restore', [$recipe->slug, $wersja->version_number]) }}">Przywróć wersję {{ $wersja->version_number }}</a>
        </p>
    @endif
@elseif(! $czyNajnowsza && $uprawnienia['ukryj'])
    <p class="historia-akcje">
        <a class="btn btn-secondary" href="{{ route('recipes.history.hide', [$recipe->slug, $wersja->version_number]) }}">Ukryj wersję {{ $wersja->version_number }}</a>
    </p>
@endif
