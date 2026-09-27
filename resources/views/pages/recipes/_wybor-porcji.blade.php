{{--
    NA ILE PORCJI — wybór widza nad listą składników (D-284, V2).

    ZWYKŁE LINKI, NIE FORMULARZ ZE SKRYPTEM. „Mniej” i „Więcej” prowadzą pod
    `?porcje=N#skladniki`, więc działają bez JavaScriptu, przy słabym zasięgu
    i po wysłaniu linku rodzinie. `rel="nofollow"`, bo wyszukiwarka nie ma
    czego szukać w stu wariantach tej samej strony — canonical i tak wskazuje
    przepis bez parametru (`KanonicznyAdresStrony`).

    PRZYCISK, KTÓREGO NIE DA SIĘ UŻYĆ, WYGLĄDA NA WYŁĄCZONY i nie jest linkiem
    (`aria-disabled`) — przy 1 porcji „Mniej” nie udaje, że coś zrobi.

    Przepis bez liczby porcji nie ma od czego liczyć: wtedy nie ma tu nic.
--}}
@if($wyborPorcji->dostepny())
    @php
        $adresPorcji = fn (?float $ile): string => route('recipes.show', array_filter([
            'recipe' => $recipe->slug,
            'porcje' => $ile === null ? null : $wyborPorcji->doAdresu($ile),
        ], fn ($wartosc) => $wartosc !== null)).'#skladniki';
        $mniej = $wyborPorcji->mniej();
        $wiecej = $wyborPorcji->wiecej();
    @endphp
    <div class="porcje-wybor" role="group" aria-labelledby="porcje-wybor-tytul">
        <p class="porcje-wybor-tytul" id="porcje-wybor-tytul">Na ile porcji?</p>
        <div class="porcje-wybor-przyciski">
            @if($mniej !== null)
                <a class="btn btn-secondary porcje-wybor-krok" href="{{ $adresPorcji($mniej) }}" rel="nofollow"
                   aria-label="Mniej porcji: {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($mniej) }}"><span aria-hidden="true">−</span> Mniej</a>
            @else
                <span class="btn btn-secondary porcje-wybor-krok" aria-disabled="true"><span aria-hidden="true">−</span> Mniej</span>
            @endif
            <strong class="porcje-wybor-liczba">{{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wyborPorcji->wybrane) }}</strong>
            @if($wiecej !== null)
                <a class="btn btn-secondary porcje-wybor-krok" href="{{ $adresPorcji($wiecej) }}" rel="nofollow"
                   aria-label="Więcej porcji: {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wiecej) }}">Więcej <span aria-hidden="true">+</span></a>
            @else
                <span class="btn btn-secondary porcje-wybor-krok" aria-disabled="true">Więcej <span aria-hidden="true">+</span></span>
            @endif
        </div>
    </div>

    @if($wyborPorcji->odrzucone)
        {{-- Adres z liczbą spoza zakresu albo z literami — pokazujemy przepis
             jak autor go napisał i mówimy, co zrobić. --}}
        <p class="porcje-wybor-uwaga">
            Tej liczby porcji nie da się przeliczyć. Pokazujemy ilości z przepisu.
            Wybierz od {{ \App\Domain\Recipes\Porcje\WyborPorcji::NAJMNIEJ }} do {{ \App\Domain\Recipes\Porcje\WyborPorcji::NAJWIECEJ }} przyciskami „Mniej” i „Więcej”.
        </p>
    @endif

    @if($wyborPorcji->przeliczone())
        <div class="porcje-wybor-uwaga">
            <p class="m-0">
                <strong>Przeliczone {{ \App\Domain\Recipes\Porcje\WyborPorcji::naIle($wyborPorcji->wybrane) }}.</strong>
                Autor podał ilości {{ \App\Domain\Recipes\Porcje\WyborPorcji::naIle($wyborPorcji->zPrzepisu) }}.
                Zaokrągliliśmy je po kuchennemu. Szczypta, „do smaku” i składniki bez liczby zostały bez zmian.
            </p>
            <p class="m-0 mt-2"><a href="{{ $adresPorcji(null) }}">Pokaż ilości z przepisu</a></p>
        </div>
    @endif
@endif
