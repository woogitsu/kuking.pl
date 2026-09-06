<x-layout title="Weryfikacja dwuetapowa" :noindex="true">
    <h1>Weryfikacja dwuetapowa</h1>

    <p style="margin-bottom:var(--spacing-5);">
        Dodaje do hasła drugi krok: kod z aplikacji w telefonie. Jeśli ktoś pozna Twoje hasło,
        samo hasło mu nie wystarczy, żeby się zalogować.
    </p>

    @if(session('status'))
        <p class="card" role="status" style="margin-bottom:var(--spacing-5);">{{ session('status') }}</p>
    @endif

    @if($wlaczone)
        <section class="card">
            <h2 style="margin-top:0;">Włączona</h2>
            <p>Przy logowaniu, oprócz hasła, poprosimy Cię o kod z aplikacji uwierzytelniającej.</p>

            <details style="margin-top:var(--spacing-5);">
                <summary class="btn btn-secondary" style="display:inline-flex;">Wyłącz weryfikację dwuetapową</summary>
                <div style="margin-top:var(--spacing-4);">
                    <x-error-summary />
                    <form method="POST" action="{{ route('settings.two_factor.disable') }}">
                        @csrf
                        <x-field name="password" label="Wpisz swoje hasło" type="password" required
                                 autocomplete="current-password"
                                 help="Pytamy o hasło, żeby mieć pewność, że to naprawdę Ty." />
                        <button class="btn btn-danger" type="submit" style="margin-top:var(--spacing-4);">Wyłącz</button>
                    </form>
                </div>
            </details>
        </section>
    @else
        <section class="card">
            <h2 style="margin-top:0;">Wyłączona</h2>
            <p>Włączenie zajmuje mniej niż dwie minuty i wymaga aplikacji uwierzytelniającej w telefonie
                (na przykład Google Authenticator, Aegis albo 1Password).</p>
            <a class="btn btn-primary" href="{{ route('settings.two_factor.enable') }}">Włącz weryfikację dwuetapową</a>
        </section>
    @endif

    <x-ustawienia-nawigacja aktywne="two_factor" />
</x-layout>
