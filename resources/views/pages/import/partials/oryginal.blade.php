{{--
    Oryginał obok tekstu (projekt §6.3): zdjęcie kartki przy polach składników
    i kroków. Na szerokim ekranie w kolumnie obok, na telefonie nad polami
    (`.oryginal-kartki` w app.css). Dotknięcie otwiera duży wariant — zwykłym
    odnośnikiem, bez szczypania jako jedynej drogi.
--}}
@if(($skan ?? null) !== null)
    <figure class="oryginal-kartki">
        <x-photo :media="$skan" variant="large" :zoom="true" alt="Zdjęcie kartki, z której odczytano przepis" tresc="przepis"
                 sizes="(min-width: 64rem) 420px, 100vw" />
        <figcaption class="field-help">Zdjęcie kartki. Dotknij, żeby zobaczyć je w pełnym rozmiarze.</figcaption>
    </figure>
@endif
