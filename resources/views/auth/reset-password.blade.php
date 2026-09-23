<x-layout title="Ustaw nowe hasło" :noindex="true">
    <h1>Ustaw nowe hasło</h1>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-field name="email" label="Twój adres e-mail" type="email" :value="$email" required autocomplete="email" />
        <x-field name="password" label="Nowe hasło" type="password" required autocomplete="new-password"
                 help="Co najmniej 10 znaków." />
        <x-field name="password_confirmation" label="Powtórz nowe hasło" type="password" required autocomplete="new-password" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz nowe hasło</button>
        </div>
    </form>
</x-layout>
