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
        {{--
            TEN EKRAN JEST DLA OSÓB, KTÓRE JUŻ MAJĄ KONTO — I MUSI TO POWIEDZIEĆ.

            Poprzednie zdanie brzmiało „Podaj adres e-mail, NA KTÓRY ZAKŁADASZ
            KONTO" i czytało się jak formularz rejestracji. 63-letnia osoba
            z grupy docelowej weszła tędy, wpisała swój adres, dostała zielone
            „wysłaliśmy…" i czekała na wiadomość, która nigdy nie miała przyjść:
            konta pod tym adresem nie było, a logowanie linkiem świadomie
            odpowiada tak samo dla adresu z kontem i bez konta (D-056), żeby
            nie zdradzać, kto ma konto w Kuking.

            Ta prywatność zostaje. Naprawiamy dwie inne rzeczy: ekran mówi
            teraz, dla kogo jest, i ma widoczną drogę do rejestracji — żeby
            człowiek, który trafił tu przez pomyłkę, nie został z pustą
            skrzynką i bez wyjścia.
        --}}
        <p class="mb-5">
            Ten ekran jest dla osób, które <strong>już mają konto w Kuking</strong>.
            Podaj adres e-mail z tego konta — wyślemy na niego
            wiadomość z jednym przyciskiem, a po kliknięciu wejdziesz na konto bez
            wpisywania hasła.
        </p>

        <p class="notice mb-5">
            {{-- BEZ „zajmuje minutę": to obietnica z miarą, której nikt nie
                 mierzy. Skreślona, a nie zastąpiona inną liczbą — informacja,
                 dla której to zdanie tu stoi, brzmi „to nie jest ten formularz,
                 w którym jesteś", i ta zostaje w całości. --}}
            Nie masz jeszcze konta? <a href="{{ route('register') }}">Załóż konto</a> —
            to inny formularz.
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
            Otwórz ją i kliknij przycisk „Zaloguj mnie w Kuking”. Możesz to zrobić na telefonie, nawet jeśli o link
            prosisz z komputera. Link działa przez pół godziny i tylko raz.
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
