<x-layout title="Wyślij mi link do zalogowania" :noindex="true">
    <h1>Wyślij mi link do zalogowania</h1>

    {{--
        DWA STANY TEGO EKRANU, BO SERWIS NIE ZAWSZE WYSYŁA POCZTĘ — dokładnie
        jak przy „Nie pamiętam hasła". Przy `MAIL_MAILER=log` Laravel zapisuje
        wiadomość do dziennika i zgłasza sukces, więc formularz, który
        przyjmuje adres i „wysyła", zostawiałby człowieka czekającego na list,
        który nigdy nie powstał. Pole, które nic nie robi, jest gorsze niż
        jego brak. `App\Support\Poczta::dziala()` pyta wprost o sterownik
        poczty, więc ten ekran nie może rozjechać się z rzeczywistością.
    --}}
    @if(\App\Support\Poczta::dziala())
        <p class="mb-5">
            Podaj adres e-mail, na który zakładasz konto. Wyślemy na niego wiadomość z jednym
            przyciskiem — po kliknięciu wejdziesz na swoje konto bez wpisywania hasła.
        </p>

        <x-error-summary />

        <form class="card" method="POST" action="{{ route('login.link.send') }}">
            @csrf
            <x-field name="email" label="Twój adres e-mail" type="email" required autocomplete="email"
                     help="Ten sam, na który przychodzą wiadomości z Kuking." />
            <x-turnstile miejsce="logowanie_linkiem" />

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Wyślij mi link</button>
                <a class="btn btn-quiet" href="{{ route('login') }}">Wolę wpisać hasło</a>
            </div>
        </form>

        <p class="notice mt-6">
            <strong>Co się stanie dalej?</strong> Przyjdzie wiadomość „Twój link do zalogowania w Kuking”.
            Otwórz ją i kliknij zielony przycisk. Możesz to zrobić na telefonie, nawet jeśli link zamawiasz
            o link na komputerze. Link działa przez pół godziny i tylko raz.
        </p>

        <p class="mt-5">
            <strong>Wiadomość nie przychodzi?</strong> Sprawdź folder „Spam” albo „Oferty”.
            Jeśli nadal nic nie ma, napisz do nas: {{ config('kuking.community.contact_email') }}
        </p>

        {{--
            TO ZDANIE STOI TU DLA MODERATORÓW I ADMINISTRATORÓW — i jest jedynym
            sposobem, żeby im o tym powiedzieć, nie zdradzając nikomu, kto nim
            jest. Ich kontom linku nie wysyłamy (issue #25), a odpowiedź
            formularza musi wyglądać dla wszystkich tak samo, więc bez tego
            zdania osoba z obsługi serwisu czekałaby na list, który z założenia
            nie przyjdzie.
        --}}
        <p class="mt-5">
            Kontom obsługi serwisu — moderatorom i administratorom — linku nie wysyłamy.
            Tam obowiązuje hasło i weryfikacja dwuetapowa.
        </p>
    @else
        <p class="notice">
            {{ \App\Support\Poczta::komunikatBrakuPoczty('link do zalogowania') }}
        </p>

        <p class="mt-5">
            <a class="btn btn-secondary" href="mailto:{{ config('kuking.community.contact_email') }}?subject={{ rawurlencode('Nie mogę się zalogować') }}">
                Napisz do nas w sprawie logowania
            </a>
            <a class="btn btn-quiet" href="{{ route('login') }}">Wróć do logowania</a>
        </p>
    @endif
</x-layout>
