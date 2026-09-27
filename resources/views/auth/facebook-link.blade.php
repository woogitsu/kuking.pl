<x-layout title="Połączyć to konto z Facebookiem?" :noindex="true">
    <h1>Połączyć to konto z Facebookiem?</h1>

    {{--
        TO JEST JEDYNA BEZPIECZNA DROGA POWIĄZANIA ISTNIEJĄCEGO KONTA
        Z FACEBOOKIEM (D-098) — i różni się od `auth/google-link` w rzeczy
        najważniejszej.

        Tam człowiek jest GOŚCIEM: rozpoznaliśmy go po adresie e-mail, który
        Google POTWIERDZIŁO, i prosimy o jedno kliknięcie, żeby połączenie
        nie było niespodzianką.

        Tutaj człowiek jest ZALOGOWANY, lecz przed dołączeniem nowej drogi
        wejścia ponownie potwierdza swoje konto Kuking. Facebook nie
        mówi, czy adres e-mail jest potwierdzony, więc rozpoznanie po adresie
        byłoby przejęciem konta na życzenie (wpisuję cudzy adres w swoim
        koncie na Facebooku i klikam „to moje konto").

        Dlatego ten ekran NIE POKAZUJE adresu e-mail z Facebooka i nie ma po
        co go pokazywać: nie służy on tu do niczego i nie zostanie nigdzie
        zapisany. Zapisujemy jeden identyfikator konta Facebooka, inny dla
        każdego serwisu.

        GET niczego nie zmienia. Powiązanie powstaje dopiero po POST poniżej.
    --}}
    <div class="sekcja-strony">
        <p>
            Jesteś na koncie <strong>{{ $displayName ?? 'to konto' }}</strong>.
            @if($imieZFacebooka !== '')
                Na Facebooku rozpoznajemy Cię jako <strong>{{ $imieZFacebooka }}</strong>.
            @endif
        </p>
        <p>
            Po połączeniu kont możesz korzystać z przycisku „Wejdź kontem Facebooka”.
            Facebook może poprosić o potwierdzenie. <strong>Twoje dotychczasowe sposoby logowania
            nadal będą działać.</strong> Nic w Twoim profilu, przepisach ani zeszytach
            się nie zmieni.
        </p>
        <p>
            Nie bierzemy z Facebooka zdjęcia, listy znajomych ani niczego o tym, co tam
            robisz — i nigdy nic nie napiszemy na Twojej tablicy.
        </p>

        <form method="POST" action="{{ route('facebook.link.store') }}">
            @csrf
            @if(!empty($proofToken))
                <input type="hidden" name="proof_token" value="{{ $proofToken }}">
                <p>Link potwierdzający działa tylko w tej przeglądarce i tylko przez 10 minut.</p>
            @else
                <label for="facebook-link-password">Hasło do Kuking</label>
                <input id="facebook-link-password" name="password" type="password" autocomplete="current-password">
                <p>Wpisz obecne hasło, żeby potwierdzić połączenie.</p>
            @endif
            @if($maDrugiSkladnik ?? false)
                <label for="facebook-link-code">Kod z aplikacji lub kod zapasowy</label>
                <input id="facebook-link-code" name="two_factor_code" type="text" autocomplete="one-time-code" required>
            @endif
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Połącz z Facebookiem</button>
                <a class="btn btn-quiet" href="{{ route('settings.security') }}">Nie teraz</a>
            </div>
        </form>
        @if(empty($proofToken) && ($adresPotwierdzony ?? false))
            <form method="POST" action="{{ route('facebook.link.email') }}">
                @csrf
                <p>Nie masz hasła do Kuking? Wyślemy link na Twój obecny, potwierdzony adres.</p>
                <button class="btn btn-secondary" type="submit">Wyślij link potwierdzający</button>
            </form>
        @elseif(empty($proofToken))
            <p>Jeśli nie masz hasła do Kuking, najpierw potwierdź swój adres e-mail w ustawieniach konta. Potem wróć tutaj po link potwierdzający.</p>
        @endif
    </div>

    {{--
        DROGA WYJŚCIA DLA KOGOŚ, KTO WIDZI TU NIE SWOJE IMIĘ Z FACEBOOKA.

        Z jednego telefonu korzysta czasem całe małżeństwo, a Facebook wchodzi
        kontem, które jest na nim zalogowane. Kto widzi nie siebie, ma się
        dowiedzieć, co zrobić, a nie zgadywać.
    --}}
    <p class="mt-6">
        To nie Twój Facebook? Nic nie klikaj — wróć do
        <a href="{{ route('settings.security') }}">ustawień bezpieczeństwa</a>
        i sprawdź na Facebooku, które konto jest na tym urządzeniu otwarte.
    </p>
</x-layout>
