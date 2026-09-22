<x-layout :title="$naglowek" :noindex="true">
    {{--
        JEDEN EKRAN DLA CZTERECH SYTUACJI: link wygasł, link został już użyty,
        takiego linku nigdy nie było, logowanie linkiem jest wyłączone.

        Trzy pierwsze wyglądają identycznie ŚWIADOMIE. Gdyby różniły się choćby
        kodem odpowiedzi, zgadywanie tokenów dostałoby wyrocznię „ten istniał,
        tamten nie". Dla człowieka i tak nie ma między nimi różnicy: w każdym
        z tych przypadków ma zrobić dokładnie to samo.

        Ekran ZAWSZE kończy się drogą dalej (docs/UX_50_PLUS.md): odmowa bez
        następnego kroku jest ślepą ścianą, a osoba, która właśnie nie weszła
        na swoje konto, jest dokładnie tą, której nie wolno tam zostawić.
    --}}
    <h1>{{ $naglowek }}</h1>

    <p class="notice">{{ $tresc }}</p>

    <p class="mt-6">
        @if($mozeProsicONowy)
            <a class="btn btn-primary" href="{{ route('login.link') }}">Wyślij mi nowy link</a>
        @endif
        <a class="btn btn-secondary" href="{{ route('login') }}">Zaloguj się hasłem</a>
    </p>

    <p class="mt-5">
        Nie pamiętasz hasła? <a href="{{ route('password.request') }}">Ustaw nowe</a>.
        Jeśli nadal nie możesz się zalogować, napisz do nas: {{ config('kuking.community.contact_email') }}.
    </p>
</x-layout>
