<x-layout title="Zaloguj się" :noindex="true">
    <x-marka-wejscie opis="Twoja kuchnia, przepisy i ludzie, którzy naprawdę gotują.">
    <x-slot:naglowek><h1>Zaloguj się</h1></x-slot:naglowek>
    <x-slot:uzupelnienie>
        <x-wejscia-zewnetrzne />
        {{-- Równorzędna droga bez hasła pozostaje widoczna obok formularza. --}}
        @if(config('kuking.login_link.wlaczone'))
            <div class="sekcja-strony">
                <h2>Nie pamiętasz hasła? Nie musisz go wpisywać</h2>
                <p>
                    Wyślemy Ci wiadomość z linkiem. Otwórz go, a na stronie kliknij „Zaloguj mnie”.
                    Przy potwierdzonym adresie hasło zostaje bez zmian.
                    Jeśli adres konta nie był potwierdzony, wiadomość poprosi najpierw o ustawienie hasła.
                </p>
                <p class="form-actions">
                    <a class="btn btn-secondary" href="{{ route('login.link') }}">Wyślij mi link do zalogowania</a>
                </p>
            </div>
        @endif
        <p>Nie masz konta? <a href="{{ route('register') }}">Załóż konto</a>.</p>
        <p>Twoje konto zostało zablokowane albo zawieszone i uważasz, że to pomyłka?
            <a href="{{ route('appeals.guest') }}">Złóż odwołanie</a>.</p>
    </x-slot:uzupelnienie>
    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('login') }}">
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


    </x-marka-wejscie>
</x-layout>
