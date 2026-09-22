{{--
    Potwierdzenie po wysłaniu wiadomości.

    „DZIĘKUJEMY" BEZ NICZEGO WIĘCEJ JEST GORSZE NIŻ NIC — bo zostawia człowieka
    z pytaniem, czy w ogóle ma na co czekać. Ten ekran odpowiada na trzy rzeczy,
    w tej kolejności:

      1. czy to do nas doszło (nagłówek: „Mamy Twoją wiadomość"),
      2. na jaki adres przyjdzie odpowiedź,
      3. kiedy mniej więcej — bez obiecywania dyżuru, którego nie ma.

    ZDJĘTE ZAPEWNIENIA O IMPLEMENTACJI I TRWAŁOŚCI (audyt tekstów 11.09.2026).

    Stało tu „Zapisała się w Kuking — nie zginie, nawet gdyby akurat nie
    działała poczta." To prawda i nadal jest prawdą (wiadomość ląduje
    w tabeli, nie tylko w skrzynce), ale człowiekowi mówiło o NASZEJ
    architekturze, a nie o tym, co go dotyczy — a przy okazji podsuwało myśl,
    że poczta u nas bywa nieczynna. „Mamy Twoją wiadomość" w nagłówku mówi
    dokładnie to, po co ten ekran istnieje.

    Zdjęte też „Kuking prowadzi na razie jedna osoba, więc nie ma tu
    całodobowego dyżuru i nie będziemy go udawać". Liczba osób
    w redakcji jest POWODEM, a nie informacją dla czekającego; „nie będziemy
    go udawać" jest postawą. Zostaje to, co człowiek ma z tego wiedzieć: kto
    czyta i kiedy realnie przyjdzie odpowiedź.

    `noindex`, bo strona niesie adres e-mail podany przed chwilą.
--}}
<x-layout title="Wiadomość wysłana" :noindex="true">
    <h1>Mamy Twoją wiadomość</h1>

    <div class="sekcja-strony">
        @if($odpowiedzNa)
            <p class="mt-0 mb-0">
                Odpowiedź wyślemy na <strong>{{ $odpowiedzNa }}</strong>.
                Jeśli to nie jest adres, który czytasz, napisz do nas jeszcze raz —
                z tym właściwym.
            </p>
        @else
            {{-- Bez adresu nie ma jak odpisać i trzeba to powiedzieć wprost,
                 zamiast zostawiać człowieka w oczekiwaniu na odpowiedź, która
                 nigdy nie przyjdzie. --}}
            <p class="mt-0 mb-0">
                W formularzu nie było adresu e-mail, więc nie mamy jak odpisać —
                ale wiadomość przeczytamy. Jeśli chcesz odpowiedź,
                <a href="{{ route('kontakt') }}">napisz do nas jeszcze raz</a> i podaj adres.
            </p>
        @endif
    </div>

    <p>
        Czyta je {{ config('kuking.community.host_name') }}. Nie ma tu całodobowego
        dyżuru — czasem odpowiedź przyjdzie tego samego dnia, czasem po weekendzie.
    </p>

    <p>
        <a class="btn btn-quiet" href="{{ auth()->check() ? route('home') : route('landing') }}">
            Wróć do Kuking
        </a>
    </p>
</x-layout>
