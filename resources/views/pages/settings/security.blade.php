<x-layout title="Bezpieczeństwo" :noindex="true">
    <h1>Bezpieczeństwo konta</h1>

    <x-error-summary />

    <section class="panel-formularza">
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
                     help="Co najmniej 10 znaków. Najprościej połączyć myślnikami trzy swoje słowa, na przykład: parasol-wtorek-cebula. Wymyśl własne, nie przepisuj tych z przykładu." />

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

    <section class="panel-formularza mt-8">
        <h2 class="mt-0">Wyloguj mnie z innych urządzeń</h2>
        <p>
            Użyj tego, jeśli konto zostało zalogowane na cudzym telefonie albo komputerze —
            na przykład u rodziny czy znajomych — i nie masz jak się tam
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

    {{--
        WEJŚCIE KONTEM FACEBOOKA — „POŁĄCZ", BEZ „ODŁĄCZ" (issue #259, D-098).

        ═══ DLACZEGO TA SEKCJA MUSI TU BYĆ, A NIE „MOŻE" ═══

        Facebook nie mówi, czy adres e-mail jest potwierdzony, więc adres
        z Facebooka NIE MOŻE łączyć się z istniejącym kontem (D-098) — inaczej
        wystarczyłoby wpisać cudzy adres w swoim koncie na Facebooku i kliknąć
        u nas „to moje konto". To zamyka atak, ale zostawia pytanie: jak ma
        połączyć konto z Facebookiem człowiek, który konto w Kuking już ma?

        Jedyna bezpieczna odpowiedź to ta: prosi o to, będąc JUŻ ZALOGOWANY —
        czyli dowodzi, że konto jest jego, czynnością, a nie twierdzeniem.
        Ten przycisk jest tą drogą. Bez niego odmowa „na ten adres jest już
        konto" byłaby ślepym zaułkiem, a wejście kontem Facebooka działałoby
        wyłącznie dla kont zakładanych od zera.

        ═══ DLACZEGO NIE MA TU „ODŁĄCZ" ═══

        D-069 ostrzega wprost: odłączenie konta, które NIE MA innej drogi
        wejścia (nie ustawiło hasła), zamyka człowiekowi drzwi jednym
        kliknięciem. Zrobienie tego dobrze wymaga sprawdzenia, czy zostaje
        hasło albo potwierdzony adres, i ewentualnej odmowy — czyli osobnej
        decyzji i osobnych testów. Dziś rozłączenie robimy na prośbę wysłaną
        na adres kontaktowy (tak mówi polityka prywatności) i to jest
        świadome odłożenie, nie przeoczenie. „Połącz" nie ma tego problemu
        w żadną stronę: dokłada drogę wejścia, nie zabiera żadnej.

        ═══ BEZ JAVASCRIPTU I BEZ MARTWEGO PRZYCISKU ═══

        Zwykły odnośnik `.btn` (48 px, ten sam rozmiar tekstu co reszta
        ekranu), a cała droga to przekierowania po stronie serwera. Gdy
        Facebook nie jest skonfigurowany albo droga jest wyłączona
        (`KUKING_WEJSCIE_FACEBOOK=false`), tej sekcji NIE MA na ekranie
        w ogóle — pyta o to `App\Support\Facebook::dziala()`, to samo
        miejsce, o które pyta kontroler i rząd przycisków na logowaniu.
    --}}
    @if(\App\Support\Facebook::dziala())
        {{-- SEKCJA, nie ramka pomocnicza — ta sama zasada co przy logowaniu
             linkiem na `/login` (D-056). D-113 czyni ją tu mocniejszą: człowiek,
             który ma już konto w Kuking, NIE wejdzie na nie kontem Facebooka,
             dopóki sam nie połączy kont z tego ekranu, a list kierujący go
             tutaj mówi wprost „połącz konta w Ustawienia → Bezpieczeństwo".
             Wgłębienie mówiłoby „to jest obok" o jedynej drodze do celu. --}}
        <section class="sekcja-strony mt-8">
            <h2 class="mt-0">Wejście kontem Facebooka</h2>

            {{--
                TRZECI STAN: POWIĄZANIE JEST, ALE UŚPIONE (issue #259).

                Facebook przysłał nam powiadomienie, że ten człowiek odebrał
                naszej aplikacji dostęp w swoich ustawieniach Facebooka
                (`FacebookDeauthorizeController`). Wiersza powiązania NIE
                KASUJEMY — kto nie ma hasła, straciłby jedyną drogę wejścia —
                więc bez tego stanu ekran pokazywałby mu „połączone" i kłamał.

                Zdanie mówi, CO ZROBIĆ, nie samo „stan: odebrany". Przycisk
                prowadzi na `facebook.start`, czyli na prawdziwy ekran zgody
                Facebooka, po którym znacznik gaśnie przy wejściu (D-053:
                żadnego martwego przycisku).

                Kolejność gałęzi ma znaczenie: stan uśpiony musi być sprawdzony
                PRZED „połączone", bo `hasFacebookConnected()` jest prawdziwe
                także wtedy — powiązanie wciąż istnieje.
            --}}
            @if(auth()->user()->dostepOdebranyU(\App\Models\TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK))
                <p>
                    <strong>Facebook przestał nas wpuszczać na Twoje konto.</strong>
                    Stało się to, bo w ustawieniach Facebooka usunięto zgodę dla Kuking —
                    zwykle robi to sam właściciel konta, porządkując listę aplikacji.
                    Nic Ci przez to nie przepadło: Twoje konto w Kuking, wpisy i zdjęcia
                    są nietknięte.
                </p>
                <p>
                    Żeby znów wchodzić kontem Facebooka, kliknij poniżej i potwierdź zgodę
                    jeszcze raz. Jeśli wolisz zostać przy haśle — nie rób nic; hasło działa
                    tak samo jak wcześniej.
                </p>
                <div class="form-actions">
                    <a class="btn btn-secondary" href="{{ route('facebook.start') }}">
                        <x-logo-dostawcy nazwa="facebook" />
                        Połącz konto Facebooka jeszcze raz
                    </a>
                </div>
            @elseif(auth()->user()->hasFacebookConnected())
                <p>
                    To konto jest <strong>połączone z Twoim kontem Facebooka</strong> —
                    możesz logować się przyciskiem „Wejdź kontem Facebooka"
                    na stronie logowania. Twoje hasło działa dalej tak samo.
                </p>
                <p>
                    Chcesz to rozłączyć? Napisz do nas na
                    <strong>{{ config('kuking.community.contact_email') }}</strong> —
                    odpisuje człowiek. Sprawdzimy przy tym, czy zostaje Ci inna droga
                    wejścia na konto, żeby nie zostać bez dostępu.
                </p>
            @else
                <p>
                    Jeśli połączysz to konto ze swoim kontem Facebooka, następnym razem
                    możesz logować się przyciskiem „Wejdź kontem Facebooka”, bez wpisywania hasła do Kuking.
                    Hasło zostanie takie, jakie jest, i nadal będzie działać.
                </p>
                <p>
                    Nie bierzemy z Facebooka zdjęcia, listy znajomych ani niczego o tym, co
                    tam robisz — i nigdy nic nie napiszemy na Twojej tablicy. Przeniesiemy
                    Cię na stronę Facebooka, tam potwierdzisz, że to Ty, i wrócisz tutaj.
                </p>
                <div class="form-actions">
                    <a class="btn btn-secondary" href="{{ route('facebook.start') }}">
                        <x-logo-dostawcy nazwa="facebook" />
                        Połącz konto Facebooka
                    </a>
                </div>
            @endif
        </section>
    @endif

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="security" />
    </x-slot:rail>
</x-layout>
