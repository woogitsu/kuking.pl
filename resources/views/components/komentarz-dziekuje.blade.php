{{--
    „Dziękuję" pod komentarzem (issue #2355, F11).

    Autor treści — wpisu, przepisu albo wykonania — widzi pod CUDZYM
    komentarzem przycisk z widocznym słowem „Dziękuję". To zwykły formularz
    POST z CSRF, działa bez JavaScriptu; przycisk to `.btn` (co najmniej 48 px,
    tekst nie tylko ikona). Dla czytnika ekranu dochodzi niewidoczne dopowiedzenie
    „za komentarz od …" — widoczne słowo zostaje pierwsze w nazwie przycisku.

    Po podziękowaniu przycisk zamienia się w zwykły napis „Podziękowano za ten
    komentarz." Widzą go dwie osoby: dziękujący i autor komentarza. Nikt inny,
    bez licznika — pilnuje `PodziekowaniaNieMajaLicznikaTest`. Wycofania nie ma
    (decyzja w `ThankForComment`), więc nie ma tu drugiego przycisku.

    Widok rysuje przycisk po TANIM warunku (`CommentPolicy::offerThank()`, bez
    zapytań do bazy). Lista komentarzy jest już przefiltrowana pod kątem blokad
    i statusu; pełna bramka `CommentPolicy::thank()` obowiązuje przy kliknięciu.

    `podziekowane` — mapa „id komentarza → true" policzona RAZ dla całej listy
    w `comment-thread` (jedno zapytanie, nie jedno na komentarz).
--}}
@props(['comment', 'podziekowane' => []])
@php($juzPodziekowano = isset($podziekowane[(string) $comment->getKey()]))
@can('offerThank', $comment)
    @if($juzPodziekowano)
        <span class="meta">Podziękowano za ten komentarz.</span>
    @else
        <form method="POST" action="{{ route('comments.thank', $comment) }}" novalidate>
            @csrf
            <button class="btn btn-quiet" type="submit">Dziękuję<span class="visually-hidden"> za komentarz od {{ $comment->author->displayName() }}</span></button>
        </form>
    @endif
@elseif($juzPodziekowano && auth()->id() === $comment->author_id)
    <span class="meta">Podziękowano za ten komentarz.</span>
@endcan
