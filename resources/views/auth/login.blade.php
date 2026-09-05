<x-layout title="Zaloguj się" :noindex="true">
    <h1>Zaloguj się</h1>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('login') }}">
        @csrf

        <x-field name="login" label="Adres e-mail albo nazwa użytkownika" required
                 autocomplete="username"
                 help="Możesz wpisać jedno albo drugie — obojętnie które." />

        <x-field name="password" label="Hasło" type="password" required autocomplete="current-password" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zaloguj się</button>
            <a class="btn btn-quiet" href="{{ route('password.request') }}">Nie pamiętam hasła</a>
        </div>
    </form>

    <p style="margin-top:var(--spacing-6);">Nie masz konta? <a href="{{ route('register') }}">Załóż konto</a>.</p>
</x-layout>
