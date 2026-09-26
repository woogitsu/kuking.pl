{{--
    Karuzela zdjęć we wpisie (issue #92).

    KARUZELA DZIAŁA BEZ JAVASCRIPTU I TO JEST WARUNEK, NIE OZDOBNIK
    Przewijanie robi przeglądarka: taśma to kontener z `overflow-x: auto`
    i `scroll-snap-type: x mandatory`, a „Poprzednie / Następne” to zwykłe
    odnośniki do sąsiednich slajdów (`#id`). Skok do kotwicy przewija
    NAJBLIŻSZEGO przewijalnego przodka — czyli właśnie taśmę — więc cała
    obsługa działa na wyłączonym skrypcie, na starym telefonie i przy
    słabym łączu (AGENTS.md §5).

    PRZYCISKI SĄ WIDOCZNE I MAJĄ TEKST
    `docs/UX_50_PLUS.md` zabrania swipe’a jako JEDYNEJ drogi. Karuzela
    obsługiwana wyłącznie przesunięciem palca jest niedostępna dla części
    naszych użytkowników — a to jest serwis dla osób 50+. Dlatego pod każdym
    zdjęciem stoją dwa przyciski o wysokości 48 px, z pełnym napisem,
    nie samą strzałką.

    STEROWANIE JEST W ŚRODKU SLAJDU, NIE OBOK TAŚMY
    Dzięki temu widoczna para przycisków ZAWSZE należy do widocznego zdjęcia
    i wie, dokąd prowadzi — bez `:target`, bez skryptu i bez stanu, który
    trzeba by gdzieś trzymać. Na krańcach zamiast odnośnika stoi nieaktywny
    napis: przycisk, który nie ma dokąd prowadzić, jest gorszy niż jego brak
    (ten sam wzorzec co „Przenieś w górę/w dół" w kreatorze przepisu, #13).

    OGŁASZANIE ZMIANY SLAJDU
    Numer slajdu jest WIDOCZNY i wpisany w tekst alternatywny każdego zdjęcia,
    więc bez skryptu nikt nie gubi się w kolejności. Obszar `aria-live` jest
    celowo PUSTY w kodzie strony — wypełnia go dopiero skrypt (resources/js),
    kiedy zmieni się widoczny slajd. Wpisany na stałe tekst „Zdjęcie 1 z 4"
    kłamałby po pierwszym przewinięciu u każdego, kto nie ma JavaScriptu.
--}}
@props(['post', 'priority' => false])
@php
    $zdjecia = $post->media;
    $ile = $zdjecia->count();
    $slajd = fn (int $numer): string => 'wpis-'.$post->getKey().'-zdjecie-'.$numer;
@endphp
<div class="karuzela"
     role="group"
     aria-roledescription="karuzela"
     aria-label="Zdjęcia w tym wpisie: {{ $ile }}">

    <p class="visually-hidden" aria-live="polite" data-karuzela-ogloszenie></p>

    <ol class="karuzela-tasma" data-karuzela-tasma>
        @foreach($zdjecia as $index => $media)
            @php $numer = $index + 1; @endphp
            <li class="karuzela-slajd" id="{{ $slajd($numer) }}" data-karuzela-numer="{{ $numer }}">
                <x-photo :media="$media"
                         :priority="$priority && $loop->first"
                         :alt="$media->alt_text ?: 'Zdjęcie '.$numer.' z '.$ile.' w tym wpisie'" />

                <div class="karuzela-pasek">
                    <p class="karuzela-licznik">Zdjęcie {{ $numer }} z {{ $ile }}</p>

                    <div class="karuzela-sterowanie">
                        @if($numer > 1)
                            <a class="btn btn-secondary" href="#{{ $slajd($numer - 1) }}">Poprzednie zdjęcie</a>
                        @else
                            <span class="btn btn-secondary" aria-disabled="true">Poprzednie zdjęcie</span>
                        @endif

                        @if($numer < $ile)
                            <a class="btn btn-secondary" href="#{{ $slajd($numer + 1) }}">Następne zdjęcie</a>
                        @else
                            <span class="btn btn-secondary" aria-disabled="true">Następne zdjęcie</span>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ol>
</div>
