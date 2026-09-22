{{--
    Błąd przy GRUPIE WYBORU (radio, checkbox) albo przy polu pliku — czyli
    wszędzie tam, gdzie nie da się użyć `x-field`.

    PO CO TO ISTNIEJE (zmierzone, nie założone)
    `x-error-summary` na górze formularza robi z każdego błędu ODNOŚNIK
    prowadzący pod `#f-<nazwa pola>`. Przy polach z `x-field` ten odnośnik
    działa, bo `x-field` nadaje polu dokładnie takie `id`. Przy grupach
    wyboru nikt tego `id` nie nadawał — więc przy pomiarze z września 2026
    siedem odnośników w podsumowaniu nie prowadziło DONIKĄD: człowiek klikał
    zdanie „Zaznacz, kto ma widzieć ten wpis", a strona nie ruszała się
    z miejsca. Dotyczyło to pola `visibility` na trzech formularzach,
    `reason` na dwóch, a także `kind` i `perceived_difficulty`.

    Przy dwóch grupach („Zrobisz to jeszcze raz?" i „Jak trudne to było dla
    Ciebie?") było gorzej: błąd nie pojawiał się przy polu W OGÓLE, tylko
    w podsumowaniu — a `docs/UX_50_PLUS.md` wymaga OBU miejsc naraz.

    JAK TEGO UŻYWAĆ
    Na znaczniku grupy (zwykle `fieldset`) postaw `id="f-<nazwa>"`, a przy
    błędzie także `tabindex="-1"`, `aria-invalid="true"` i `aria-describedby`
    wskazujące na `f-<nazwa>-error`. Pod grupą postaw ten komponent:

        <x-blad-grupy name="visibility" />

    `id` liczy się tą samą regułą co w `x-field` (nawiasy i kropki na
    myślniki), więc obie drogi dają ten sam identyfikator i podsumowanie
    trafia tam, gdzie powinno. `aria-invalid` jest w ARIA atrybutem
    globalnym, więc wolno go postawić także na `fieldset` (rola `group`).
    `tabindex="-1"` jest potrzebny, bo bez niego przeglądarka po kliknięciu
    odnośnika przewinie stronę, ale nie przeniesie FOKUSU — osoba chodząca
    klawiaturą albo czytnikiem zostaje na górze formularza.
--}}
@props(['name'])
@php
    $idPola = 'f-'.str_replace(['[', ']', '.'], '-', $name);
@endphp
@error($name)
    <span class="field-error" id="{{ $idPola }}-error">{{ $message }}</span>
@enderror
