{{--
    Zapis nazwy „kuKING" z wersalikami w środku — dwukolorowo: „ku" bierze
    kolor tekstu wokół, „KING" kolor marki (`.kuking-word strong`
    w `resources/css/app.css`).

    GDZIE WOLNO, A GDZIE NIE — `docs/brand/GLOS_MARKI.md` §2 i §3.
    W skrócie: wszędzie, gdzie nazwa jest czytana jako nazwa (nagłówek, tekst
    bieżący, nawigacja, stopka, zaproszenie). NIE tutaj:

      1. tam, gdzie koloru i znacznika nie ma — `alt`, `title`, `aria-label`,
         `<title>`, `meta`, JSON-LD, temat listu, pliki eksportu, tekst tylko
         dla czytnika ekranu. Tam piszemy zwyczajnie „Kuking";
      2. w błędzie, moderacji, tekście prawnym, na ekranie bezpieczeństwa
         i w liście technicznym (hierarchia tonu — GLOS_MARKI §3);
      3. w powiadomieniu o cudzej aktywności;
      4. w polu formularza, który ktoś właśnie wypełnia.

    Pilnuje tego `tests/Feature/TekstyWedlugCopyStyleTest.php`.

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

    `bez-koloru` — ŚWIADOMA REZYGNACJA Z CZERWIENI, GDY NIE MA NA NIEJ
    KONTRASTU. Czerwień marki na tle w kolorze marki daje 1,00:1, czyli tekst
    niewidoczny, a WCAG 1.4.3 wymaga 4,5:1. Przycisk podstawowy ma dokładnie
    takie tło, więc tam „KING" bierze kolor otoczenia i nośnikiem zostają same
    wersaliki. Dla `.btn` robi to już arkusz stylów; ten parametr jest dla
    każdego innego miejsca, w którym trafisz na tło w kolorze marki — nazwany,
    żeby nie trzeba było zgadywać z CSS-a, dlaczego słowo raz jest dwukolorowe,
    a raz nie.
--}}
@props(['forma' => '', 'bezKoloru' => false])
<span @class(['kuking-word', 'kuking-word--bez-koloru' => $bezKoloru])>
    <span aria-hidden="true">ku<strong>KING</strong>{{ $forma }}</span>
    <span class="visually-hidden">kuking{{ $forma }}</span>
</span>
