{{--
    Kolaż zdjęć we wpisie (issue #92).

    KOLAŻ NIE OBCINA JEDZENIA
    Typowa siatka kolażowa kadruje każde zdjęcie do kwadratu (`object-fit:
    cover`) — i ucina dokładnie to, co człowiek chciał pokazać: brzeg blachy,
    rękę babci, całą rybę. W Kuking zdjęcie jedzenia JEST treścią wpisu,
    więc pola siatki są równe, ale zdjęcie mieści się w polu w całości
    (`object-fit: contain`, patrz `.kolaz-pole` w app.css). Cena jest widoczna
    i świadoma: przy zdjęciach o różnych proporcjach zostają puste marginesy.
    Pusty margines da się zignorować, uciętego jedzenia nie da się odzyskać.

    KAŻDE ZDJĘCIE JEST OSOBNYM ODNOŚNIKIEM DO POWIĘKSZENIA
    Pole w siatce jest mniejsze niż zdjęcie na całą szerokość karty, więc
    droga do dużego widoku musi być oczywista — to zwykły link z `x-photo`,
    działający bez skryptu.
--}}
@props(['post', 'priority' => false])
@php
    $zdjecia = $post->media;
    $ile = $zdjecia->count();
@endphp
<ul class="kolaz" aria-label="Kolaż zdjęć: {{ $ile }}">
    @foreach($zdjecia as $index => $media)
        <li class="kolaz-pole">
            <x-photo :media="$media"
                     :priority="$priority && $loop->first"
                     variant="thumb"
                     sizes="(min-width: 64rem) 360px, 50vw"
                     :alt="$media->alt_text ?: 'Zdjęcie '.($index + 1).' z '.$ile.' w tym wpisie'" />
        </li>
    @endforeach
</ul>
