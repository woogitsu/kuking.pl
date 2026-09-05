{{--
    Zapis nazwy „kuKING" z wersalikami w środku.

    aria-label jest tu konieczne, nie ozdobne: część czytników ekranu literuje
    wersaliki wewnątrz wyrazu („ku-ka-i-en-gie"), co dla osoby korzystającej
    z czytnika zamienia nazwę w bełkot.

    Odmiana przez atrybut, bo w polskim „Zostań kuKINGiem" i „2 431 kuKINGów"
    to dwie różne formy tego samego słowa.
    Dozwolone formy: docs/brand/COPY_STYLE.md §2.
--}}
@props(['forma' => ''])
<span class="kuking-word" aria-label="kuking{{ $forma }}">ku<strong>KING</strong>{{ $forma }}</span>
