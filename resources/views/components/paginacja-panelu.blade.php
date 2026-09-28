{{--
    Stronicowanie kolejek panelu moderacji: „Poprzednia strona”,
    „Strona 2 z 4”, „Następna strona”.

    DLACZEGO WŁASNY WIDOK, A NIE `$x->links()`
    `links()` renderował gotowy widok Laravela `pagination::tailwind`.
    Opiera się on na klasach Tailwinda (`sm:flex`, `w-5`, `leading-5`…),
    których w naszym zbudowanym CSS nie ma — Tailwind 4 nie skanuje
    `vendor/`. Zmierzone w audycie B1 (25 września 2026): na komputerze
    blok miał `hidden sm:flex`, a `sm:flex` nie istniało, więc pod listą
    nie było NIC. Moderator widział 25 zgłoszeń i nie miał jak się
    dowiedzieć, że czeka ich 40. Na telefonie linki miały 14 px i 38 px
    wysokości, a w HTML-u stało „Showing 26 to 50 of 100 results”.

    Tu są zwykłe `.btn` (48 px, `--text-body`), napisy po polsku i żadnej
    klasy, która zależy od skanu cudzego katalogu. Numer strony stoi w
    zdaniu, bo panel przegląda KOLEJKĘ i trzeba wiedzieć, ile zostało
    (patrz `PaginacjaToPokazWiecejTest`). Przycisku, który nie ma dokąd
    prowadzić, nie rysujemy wcale — wyszarzony martwy przycisk udaje żywy.

    Pilnuje tego `tests/Feature/PaginacjaPaneluModeracjiTest.php`.
--}}
@props(['paginator'])
@if($paginator->hasPages())
    <nav class="flex flex-wrap items-center gap-3" aria-label="Strony listy">
        @unless($paginator->onFirstPage())
            <a class="btn btn-secondary" href="{{ $paginator->previousPageUrl() }}" rel="prev">Poprzednia strona</a>
        @endunless

        <p class="m-0">Strona {{ $paginator->currentPage() }} z {{ $paginator->lastPage() }}</p>

        @if($paginator->hasMorePages())
            <a class="btn btn-secondary" href="{{ $paginator->nextPageUrl() }}" rel="next">Następna strona</a>
        @endif
    </nav>
@endif
