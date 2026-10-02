{{--
    NA ILE PORCJI — wybór widza nad listą składników (D-284, V2).

    ZWYKŁE LINKI, NIE FORMULARZ ZE SKRYPTEM. „Mniej” i „Więcej” prowadzą pod
    `?porcje=N#skladniki`, więc działają bez JavaScriptu, przy słabym zasięgu
    i po wysłaniu linku rodzinie. `rel="nofollow"`, bo wyszukiwarka nie ma
    czego szukać w stu wariantach tej samej strony — canonical i tak wskazuje
    przepis bez parametru (`KanonicznyAdresStrony`).

    PRZYCISK, KTÓREGO NIE DA SIĘ UŻYĆ, WYGLĄDA NA WYŁĄCZONY i nie jest linkiem
    (`aria-disabled`) — przy 1 porcji „Mniej” nie udaje, że coś zrobi.

    POLE „NA ILE PORCJI?” (#2499, rozszerzenie D-284) — zwykły formularz GET
    bez JavaScriptu, żeby z 4 porcji zrobić 20 jednym wysłaniem zamiast
    szesnastu „Więcej”. Walidację i zakres 1–100 robi `WyborPorcji::dla()`
    (ta sama co dla linków); tu tylko pokazujemy jej wynik. Błędnie wpisany
    tekst zostaje w polu, a ilości na stronie to ilości autora.

    Przepis bez liczby porcji nie ma od czego liczyć: wtedy nie ma tu nic.
--}}
@if($wyborPorcji->dostepny())
    @php
        $adresPorcji = fn (?float $ile): string => route('recipes.show', array_filter([
            'recipe' => $recipe->slug,
            'porcje' => $ile === null ? null : $wyborPorcji->doAdresu($ile),
            // Na kartce „dla pomocnika” (#2345) zmiana porcji zostaje na kartce.
            'druk' => ($dlaPomocnika ?? false) ? 1 : null,
            'dla' => ($dlaPomocnika ?? false) ? 'pomocnika' : null,
            'qr' => ($dlaPomocnika ?? false) && ($qrNaKartce ?? false) ? 1 : null,
        ], fn ($wartosc) => $wartosc !== null)).'#skladniki';
        $mniej = $wyborPorcji->mniej();
        $wiecej = $wyborPorcji->wiecej();
        // Błędnie wpisany tekst zostaje w polu (Blade go zakodowuje); poprawna
        // liczba wraca z przecinkiem. Tekst obcinamy, by nie rozpychał strony.
        $wpisane = request()->query('porcje');
        $wartoscPola = $wyborPorcji->odrzucone && is_string($wpisane)
            ? \Illuminate\Support\Str::limit($wpisane, 30, '')
            : \App\Domain\Recipes\Porcje\WyborPorcji::doPola((float) $wyborPorcji->wybrane);
    @endphp
    <div class="porcje-wybor" role="group" aria-labelledby="porcje-wybor-tytul">
        <p class="porcje-wybor-tytul" id="porcje-wybor-tytul">Liczba porcji</p>
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
        <form class="porcje-wybor-pole" method="GET" action="{{ route('recipes.show', $recipe->slug) }}#skladniki">
            @if($dlaPomocnika ?? false)
                <input type="hidden" name="druk" value="1">
                <input type="hidden" name="dla" value="pomocnika">
                @if($qrNaKartce ?? false)<input type="hidden" name="qr" value="1">@endif
            @endif
            <label class="porcje-wybor-etykieta" for="porcje-wybor-pole">Na ile porcji?</label>
            <div class="porcje-wybor-przyciski">
                <input class="porcje-wybor-wejscie" id="porcje-wybor-pole" name="porcje" type="text" inputmode="decimal"
                       autocomplete="off" value="{{ $wartoscPola }}"
                       @if($wyborPorcji->odrzucone) aria-invalid="true" aria-describedby="porcje-wybor-blad" @endif>
                <button class="btn btn-secondary" type="submit">Przelicz</button>
            </div>
            @if($wyborPorcji->odrzucone)
                <p class="field-error" id="porcje-wybor-blad" role="alert">Wpisz liczbę od {{ \App\Domain\Recipes\Porcje\WyborPorcji::NAJMNIEJ }} do {{ \App\Domain\Recipes\Porcje\WyborPorcji::NAJWIECEJ }}, na przykład 20 albo 2,5.</p>
            @endif
        </form>
    </div>

    @if($wyborPorcji->odrzucone)
        {{-- Adres z liczbą spoza zakresu albo z literami — pokazujemy przepis
             jak autor go napisał i mówimy, co zrobić. --}}
        <p class="porcje-wybor-uwaga">
            Tej liczby porcji nie da się przeliczyć. Pokazujemy ilości z przepisu.
            Wpisz od {{ \App\Domain\Recipes\Porcje\WyborPorcji::NAJMNIEJ }} do {{ \App\Domain\Recipes\Porcje\WyborPorcji::NAJWIECEJ }} w polu albo użyj przycisków „Mniej” i „Więcej”.
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
