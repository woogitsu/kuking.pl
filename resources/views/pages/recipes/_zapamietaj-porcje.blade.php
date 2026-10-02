{{--
    „ZAPAMIĘTAJ DLA MNIE” — jawny, prywatny zapis własnej liczby porcji przy
    jednym przepisie (#2602, rozszerzenie D-284; decyzja właściciela w D-333).

    TYLKO ZALOGOWANA OSOBA I TYLKO ŚWIADOMYM PRZYCISKIEM. To zwykłe formularze
    POST/DELETE, bez JavaScriptu; nic nie zapisuje się samo przy „Mniej” czy
    „Więcej”. Gość nie widzi tu nic. Liczbę wysyła ukryte pole, ale serwer i tak
    sprawdza ją przez `WyborPorcji` — formularz niczego nie rozstrzyga.

    PIERWSZEŃSTWO. Liczba z adresu (`?porcje=N`) wygrywa z zapamiętaną; zapamiętana
    działa tylko przy adresie bez porcji. Ilości autora przy zapamiętanym
    ustawieniu to jawne `?porcje=autor` (`WyborZapamietanychPorcji::parametrDla`).

    Zmienne: $recipe, $wyborPorcji (WyborPorcji), $zapamietanePorcje (WyborZapamietanychPorcji).
--}}
@auth
    @if(auth()->user()->isActive())
        @php
            $zapamietane = $zapamietanePorcje->zapamietane;
            $akcjaZapisu = route('recipes.porcje.store', $recipe->slug);
            $akcjaZapomnienia = route('recipes.porcje.destroy', $recipe->slug);
            $adresMojego = route('recipes.show', $recipe->slug).'#skladniki';
            $podstawaZmieniona = $zapamietane !== null && $wyborPorcji->dostepny()
                && abs($zapamietane - (float) $wyborPorcji->zPrzepisu) < 0.001;
            $inneNizZapamietane = $wyborPorcji->przeliczone()
                && ($zapamietane === null || abs($zapamietane - (float) $wyborPorcji->wybrane) >= 0.001);
        @endphp
        @if($zapamietane !== null && ! $wyborPorcji->dostepny())
            {{-- Autor usunął liczbę porcji: przepis nie jest skalowany, a stare ustawienie można zapomnieć. --}}
            <div class="porcje-wybor-uwaga porcje-zapamietane" id="zapamietane-porcje">
                <p class="m-0">
                    Masz zapamiętane ustawienie: {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($zapamietane) }}.
                    Autor nie podaje teraz liczby porcji przy tym przepisie, więc ilości są takie, jak w przepisie.
                </p>
                <div class="porcje-wybor-przyciski mt-2">
                    <form method="POST" action="{{ $akcjaZapomnienia }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-secondary">Zapomnij moje ustawienie</button>
                    </form>
                </div>
            </div>
        @elseif($zapamietane !== null)
            <div class="porcje-wybor-uwaga porcje-zapamietane" id="zapamietane-porcje">
                @if($podstawaZmieniona)
                    <p class="m-0">
                        <strong>Masz zapamiętane ustawienie: {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($zapamietane) }}.</strong>
                        Autor podaje teraz ilości {{ \App\Domain\Recipes\Porcje\WyborPorcji::naIle((float) $wyborPorcji->zPrzepisu) }}, czyli tyle samo.
                        Możesz zapomnieć to ustawienie.
                    </p>
                @elseif($zapamietanePorcje->zUstawienia())
                    <p class="m-0">
                        <strong>To Twoje zapamiętane ustawienie dla tego przepisu.</strong>
                        Widzisz je tylko Ty.
                    </p>
                @else
                    <p class="m-0">
                        <strong>Masz zapamiętane ustawienie: {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($zapamietane) }}.</strong>
                        Teraz widzisz inne ilości: z adresu albo z przepisu autora.
                    </p>
                @endif
                <div class="porcje-wybor-przyciski mt-2">
                    @if(! $zapamietanePorcje->zUstawienia() && ! $podstawaZmieniona)
                        <a class="btn btn-secondary" href="{{ $adresMojego }}" rel="nofollow">Pokaż moje ustawienie</a>
                    @endif
                    @if($inneNizZapamietane)
                        <form method="POST" action="{{ $akcjaZapisu }}">
                            @csrf
                            <input type="hidden" name="porcje" value="{{ $wyborPorcji->doAdresu((float) $wyborPorcji->wybrane) }}">
                            <button type="submit" class="btn btn-secondary">Zapamiętaj zamiast tego {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta((float) $wyborPorcji->wybrane) }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ $akcjaZapomnienia }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-secondary">Zapomnij moje ustawienie</button>
                    </form>
                </div>
            </div>
        @elseif($wyborPorcji->przeliczone())
            <div class="porcje-wybor-uwaga porcje-zapamietane" id="zapamietane-porcje">
                <p class="m-0">
                    Następnym razem ten przepis może otworzyć się od razu {{ \App\Domain\Recipes\Porcje\WyborPorcji::naIle((float) $wyborPorcji->wybrane) }}.
                    Zapisujemy tylko tę jedną liczbę, tylko dla Ciebie.
                </p>
                <div class="porcje-wybor-przyciski mt-2">
                    <form method="POST" action="{{ $akcjaZapisu }}">
                        @csrf
                        <input type="hidden" name="porcje" value="{{ $wyborPorcji->doAdresu((float) $wyborPorcji->wybrane) }}">
                        <button type="submit" class="btn btn-secondary">Zapamiętaj dla mnie {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta((float) $wyborPorcji->wybrane) }}</button>
                    </form>
                </div>
            </div>
        @endif
    @endif
@endauth
