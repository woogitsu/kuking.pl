{{--
    „Adres e-mail" — ekran ustawień z issue #195.

    Do 9 września 2026 adres konta nie był widoczny NIGDZIE w serwisie poza
    ekranem „Potwierdź e-mail" i paczką RODO. Reset hasła szedł więc na adres,
    którego człowiek nie widział i nie mógł poprawić — literówka przy
    rejestracji znaczyła konto bez drogi powrotu.

    Ten ekran odpowiada na trzy pytania w tej kolejności, bo w takiej
    kolejności się je zadaje:

      1. Jaki mam adres?           (i czy jest potwierdzony)
      2. Czy coś się z nim dzieje? (oczekująca zmiana — tylko gdy jest)
      3. Jak go zmienić?

    Wszystko działa bez JavaScriptu: trzy zwykłe formularze POST i jeden
    odnośnik GET z listu (AGENTS.md §5).
--}}
<x-layout title="Adres e-mail" :noindex="true">
    <h1>Adres e-mail</h1>

    <p class="mb-5">
        Na ten adres wysyłamy odnośnik do ustawienia nowego hasła, gdyby kiedyś
        wyleciało Ci z głowy. Tym adresem możesz się też logować.
    </p>

    <x-error-summary />

    <section class="card">
        <h2 class="mt-0">Twój adres</h2>

        {{-- Adres w całości i dużym drukiem. Człowiek ma tu zobaczyć własną
             literówkę — skrót albo gwiazdki ukrywałyby dokładnie to, po co
             ten ekran powstał. --}}
        <p><strong>{{ $user->email }}</strong></p>

        @if($user->hasVerifiedEmail())
            <p class="field-help">
                Adres jest potwierdzony. Odzyskanie hasła i pobranie swoich danych działa.
            </p>
        @else
            <p>
                Ten adres nie jest jeszcze potwierdzony. Dopóki nie potwierdzisz,
                nie pobierzesz paczki ze swoimi danymi.
            </p>

            @if(\App\Support\Poczta::dziala())
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <div class="form-actions">
                        <button class="btn btn-secondary" type="submit">Wyślij potwierdzenie jeszcze raz</button>
                    </div>
                </form>
                <p class="field-help mt-3">
                    Wyślemy wiadomość na {{ $user->email }}. Sprawdź też folder „Spam”.
                </p>
            @else
                <p class="field-help">
                    Nie wysyłamy jeszcze wiadomości e-mail, więc potwierdzenie na razie nie przyjdzie.
                    Napisz do nas na {{ config('kuking.community.contact_email') }} — odpisuje człowiek.
                </p>
            @endif
        @endif
    </section>

    @if($oczekujaca)
        <section class="card mt-8">
            <h2 class="mt-0">Zmiana adresu czeka na potwierdzenie</h2>

            <p>
                Wysłaliśmy list na adres <strong>{{ $oczekujaca->new_email }}</strong>.
                Kliknij w nim odnośnik, a wtedy przeniesiemy konto na ten adres.
            </p>

            <p>
                <strong>Do tego czasu nic się nie zmienia.</strong> Logujesz się adresem
                {{ $user->email }} i na ten adres przyjdzie odnośnik do zmiany hasła.
            </p>

            <p class="field-help">
                Odnośnik z listu działa do {{ \App\Support\Czas::data($oczekujaca->expires_at, 'j F Y, H:i') }}.
                Po tym terminie zamówisz zmianę jeszcze raz.
            </p>

            <form method="POST" action="{{ route('settings.email.cancel') }}">
                @csrf
                <div class="form-actions">
                    <button class="btn btn-secondary" type="submit">Anuluj zmianę adresu</button>
                </div>
            </form>

            <p class="field-help mt-3">
                Po anulowaniu odnośnik z listu przestaje działać, a konto zostaje przy
                dotychczasowym adresie.
            </p>
        </section>
    @endif

    <section class="card mt-8">
        <h2 class="mt-0">Zmień adres e-mail</h2>

        @if($pocztaDziala)
            <p>
                Wpisz nowy adres, a wyślemy na niego list z odnośnikiem. Konto przeniesie się
                na ten adres <strong>dopiero po kliknięciu w odnośnik</strong> — do tego czasu
                logujesz się tak jak dziś.
            </p>

            <p>
                Na dotychczasowy adres wyślemy od razu wiadomość o tej prośbie. Jeśli
                kiedykolwiek dostaniesz taką wiadomość, a to nie Ty prosiłaś/eś o zmianę —
                zmień hasło.
            </p>

            <form method="POST" action="{{ route('settings.email.request') }}">
                @csrf

                <x-field name="current_password" label="Obecne hasło" type="password" required
                         autocomplete="current-password"
                         help="Pytamy o hasło, żeby mieć pewność, że to naprawdę Ty." />

                <x-field name="email" label="Nowy adres e-mail" type="email" required
                         autocomplete="email" inputmode="email"
                         help="Wpisz adres skrzynki, do której masz dostęp — to na nią wyślemy list." />

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Wyślij list na nowy adres</button>
                </div>
            </form>
        @else
            <p>
                Nie wysyłamy jeszcze wiadomości e-mail, więc nie mamy jak potwierdzić nowego
                adresu — dlatego na razie nie da się go tu zmienić.
            </p>
            <p>
                Napisz do nas na {{ config('kuking.community.contact_email') }},
                a zmienimy adres ręcznie. Odpisuje człowiek.
            </p>
        @endif
    </section>

    <x-ustawienia-nawigacja aktywne="email" />
</x-layout>
