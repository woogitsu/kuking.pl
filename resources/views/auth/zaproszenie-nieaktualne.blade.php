<x-layout :title="$naglowek" :noindex="true">
    {{--
        JEDEN EKRAN DLA CZTERECH SYTUACJI: zaproszenie wygasło, zostało już
        użyte, takiego nigdy nie było, zakładanie konta z zaproszenia jest
        wyłączone (albo rejestracja jest zamknięta).

        Trzy pierwsze wyglądają identycznie ŚWIADOMIE — gdyby różniły się
        choćby kodem odpowiedzi, zgadywanie tokenów dostałoby wyrocznię „ten
        istniał, tamten nie". Dla człowieka i tak nie ma między nimi różnicy:
        w każdym z tych przypadków ma zrobić dokładnie to samo. Ta sama zasada
        co na `auth/login-link-unavailable`.

        Ekran ZAWSZE kończy się drogą dalej (docs/UX_50_PLUS.md): odmowa bez
        następnego kroku jest ślepą ścianą, a osoba, która właśnie nie zdążyła
        założyć konta, jest dokładnie tą, której nie wolno tam zostawić.
    --}}
    <h1>{{ $naglowek }}</h1>

    <p class="notice">{{ $tresc }}</p>

    <p class="mt-6">
        @if($mozeZalozycKonto)
            <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto</a>
        @endif
        <a class="btn btn-secondary" href="{{ route('login') }}">Mam już konto — zaloguj mnie</a>
    </p>

    <p class="mt-5">
        Coś tu nie gra? Napisz do nas: {{ config('kuking.community.contact_email') }} —
        odpisuje człowiek.
    </p>
</x-layout>
