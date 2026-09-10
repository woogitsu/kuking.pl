<x-layout title="Zaloguj się" :noindex="true">
    <h1>Zaloguj się</h1>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('login') }}">
        @csrf

        <x-field name="login" label="Adres e-mail albo nazwa użytkownika" required
                 autocomplete="username"
                 help="Możesz wpisać jedno albo drugie — obojętnie które." />

        <x-field name="password" label="Hasło" type="password" required autocomplete="current-password" />

        <x-turnstile miejsce="logowanie" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zaloguj się</button>
            <a class="btn btn-quiet" href="{{ route('password.request') }}">Nie pamiętam hasła</a>
        </div>
    </form>

    {{--
        WEJŚCIE KONTEM GOOGLE (issue #258, D-069) — TRZECIA droga, dodatkowa.

        Stoi POD hasłem i NAD linkiem e-mail, a nie na dole strony: dla osób,
        które przyjdą z kampanii na Facebooku z telefonu z Androidem, to jest
        najkrótsza droga, jaka istnieje (konto Google jest tam już
        zalogowane). Nie stoi jednak PIERWSZA, bo część naszych ludzi ma
        adresy `@wp.pl` i `@o2.pl`, gdzie konta Google nie ma — i dla nich
        pierwszą rzeczą na ekranie ma zostać to, co znają.

        Cały blok znika bez kluczy Google — patrz komponent. Martwego
        przycisku nie zostawiamy nigdzie (D-053).
    --}}
    <x-wejdz-google />

    {{--
        DRUGA, RÓWNORZĘDNA DROGA WEJŚCIA — LOGOWANIE LINKIEM (issue #25, D-056).

        Stoi TU, zaraz pod formularzem hasła, a nie pod „innymi opcjami"
        i nie na dole strony. To jest wymóg z issue: dla dużej części naszych
        ludzi (`docs/research/AUDIENCE_50_PLUS.md` — 12,3% osób 65-74 ma
        podstawowe umiejętności cyfrowe) hasło jest murem, a link jest drogą
        PODSTAWOWĄ. Schowana droga podstawowa przestaje być drogą.

        Cały blok znika, gdy `KUKING_LOGOWANIE_LINKIEM=false` — to jest
        wycofanie funkcji bez wdrażania migracji. Martwego przycisku nie
        zostawiamy nigdzie (D-053): jak nie ma drogi, nie ma i wejścia do niej.
    --}}
    @if(config('kuking.login_link.wlaczone'))
        <div class="card mt-6">
            <h2>Nie pamiętasz hasła? Nie musisz go wpisywać</h2>
            <p>
                Wyślemy Ci wiadomość z jednym przyciskiem. Klikasz — i jesteś w środku.
                Hasło zostaje takie, jakie było; możesz go używać dalej, kiedy zechcesz.
            </p>
            <p class="form-actions">
                <a class="btn btn-secondary" href="{{ route('login.link') }}">Wyślij mi link do zalogowania</a>
            </p>
        </div>
    @endif

    <p class="mt-6">Nie masz konta? <a href="{{ route('register') }}">Załóż konto</a>.</p>

    {{--
        Droga odwoławcza dla osoby, której konto zamknięto (issue #10, DSA art. 20).

        Musi być TUTAJ, bo ekran logowania to jedyne miejsce w serwisie, które
        taka osoba zobaczy — komunikat o blokadzie wyświetla się dokładnie nad
        tym formularzem (LoginController). Link jest widoczny zawsze, bez
        warunków: warunek wymagałby wiedzy, kto próbuje się zalogować, a tej
        nie mamy, dopóki ktoś nie wyśle formularza.
    --}}
    <p>Twoje konto zostało zablokowane albo zawieszone i uważasz, że to pomyłka?
        <a href="{{ route('appeals.guest') }}">Złóż odwołanie</a>.</p>
</x-layout>
