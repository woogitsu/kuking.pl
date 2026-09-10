<x-layout title="Bezpieczeństwo" :noindex="true">
    <h1>Bezpieczeństwo konta</h1>

    <x-error-summary />

    <section class="card">
        <h2 class="mt-0">Zmień hasło</h2>
        <p>
            Zmień hasło, jeśli podejrzewasz, że ktoś inny je zna — na przykład je zgadł
            albo zobaczył, jak je wpisujesz.
        </p>

        <form method="POST" action="{{ route('settings.security.password') }}">
            @csrf @method('PUT')

            <x-field name="current_password" label="Obecne hasło" type="password" required
                     autocomplete="current-password" />

            <x-field name="password" label="Nowe hasło" type="password" required
                     autocomplete="new-password"
                     help="Co najmniej 10 znaków. Najprościej wpisać trzy słowa, na przykład: zielonapietruszkarano." />

            <x-field name="password_confirmation" label="Powtórz nowe hasło" type="password" required
                     autocomplete="new-password" />

            <p class="field-help mt-3">
                Po zmianie hasła wylogujemy wszystkie inne urządzenia zalogowane na to konto.
                Ten komputer/telefon zostaje zalogowany.
            </p>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Zmień hasło</button>
            </div>
        </form>
    </section>

    <section class="card mt-8">
        <h2 class="mt-0">Wyloguj mnie z innych urządzeń</h2>
        <p>
            Użyj tego, jeśli konto zostało zalogowane na cudzym telefonie albo komputerze —
            na przykład u wnuka, w bibliotece albo u znajomych — i nie masz jak się tam
            już wylogować.
        </p>
        <p>
            Wszystkie urządzenia zalogowane na to konto, <strong>oprócz tego, na którym
            teraz jesteś</strong>, zostaną wylogowane od razu.
        </p>

        <form method="POST" action="{{ route('settings.security.logout-others') }}">
            @csrf

            {{-- `id` JAWNIE, bo wyżej na tej samej stronie stoi drugie pole
                 `name="password"` („Nowe hasło" w formularzu zmiany hasła).
                 Bez tego oba miały `id="f-password"`, a kliknięcie tej
                 etykiety przenosiło fokus do tamtego formularza. --}}
            <x-field name="password" id="f-wyloguj-inne-haslo" label="Wpisz swoje hasło" type="password" required
                     autocomplete="current-password"
                     help="Pytamy o hasło, żeby mieć pewność, że to naprawdę Ty." />

            <div class="form-actions">
                <button class="btn btn-secondary" type="submit">Wyloguj inne urządzenia</button>
            </div>
        </form>
    </section>

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="security" />
    </x-slot:rail>
</x-layout>
