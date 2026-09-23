{{--
    Ekran włączenia 2FA — kod QR ORAZ sekret przepisany tekstem (issue #12).

    Nie każdy zeskanuje kod aparatem — część osób w naszej grupie wpisuje
    rzeczy ręcznie, więc sekret stoi obok QR-a jako zwykły, zaznaczalny tekst.
--}}
<x-layout title="Włącz weryfikację dwuetapową" :noindex="true">
    <h1>Włącz weryfikację dwuetapową</h1>

    <ol class="lista-krokow mb-5">
        <li>Otwórz aplikację uwierzytelniającą w telefonie (Google Authenticator, Aegis, 1Password…).</li>
        <li>Dodaj nowe konto — zeskanuj kod QR poniżej ALBO, jeśli skaner nie działa, wpisz kod ręcznie (jest pod kodem QR).</li>
        <li>Wpisz albo wklej do pola niżej sześciocyfrowy kod, który pokaże aplikacja.</li>
    </ol>

    <section class="sekcja-strony text-center">
        <div class="max-w-[260px] mx-auto">
            {!! $qr !!}
        </div>
    </section>

    {{--
        Żargon „sekret"/„klucz TOTP" jako GŁÓWNE określenie zadania jest tu
        zły z jednego powodu: to moment PO nieudanej próbie ze skanerem QR,
        czyli dokładnie wtedy, gdy dodatkowe pojęcie najbardziej kosztuje
        (docs/research/AUDYT_60_PLUS.md, ranking pkt 2). Język zadania idzie
        pierwszy, termin techniczny zostaje jako informacja drugorzędna dla
        kontaktu ze wsparciem. Test regresyjny: DwuetapowaKodKopiaTest.
    --}}
    {{-- SEKCJA, nie ramka pomocnicza: to jest DRUGA DROGA do tego samego
         celu, równorzędna z kodem QR wyżej, a nie wyjaśnienie obok niego.
         Kod QR stoi na `sekcja-strony`, więc alternatywa dla osoby, która nie
         ma jak zeskanować, ma stać na tej samej warstwie — inaczej ekran mówi
         „ta droga jest gorsza" komuś, kto nie ma wyboru. --}}
    <section class="sekcja-strony mt-5" id="sekcja-recznego-wpisania">
        <h2 class="mt-0">Nie możesz zeskanować kodu?</h2>
        <p>
            Wpisz w aplikacji ten kod do ręcznego wpisania (czasem nazywany „sekretem"
            albo „kluczem konfiguracji"):
        </p>
        <p class="sekret-do-przepisania">
            {{ $sekret }}
        </p>
    </section>

    <x-error-summary />

    <form class="panel-formularza mt-5" method="POST" action="{{ route('settings.two_factor.confirm') }}">
        @csrf

        <x-field name="code" label="Sześciocyfrowy kod z aplikacji" required
                 inputmode="numeric" autocomplete="one-time-code" />

        {{-- Hasło jak przy wyłączaniu i nowych kodach (#1376, D-245): kod
             dowodzi, że nowy telefon działa, hasło — że to właściciel konta. --}}
        @include('pages.settings.two_factor._password-help', ['wlaczanie' => true])
        <x-field name="password" label="Hasło do Kuking" type="password" required
                 autocomplete="current-password"
                 help="Pytamy o hasło, żeby mieć pewność, że to naprawdę Ty." />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Potwierdź i włącz</button>
            <a class="btn btn-quiet" href="{{ route('settings.two_factor.edit') }}">Anuluj</a>
        </div>
    </form>
</x-layout>
