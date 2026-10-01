@props(['event', 'wskazowka' => null, 'przepisZaBlokada' => false])
{{--
    WSKAZÓWKA OD GOTUJĄCYCH — panel na stronie JEDNEGO wykonania (#2352, D-333).

    Dwie role, dwa różne zdania, nigdy oba naraz:

    - KUCHARZ (autor wykonania) widzi prośbę o zgodę: dokładny tekst uwagi,
      kto prosi i dwa duże przyciski „Zgadzam się” / „Nie”. Brak odpowiedzi to
      brak publikacji — i tak jest napisane. Po zgodzie widzi „Wycofaj zgodę”
      (za potwierdzeniem: wycofanie jest ostateczne).
    - AUTOR PRZEPISU widzi „Poproś o zgodę”, a potem stan: czeka albo stoi
      przy przepisie. „Nie” i wycofanie zgody wyglądają dla niego IDENTYCZNIE
      („nie jest dostępna jako wskazówka”) — żadnej presji na kucharza.

    Wszystko zwykłymi formularzami, bez skryptu. Tytuł przepisu nie pojawia się
    przy blokadzie z autorem (jak w `x-cooked-card`, #1394).
--}}
@php
    $zalogowany = auth()->user();
    $jestKucharzem = $zalogowany !== null && $zalogowany->getKey() === $event->user_id;
    $jestAutoremPrzepisu = $zalogowany !== null && $event->recipe !== null
        && $zalogowany->getKey() === $event->recipe->author_id
        && ! $jestKucharzem;
@endphp

@if($jestKucharzem && $wskazowka !== null)
    <section id="wskazowka" class="card stack" aria-labelledby="wskazowka-naglowek">
        @if($wskazowka->czekaNaOdpowiedz())
            <h2 id="wskazowka-naglowek" class="m-0">Prośba o zgodę na wskazówkę</h2>
            <p class="m-0">
                {{ $wskazowka->author->displayName() }} prosi o zgodę, by ta uwaga stała
                @if($event->recipe && ! $przepisZaBlokada)
                    przy przepisie „{{ $event->recipe->title }}”
                @else
                    przy jego przepisie
                @endif
                jako wskazówka dla innych gotujących:
            </p>
            <blockquote class="wskazowka-cytat tekst-jak-napisano">{{ $event->note }}</blockquote>
            <p class="m-0">
                Jeśli się zgodzisz, każdy, kto widzi ten przepis, zobaczy tę uwagę razem z Twoją nazwą.
                Zgodę możesz wycofać w każdej chwili. Jeśli nie odpowiesz, nic się nie stanie — uwaga nie zostanie pokazana.
            </p>
            <div class="wskazowka-akcje">
                @can('accept', $wskazowka)
                    <form method="POST" action="{{ route('hints.accept', $wskazowka) }}">
                        @csrf
                        <button class="btn btn-primary" type="submit">Zgadzam się</button>
                    </form>
                @endcan
                <form method="POST" action="{{ route('hints.decline', $wskazowka) }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit">Nie</button>
                </form>
            </div>
            @cannot('accept', $wskazowka)
                <p class="meta m-0">Tej prośby nie da się teraz przyjąć. Możesz odpowiedzieć „Nie” albo zostawić ją bez odpowiedzi.</p>
            @endcannot
            <p class="meta m-0">Odpowiedź „Nie” jest ostateczna i nie wysyła nikomu wiadomości.</p>
        @elseif($wskazowka->jestPrzyjeta() && $wskazowka->jestUkrytaPrzezModeracje())
            {{-- Moderacja zdjęła samą wskazówkę (#2352). Uwaga i wykonanie zostają;
                 decyzję z uzasadnieniem i odnośnikiem do odwołania kucharz dostał
                 w powiadomieniu. Zgodę nadal może wycofać. --}}
            <h2 id="wskazowka-naglowek" class="m-0">Ta wskazówka została ukryta przez moderację</h2>
            <p class="m-0">
                Nie widać jej już przy przepisie, ale Twoja uwaga i to wykonanie zostały bez zmian.
                Uzasadnienie i możliwość odwołania znajdziesz w powiadomieniu o decyzji.
                Zgodę możesz wycofać w każdej chwili.
            </p>
            <x-confirm-button
                :action="route('hints.withdraw', $wskazowka)"
                method="POST"
                label="Wycofaj zgodę"
                question="Wycofać zgodę? Autor nie będzie mógł poprosić o tę uwagę drugi raz." />
        @elseif($wskazowka->jestPrzyjeta())
            <h2 id="wskazowka-naglowek" class="m-0">Ta uwaga jest wskazówką przy przepisie</h2>
            <p class="m-0">
                Stoi tam razem z Twoją nazwą, dzięki Twojej zgodzie. Możesz ją wycofać w każdej chwili —
                wskazówka zniknie ze strony przepisu, a uwaga zostanie pod tym wykonaniem.
            </p>
            <x-confirm-button
                :action="route('hints.withdraw', $wskazowka)"
                method="POST"
                label="Wycofaj zgodę"
                question="Wycofać zgodę? Wskazówka zniknie ze strony przepisu, a autor nie będzie mógł poprosić o nią drugi raz." />
        @elseif($wskazowka->wygasla())
            {{-- Czekająca prośba po 30 dniach: koniec, bez przycisków i bez ponowienia. --}}
            <h2 id="wskazowka-naglowek" class="m-0">Ta prośba wygasła</h2>
            <p class="m-0">Prośba o zgodę na wskazówkę była bez odpowiedzi zbyt długo i wygasła. Nic nie musisz robić — uwaga zostaje tylko pod tym wykonaniem.</p>
        @elseif($wskazowka->jestAnulowana())
            <h2 id="wskazowka-naglowek" class="m-0">Prośba została wycofana</h2>
            <p class="m-0">Autor przepisu wycofał prośbę o zgodę na wskazówkę. Nic nie musisz robić — uwaga zostaje tylko pod tym wykonaniem.</p>
        @else
            <h2 id="wskazowka-naglowek" class="m-0">Wskazówka przy przepisie</h2>
            <p class="m-0">Ta uwaga nie jest pokazywana jako wskazówka. Zostaje tylko pod tym wykonaniem.</p>
        @endif
    </section>
@elseif($jestAutoremPrzepisu && ! $przepisZaBlokada)
    @if($wskazowka === null)
        @can('propose', [\App\Models\RecipeHint::class, $event])
            <section id="wskazowka-autor" class="card stack" aria-labelledby="wskazowka-autor-naglowek">
                <h2 id="wskazowka-autor-naglowek" class="m-0">Wskazówka przy Twoim przepisie</h2>
                <p class="m-0">
                    Ta uwaga może pomóc innym. Możesz poprosić osobę, która ugotowała, o zgodę na pokazanie jej
                    przy Twoim przepisie jako wskazówki. Nic nie pojawi się bez jej zgody, a prośbę można wysłać
                    tylko raz.
                </p>
                <form method="POST" action="{{ route('hints.propose', $event) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Poproś o zgodę</button>
                </form>
            </section>
        @endcan
    @elseif($wskazowka->czekaNaOdpowiedz())
        <section id="wskazowka-autor" class="card stack" aria-labelledby="wskazowka-autor-naglowek">
            <h2 id="wskazowka-autor-naglowek" class="m-0">Wskazówka przy Twoim przepisie</h2>
            <p class="m-0">Czeka na odpowiedź osoby, która ugotowała. Dopóki się nie zgodzi, nic nie jest pokazane przy przepisie. Prośba wygasa po {{ (int) config('kuking.wskazowki.prosba_wygasa_po_dniach') }} dniach bez odpowiedzi.</p>
            <x-confirm-button
                :action="route('hints.cancel', $wskazowka)"
                method="POST"
                label="Anuluj prośbę"
                question="Anulować prośbę? Osoba, która ugotowała, nie dostanie o tym wiadomości, a o to samo wykonanie nie będzie można poprosić drugi raz." />
        </section>
    @elseif($wskazowka->jestPokazywana())
        <section id="wskazowka-autor" class="card stack" aria-labelledby="wskazowka-autor-naglowek">
            <h2 id="wskazowka-autor-naglowek" class="m-0">Wskazówka przy Twoim przepisie</h2>
            <p class="m-0">Ta uwaga stoi przy przepisie jako wskazówka. Osoba, która ugotowała, może to w każdej chwili wycofać.</p>
        </section>
    @elseif($wskazowka->jestAnulowana())
        <section id="wskazowka-autor" class="card stack" aria-labelledby="wskazowka-autor-naglowek">
            <h2 id="wskazowka-autor-naglowek" class="m-0">Wskazówka przy Twoim przepisie</h2>
            <p class="m-0">Ta prośba została anulowana. O to samo wykonanie nie można poprosić drugi raz.</p>
        </section>
    @else
        {{-- „Nie”, wycofana zgoda, wygasła prośba i wskazówka ukryta przez moderację
             (#2352) wyglądają tu tak samo — autor nie dowiaduje się, która to była. --}}
        <section id="wskazowka-autor" class="card stack" aria-labelledby="wskazowka-autor-naglowek">
            <h2 id="wskazowka-autor-naglowek" class="m-0">Wskazówka przy Twoim przepisie</h2>
            <p class="m-0">Ta uwaga nie jest dostępna jako wskazówka.</p>
        </section>
    @endif
@endif
