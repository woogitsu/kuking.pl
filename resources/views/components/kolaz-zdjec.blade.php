{{--
    Kolaż zdjęć we wpisie (issue #92).

    KOLAŻ NIE OBCINA JEDZENIA I NIE DODAJE PUSTYCH KWADRATÓW
    Zdjęcia w każdym wierszu dostają szerokość proporcjonalną do własnego
    formatu. Mają wspólną wysokość bez szarych pasów nad i pod jedzeniem.
    Ostatnie zdjęcie w nieparzystej grupie zajmuje cały wiersz.

    KAŻDE ZDJĘCIE JEST OSOBNYM ODNOŚNIKIEM DO POWIĘKSZENIA
    Pole w siatce jest mniejsze niż zdjęcie na całą szerokość karty, więc
    droga do dużego widoku musi być oczywista — to zwykły link z `x-photo`,
    działający bez skryptu.
--}}
@props(['post', 'priority' => false])
@php
    $zdjecia = $post->media;
    $ile = $zdjecia->count();
    $wiersze = $zdjecia->values()->chunk(2);
    $numer = 0;
@endphp
<div class="kolaz" role="list" aria-label="Kolaż zdjęć: {{ $ile }}">
    @foreach($wiersze as $wiersz)
        @php
            $sumaProporcji = $wiersz->sum(fn ($zdjecie) =>
                max(1, (int) ($zdjecie->width('thumb') ?? 1)) /
                max(1, (int) ($zdjecie->height('thumb') ?? 1))
            );
        @endphp
        <div class="kolaz-wiersz" role="presentation">
            @foreach($wiersz as $media)
                @php
                    $numer++;
                    $szerokosc = max(1, (int) ($media->width('thumb') ?? 1));
                    $wysokosc = max(1, (int) ($media->height('thumb') ?? 1));
                    $stosunek = $szerokosc / $wysokosc;
                    $format = max(4, min(24, (int) round($stosunek * 8)));
                    $udzial = $stosunek / $sumaProporcji;
                    $sizes = $wiersz->count() === 1
                        ? '(min-width: 64rem) 720px, 100vw'
                        : '(min-width: 64rem) '.round(720 * $udzial).'px, (min-width: 30rem) '.round(100 * $udzial).'vw, 100vw';
                @endphp
                <div class="kolaz-pole kolaz-format-{{ $format }}" role="listitem">
                    <x-photo :media="$media"
                             :priority="$priority && $numer === 1"
                             variant="thumb"
                             :sizes="$sizes"
                             :alt="$media->alt_text ?: 'Zdjęcie '.$numer.' z '.$ile.' w tym wpisie'" />
                </div>
            @endforeach
        </div>
    @endforeach
</div>
