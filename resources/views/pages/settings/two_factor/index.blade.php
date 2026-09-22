<x-layout title="Weryfikacja dwuetapowa" :noindex="true">
    <h1>Weryfikacja dwuetapowa</h1>

    <p class="mb-5">
        Dodaje do hasła drugi krok: kod z aplikacji w telefonie. Jeśli ktoś pozna Twoje hasło,
        samo hasło mu nie wystarczy, żeby się zalogować.
    </p>

    {{-- KOMUNIKATU ZWROTNEGO TU NIE MA I NIE MA BYĆ.

         Stało tu drugie wypisanie `session('status')`, a `x-layout` wypisuje
         je już jako `<p class="flash">` w `<div class="komunikaty"
         aria-live="polite">`. Po każdym `redirect()->with('status', …)`
         z `TwoFactorSettingsController` (m.in. „Weryfikacja dwuetapowa jest
         wyłączona.") ten sam tekst pokazywał się DWA RAZY, a czytnik ekranu
         ogłaszał go dwukrotnie — raz z `aria-live` layoutu, raz z własnego
         `role="status"`. Przy okazji rozdzielania ról powierzchni wyszło
         przy tym drugie: komunikat zwrotny nie jest ani kartą, ani
         wyjaśnieniem obok treści — ma własny wygląd (`.flash`) i własne
         miejsce. Test: `WarstwyPowierzchniTest`. --}}

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
            <p>Włączenie wymaga aplikacji uwierzytelniającej w telefonie
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
