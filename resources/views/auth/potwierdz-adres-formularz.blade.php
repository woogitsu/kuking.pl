{{--
    Ekran pośredni z linku potwierdzającego adres e-mail (issue #1862).

    NIC SIĘ TU JESZCZE NIE WYDARZYŁO — samo wyświetlenie tej strony (GET
    z linku w mailu) niczego w koncie nie zmienia. Adres potwierdza dopiero
    POST z przycisku niżej, z CSRF.

    DLACZEGO NIE POTWIERDZAMY OD RAZU PO WEJŚCIU — ten sam powód co przy
    `auth.login-link-confirm` (D-056): skaner odnośników w bramce
    antywirusowej i podgląd/prefetch w kliencie pocztowym otwierają każdy
    link z listu, ZANIM zrobi to człowiek, samym żądaniem GET. Gdyby samo
    wejście potwierdzało adres, konto wyglądałoby na potwierdzone, choć
    właściciel nigdy świadomie nie kliknął.

    Formularz POST-uje na `$potwierdzUrl`, czyli na TEN SAM podpisany adres,
    z którego przyszło to żądanie (`$request->fullUrl()`) — podpis i termin
    ważności linku z maila zostają bez zmian, więc jeden link z listu
    wystarcza na obie czynności: otwarcie strony i potwierdzenie.
--}}
<x-layout title="Potwierdź adres e-mail" :noindex="true">
    <h1>Potwierdź swój adres e-mail</h1>

    <p class="mb-5">
        Kliknij przycisk poniżej, żeby potwierdzić, że adres
        <strong>{{ $email }}</strong> należy do Ciebie.
    </p>

    <form class="sekcja-strony" method="POST" action="{{ $potwierdzUrl }}">
        @csrf

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Potwierdź adres e-mail</button>
            <a class="btn btn-quiet" href="{{ route('home') }}">To nie teraz — wróć do <x-kuking-word /></a>
        </div>
    </form>

    <noscript>
        <p class="notice mt-6">
            Ten formularz działa bez JavaScriptu — wystarczy kliknięcie przycisku
            „Potwierdź adres e-mail” powyżej.
        </p>
    </noscript>
</x-layout>
