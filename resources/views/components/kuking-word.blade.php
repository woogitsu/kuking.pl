{{--
    Zapis nazwy „kuKING" z wersalikami w środku.

    DLACZEGO NIE `aria-label` NA <span>:
    specyfikacja „ARIA in HTML" zakazuje `aria-label` na elementach o roli
    `generic` — czyli dokładnie na <span> i <strong>. Czytniki ekranu taki
    atrybut IGNORUJĄ, więc wcześniejsza wersja tego komponentu nie robiła nic
    poza dodaniem złudzenia, że problem jest rozwiązany.

    Poprawny wzorzec: wersja wizualna schowana przed czytnikiem
    (`aria-hidden`), a obok tekst czytany wyłącznie przez czytnik.
    Dzięki temu osoba korzystająca z NVDA albo VoiceOver słyszy „kukingi",
    a nie literowane „ku-ka-i-en-gie".

    Odmiana przez atrybut, bo „Zostań kuKINGiem" i „2 431 kuKINGów" to dwie
    różne formy tego samego słowa. Dozwolone formy: docs/brand/COPY_STYLE.md §2.
--}}
@props(['forma' => ''])
<span class="kuking-word">
    <span aria-hidden="true">ku<strong>KING</strong>{{ $forma }}</span>
    <span class="visually-hidden">kuking{{ $forma }}</span>
</span>
