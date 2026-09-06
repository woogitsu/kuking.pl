{{--
    Drugi krok logowania — kod z aplikacji uwierzytelniającej (issue #12).

    Zwykły formularz HTML, bez JavaScriptu — logowanie jest jedną z akcji,
    która musi działać bez skryptu (AGENTS.md §5), a ten ekran jest jego
    częścią tak samo jak hasło.
--}}
<x-layout title="Kod z aplikacji" :noindex="true">
    <h1>Wpisz kod z aplikacji</h1>

    <p class="mb-5">
        Twoje hasło jest poprawne. To konto ma włączoną weryfikację dwuetapową — otwórz aplikację
        uwierzytelniającą w telefonie (na przykład Google Authenticator, Aegis albo 1Password)
        i przepisz sześciocyfrowy kod, który tam widzisz.
    </p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('login.two_factor.store') }}">
        @csrf

        <x-field name="code" label="Sześciocyfrowy kod z aplikacji" required
                 inputmode="numeric" autocomplete="one-time-code" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zaloguj się</button>
        </div>
    </form>

    <details class="card mt-5">
        <summary class="btn btn-secondary inline-flex">Nie mam dostępu do telefonu</summary>
        <div class="mt-4">
            <p>
                Możesz zamiast tego użyć jednego z kodów zapasowych, które dostałeś/aś przy włączaniu
                weryfikacji dwuetapowej. Każdy kod zapasowy działa tylko raz.
            </p>
            <form method="POST" action="{{ route('login.two_factor.store') }}">
                @csrf
                <x-field name="backup_code" label="Kod zapasowy" autocomplete="off" placeholder="XXXX-XXXX" />
                <div class="form-actions">
                    <button class="btn btn-secondary" type="submit">Zaloguj się kodem zapasowym</button>
                </div>
            </form>
        </div>
    </details>
</x-layout>
