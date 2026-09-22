<x-layout title="Zaloguj mnie" :noindex="true">
    {{--
        EKRAN Z LINKU Z LISTU. NIC SIĘ TU JESZCZE NIE WYDARZYŁO — token
        zużywa dopiero POST z tego formularza.

        DLACZEGO NIE LOGUJEMY OD RAZU PO WEJŚCIU (D-056)
        Bo skanery odnośników w programach pocztowych i w bramkach
        antywirusowych otwierają każdy adres z listu, ZANIM zrobi to człowiek.
        Gdyby samo wejście logowało i kasowało token, właściciel konta
        dostawałby „ten link już nie działa" przy pierwszym kliknięciu.
        Skaner wykonuje GET, nie POST z tokenem CSRF — więc jedno kliknięcie
        więcej kosztuje sekundę, a ratuje całą drogę.

        Człowiek dostaje przy okazji rzecz, której nie miałby przy logowaniu
        natychmiastowym: WIDZI, NA JAKIE KONTO WCHODZI, zanim wejdzie.
        To jest ważne, bo z jednej skrzynki korzysta czasem całe małżeństwo.
    --}}
    <h1>Zaloguj mnie</h1>

    <p class="mb-5">
        Wszystko się zgadza. Kliknij przycisk poniżej, a wejdziesz na konto
        @if($displayName)
            <strong>{{ $displayName }}</strong>
        @endif
        ({{ $adresSkrot }}). Hasła nie trzeba wpisywać.
    </p>

    {{-- Ekran potwierdzenia, nie panel formularza: nie ma tu czego wypełniać,
         jest jeden przycisk zużywający token. --}}
    <form class="sekcja-strony" method="POST" action="{{ route('login.link.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zaloguj mnie</button>
            <a class="btn btn-quiet" href="{{ route('login') }}">To nie ja — wróć do logowania</a>
        </div>
    </form>

    <p class="notice mt-6">
        Ten link działa tylko raz. Po zalogowaniu nie można go użyć ponownie.
    </p>
</x-layout>
