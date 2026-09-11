<x-layout title="Załóż konto w Kuking" :noindex="true">
    {{--
        EKRAN Z LINKU W WIADOMOŚCI (D-085). NIC SIĘ TU JESZCZE NIE WYDARZYŁO
        — samo wejście pod ten adres niczego nie zapisuje i niczego nie zużywa.

        DLACZEGO NIE PRZENOSIMY OD RAZU NA FORMULARZ
        Bo skanery odnośników w programach pocztowych i w bramkach
        antywirusowych otwierają każdy adres z wiadomości, ZANIM zrobi to
        człowiek — metodą GET. Ta sama lekcja co przy logowaniu linkiem
        (D-056). Jedno kliknięcie więcej kosztuje sekundę, a ratuje całą drogę.

        Człowiek dostaje przy okazji rzecz, której nie miałby przy przeniesieniu
        natychmiastowym: WIDZI, NA JAKI ADRES zakłada konto, zanim zacznie
        cokolwiek wpisywać. Z jednej skrzynki korzysta czasem całe małżeństwo.
    --}}
    <h1>Załóż konto w Kuking</h1>

    <p class="mb-5">
        Na adres <strong>{{ $adres }}</strong> nie ma jeszcze konta w Kuking.
        Kliknij przycisk poniżej, a przejdziesz do krótkiego formularza — ten adres będzie
        już w nim wpisany i potwierdzony, więc zostanie imię, nazwa użytkownika i hasło.
    </p>

    <form class="sekcja-strony" method="POST" action="{{ route('zaproszenie.przyjmij') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Załóż konto</button>
            <a class="btn btn-quiet" href="{{ route('login') }}">Mam już konto — chcę się zalogować</a>
        </div>
    </form>

    <p class="notice mt-6">
        Adresu e-mail nie będziemy potwierdzać drugi raz. Kliknięcie w wiadomość z Twojej skrzynki
        wystarcza, więc żadna kolejna wiadomość od nas nie musi już przyjść.
    </p>
</x-layout>
