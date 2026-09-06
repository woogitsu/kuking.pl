<x-layout title="Zaloguj się" :noindex="true">
    <h1>Zaloguj się</h1>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('login') }}">
        @csrf

        <x-field name="login" label="Adres e-mail albo nazwa użytkownika" required
                 autocomplete="username"
                 help="Możesz wpisać jedno albo drugie — obojętnie które." />

        <x-field name="password" label="Hasło" type="password" required autocomplete="current-password" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zaloguj się</button>
            <a class="btn btn-quiet" href="{{ route('password.request') }}">Nie pamiętam hasła</a>
        </div>
    </form>

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
