<x-layout title="Weryfikacja dwuetapowa" :noindex="true">
    <h1>Weryfikacja dwuetapowa</h1>

    <p class="mb-5">
        Dodaje do hasła drugi krok: kod z aplikacji w telefonie. Jeśli ktoś pozna Twoje hasło,
        samo hasło mu nie wystarczy, żeby się zalogować.
    </p>

    @if(session('status'))
        <p class="ramka-pomocnicza mb-5" role="status">{{ session('status') }}</p>
    @endif

    @if($wlaczone)
        <section class="sekcja-strony">
            <h2 class="mt-0">Włączona</h2>
            <p>Przy logowaniu, oprócz hasła, poprosimy Cię o kod z aplikacji uwierzytelniającej.</p>

            {{--
                NOWE KODY ZAPASOWE STOJĄ WYŻEJ NIŻ „WYŁĄCZ" I TO NIE JEST PRZYPADEK.

                Kody pokazujemy raz. Kto ich nie zapisał albo zgubił kartkę,
                miał dotąd jedną drogę do nowych: zdjąć 2FA i włączyć od zera.
                To znaczy trzy złe rzeczy naraz — konto zostaje przez chwilę
                na samym haśle, moderator traci w tym czasie wejście do panelu,
                a sekret trzeba przepisać do telefonu jeszcze raz, choć z nim
                nic nie było nie tak.

                Skoro poczta jeszcze nie działa, a utrata telefonu razem
                z kodami zamyka konto do czasu wejścia na serwer, droga do
                nowych kodów musi być łatwa, dopóki człowiek ma jeszcze dostęp.
            --}}
            <details class="mt-5">
                <summary class="btn btn-secondary inline-flex">Wygeneruj nowe kody zapasowe</summary>
                <div class="mt-4">
                    <p>
                        Nowy komplet ośmiu kodów. <strong>Stare kody przestaną wtedy działać</strong> —
                        o to właśnie chodzi, jeśli nie wiesz, gdzie jest kartka z poprzednimi.
                        Aplikacja w telefonie działa dalej bez zmian, nie musisz nic w niej przestawiać.
                    </p>
                    <form method="POST" action="{{ route('settings.two_factor.regenerate') }}">
                        @csrf
                        <x-field name="password" label="Wpisz swoje hasło" type="password" required
                                 autocomplete="current-password"
                                 help="Pytamy o hasło, żeby mieć pewność, że to naprawdę Ty." />
                        <button class="btn btn-secondary mt-4" type="submit">Wygeneruj nowe kody</button>
                    </form>
                </div>
            </details>

            <details class="mt-5">
                <summary class="btn btn-secondary inline-flex">Wyłącz weryfikację dwuetapową</summary>
                <div class="mt-4">
                    <x-error-summary />
                    <form method="POST" action="{{ route('settings.two_factor.disable') }}">
                        @csrf
                        <x-field name="password" label="Wpisz swoje hasło" type="password" required
                                 autocomplete="current-password"
                                 help="Pytamy o hasło, żeby mieć pewność, że to naprawdę Ty." />
                        <button class="btn btn-danger mt-4" type="submit">Wyłącz</button>
                    </form>
                </div>
            </details>
        </section>
    @else
        <section class="sekcja-strony">
            <h2 class="mt-0">Wyłączona</h2>
            <p>Włączenie zajmuje mniej niż dwie minuty i wymaga aplikacji uwierzytelniającej w telefonie
                (na przykład Google Authenticator, Aegis albo 1Password).</p>
            <a class="btn btn-primary" href="{{ route('settings.two_factor.enable') }}">Włącz weryfikację dwuetapową</a>
        </section>
    @endif

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="two_factor" />
    </x-slot:rail>
</x-layout>
