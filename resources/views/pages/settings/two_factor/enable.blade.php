{{--
    Ekran włączenia 2FA — kod QR ORAZ sekret przepisany tekstem (issue #12).

    Nie każdy zeskanuje kod aparatem — część osób w naszej grupie wpisuje
    rzeczy ręcznie, więc sekret stoi obok QR-a jako zwykły, zaznaczalny tekst.
--}}
<x-layout title="Włącz weryfikację dwuetapową" :noindex="true">
    <h1>Włącz weryfikację dwuetapową</h1>

    <ol style="margin-bottom:var(--spacing-5); padding-left:1.3em;">
        <li>Otwórz aplikację uwierzytelniającą w telefonie (Google Authenticator, Aegis, 1Password…).</li>
        <li>Dodaj nowe konto — zeskanuj kod QR poniżej ALBO wpisz sekret ręcznie.</li>
        <li>Przepisz sześciocyfrowy kod, który aplikacja pokaże, do pola niżej.</li>
    </ol>

    <section class="card text-center">
        <div style="max-width:260px; margin:0 auto;">
            {!! $qr !!}
        </div>
    </section>

    <section class="card mt-5">
        <h2 class="mt-0">Nie możesz zeskanować kodu?</h2>
        <p>Wpisz ten sekret ręcznie, jako „klucz konfiguracji" albo „sekret":</p>
        <p style="font-size:20px; font-weight:700; letter-spacing:0.08em; word-break:break-all;">
            {{ $sekret }}
        </p>
    </section>

    <x-error-summary />

    <form class="card mt-5" method="POST" action="{{ route('settings.two_factor.confirm') }}">
        @csrf

        <x-field name="code" label="Sześciocyfrowy kod z aplikacji" required
                 inputmode="numeric" autocomplete="one-time-code" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Potwierdź i włącz</button>
            <a class="btn btn-quiet" href="{{ route('settings.two_factor.edit') }}">Anuluj</a>
        </div>
    </form>
</x-layout>
