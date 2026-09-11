<x-layout title="Połączyć to konto z Facebookiem?" :noindex="true">
    <h1>Połączyć to konto z Facebookiem?</h1>

    {{--
        TO JEST JEDYNA BEZPIECZNA DROGA POWIĄZANIA ISTNIEJĄCEGO KONTA
        Z FACEBOOKIEM (D-098) — i różni się od `auth/google-link` w rzeczy
        najważniejszej.

        Tam człowiek jest GOŚCIEM: rozpoznaliśmy go po adresie e-mail, który
        Google POTWIERDZIŁO, i prosimy o jedno kliknięcie, żeby połączenie
        nie było niespodzianką.

        Tutaj człowiek jest ZALOGOWANY — i to jest cały dowód. Facebook nie
        mówi, czy adres e-mail jest potwierdzony, więc rozpoznanie po adresie
        byłoby przejęciem konta na życzenie (wpisuję cudzy adres w swoim
        koncie na Facebooku i klikam „to moje konto"). Dowodem nie może być
        twierdzenie, tylko czynność: wejście na konto hasłem albo linkiem
        z wiadomości.

        Dlatego ten ekran NIE POKAZUJE adresu e-mail z Facebooka i nie ma po
        co go pokazywać: nie służy on tu do niczego i nie zostanie nigdzie
        zapisany. Zapisujemy jeden identyfikator konta Facebooka, inny dla
        każdego serwisu.

        GET niczego nie zmienia. Powiązanie powstaje dopiero po POST poniżej.
    --}}
    <div class="card">
        <p>
            Jesteś na koncie <strong>{{ $displayName ?? 'to konto' }}</strong>.
            @if($imieZFacebooka !== '')
                Na Facebooku rozpoznajemy Cię jako <strong>{{ $imieZFacebooka }}</strong>.
            @endif
        </p>
        <p>
            Jeśli połączysz to konto z Facebookiem, od następnego razu wejdziesz tu jednym
            kliknięciem — bez wpisywania hasła. <strong>Twoje hasło zostanie takie, jakie
            było, i nadal będzie działać.</strong> Nic w Twoim profilu, przepisach ani
            zeszytach się nie zmieni.
        </p>
        <p>
            Nie bierzemy z Facebooka zdjęcia, listy znajomych ani niczego o tym, co tam
            robisz — i nigdy nic nie napiszemy na Twojej tablicy.
        </p>

        <form method="POST" action="{{ route('facebook.link.store') }}">
            @csrf
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Połącz z Facebookiem</button>
                <a class="btn btn-quiet" href="{{ route('settings.security') }}">Nie teraz</a>
            </div>
        </form>
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
