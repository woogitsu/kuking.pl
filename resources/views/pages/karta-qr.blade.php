{{--
    „KARTA Z KODEM QR” (#2349, F10 z researchu z 30 września 2026).

    Kartka A4 do rozdawania (KGW, UTW, rodzina): tytuł publicznego przepisu
    albo nazwa publicznego profilu, duży kod QR i TEN SAM adres zapisany
    tekstem — kto nie ma skanera, przepisze go z kartki. Na ekranie widać
    wstęp i przycisk „Wydrukuj”; na papier idzie sama `.karta-qr`.

    Rama kartki (biały papier, czarny tekst, bez belek i przycisków) jest
    wspólna ze ściągawką i przepisem: `resources/css/wydruk-przepisu.css`.
    Klasa `.sciagawka` dokłada duże pismo (16 pt, tytuł 26 pt, razy skala
    tekstu), `.karta-qr` — tylko to, czego ściągawka nie ma.

    W KODZIE JEST WYŁĄCZNIE PUBLICZNY ADRES ($adres z `KartaZKodemQr`):
    żadnego tokenu, sesji ani danych osoby drukującej. Uzasadnienie
    w `App\Domain\Sharing\KartaZKodemQr`.

    „Wydrukuj” działa jak „Drukuj przepis”: zwykły odnośnik z `?druk=1`,
    który `drukuj-przepis.js` zamienia w `window.print()`, a bez skryptu
    prowadzi do zdania, co nacisnąć (D-053). Strona ma `noindex`: to
    kartka do papieru, nie treść dla wyszukiwarki.
--}}
<x-layout :title="'Karta z kodem QR: '.$tytul" :noindex="true">
    <div class="druk-podpowiedz">
        <h1>Karta z kodem QR</h1>
        <p class="mb-3">
            Jedna kartka dużym drukiem z kodem do zeskanowania telefonem.
            Da się ją rozdać na zajęciach albo wysłać rodzinie —
            osoba, która ją dostanie, otworzy {{ $rodzaj === 'przepis' ? 'ten przepis' : 'ten profil' }} bez zakładania konta.
        </p>
        <p class="mb-3"><strong>W kodzie jest tylko publiczny adres</strong> — nic, co dotyczy Ciebie.</p>
        <div class="form-actions">
            <a class="btn btn-primary" href="{{ $adresKarty }}?druk=1#jak-wydrukowac" rel="nofollow" data-drukuj-przepis>Wydrukuj kartę</a>
            <a class="btn btn-quiet" href="{{ $wrocUrl }}">{{ $rodzaj === 'przepis' ? 'Wróć do przepisu' : 'Wróć do profilu' }}</a>
        </div>
        @if(request()->boolean('druk'))
            <div class="notice mt-4" id="jak-wydrukowac" role="status">
                <p class="m-0"><strong>Jak wydrukować tę kartkę:</strong></p>
                <p class="m-0">Na komputerze naciśnij razem klawisze <kbd>Ctrl</kbd> i <kbd>P</kbd> (na komputerze Apple: <kbd>Cmd</kbd> i <kbd>P</kbd>).</p>
                <p class="m-0">Na telefonie otwórz menu przeglądarki (trzy kropki albo „Udostępnij”) i wybierz „Drukuj”.</p>
            </div>
        @endif
        <p class="mt-6 mb-3">Tak będzie wyglądać kartka:</p>
    </div>

    <article class="sciagawka karta-qr sekcja-strony" aria-labelledby="karta-qr-tytul">
        <p class="karta-qr-marka"><x-kuking-word /></p>
        <h2 id="karta-qr-tytul">{{ $tytul }}</h2>
        <p class="karta-qr-opis">
            {{ $rodzaj === 'przepis'
                ? 'Ten przepis możesz przeczytać w telefonie. Zeskanuj kod aparatem albo wpisz adres poniżej.'
                : 'Tę stronę możesz obejrzeć w telefonie. Zeskanuj kod aparatem albo wpisz adres poniżej.' }}
        </p>

        <div class="karta-qr-kod" role="img" aria-label="Kod QR z adresem: {{ $adres }}">
            {!! $kodSvg !!}
        </div>

        <p class="karta-qr-adres-podpis">Albo wpisz w przeglądarce:</p>
        <p class="karta-qr-adres">{{ $adres }}</p>

        <p class="karta-qr-uwaga"><strong>Konto nie jest potrzebne, żeby to przeczytać.</strong></p>
    </article>
</x-layout>
