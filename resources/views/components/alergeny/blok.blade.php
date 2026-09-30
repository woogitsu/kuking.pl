{{-- Alergeny według autora na stronie przepisu (#1902, D-333) — STAŁY blok pod
     składnikami, nie dymek i nie plakietka. Pokazywany tylko przy włączonej fladze
     (sprawdza wywołujący) i ZAWSZE w jednym z trzech wariantów:

       1. `declared` z listą   — „Alergeny według autora: …”
       2. `declared` bez listy — „Autor nie zaznaczył żadnego z 14 alergenów.”
       3. wszystko inne        — „Alergeny: nie sprawdzono.”

     Cisza nigdy nie znaczy „w porządku”: przepis bez oznaczenia mówi wprost, że nie wiemy.
     Stan `needs_review` czytelnik widzi jak „nie sprawdzono” — listy nie pokazujemy.
     Autorowi dochodzi jedno zdanie i odnośnik do formularza z sekcją alergenów. --}}
@props(['recipe'])
@php
    $zdeklarowane = $recipe->alergenyZdeklarowane();
    $lista = $zdeklarowane ? \App\Domain\Recipes\Alergeny\Alergen::nazwyZKodow($recipe->allergens) : '';
    $wlasciciel = auth()->id() === $recipe->author_id && auth()->user()?->can('update', $recipe);
    $doPrzegladu = $recipe->allergen_status === \App\Models\Recipe::ALERGENY_DO_PRZEGLADU;
@endphp
<section class="notice" id="alergeny" aria-labelledby="alergeny-naglowek" data-alergeny="{{ $zdeklarowane ? 'declared' : 'unchecked' }}">
    <h3 id="alergeny-naglowek" class="mt-0">Alergeny</h3>
    @if($zdeklarowane && $lista !== '')
        <p>Alergeny według autora: {{ $lista }}. To zaznaczenie autora, nie badanie. Gotowe produkty (sosy, kiełbasy, przyprawy, proszek do pieczenia) mogą zawierać alergeny, których tu nie widać — przeczytaj etykiety.</p>
    @elseif($zdeklarowane)
        <p>Autor nie zaznaczył żadnego z 14 alergenów. To nie jest gwarancja. Przeczytaj etykiety gotowych produktów.</p>
    @else
        <p>Alergeny: nie sprawdzono. Autor nie zaznaczył, co zawiera ten przepis, więc nie wiemy, czy nadaje się dla osoby z alergią.</p>
    @endif

    @if($wlasciciel)
        @if($doPrzegladu)
            <p><strong>Zmieniono składniki po zaznaczeniu alergenów. Sprawdź listę jeszcze raz.</strong> Ten komunikat widzisz tylko Ty.</p>
        @endif
        <a class="btn btn-secondary" href="{{ route('recipes.edit', $recipe->slug) }}#f-alergeny">{{ $zdeklarowane ? 'Zmień oznaczenie alergenów' : ($doPrzegladu ? 'Sprawdź alergeny' : 'Oznacz alergeny') }}</a>
    @endif

    <details class="mt-3">
        <summary class="btn btn-quiet inline-flex">O alergenach na Kuking</summary>
        <p>Oznaczenia wpisują autorzy przepisów. Kuking ich nie sprawdza i nie zastępują one etykiety ani porady lekarza. Nie obiecujemy też, że w przepisie nie ma śladów alergenów ani zanieczyszczeń z innych produktów. Przy gotowych produktach zawsze czytaj etykietę.</p>
        <p>Jeśli widzisz błąd w oznaczeniu, zgłoś przepis i wybierz powód „Błędne oznaczenie alergenów”.</p>
    </details>
</section>
