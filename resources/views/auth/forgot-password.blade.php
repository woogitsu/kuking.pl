<x-layout title="Nie pamiętam hasła" :noindex="true">
    <h1>Nie pamiętam hasła</h1>
    <p style="margin-bottom:var(--spacing-5);">
        Podaj adres e-mail, na który zakładałaś konto. Wyślemy na niego wiadomość z linkiem do ustawienia nowego hasła.
    </p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('password.email') }}">
        @csrf
        <x-field name="email" label="Twój adres e-mail" type="email" required autocomplete="email" />
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij link</button>
            <a class="btn btn-quiet" href="{{ route('login') }}">Wróć do logowania</a>
        </div>
    </form>

    <p class="notice" style="margin-top:var(--spacing-6);">
        <strong>Wiadomość nie przychodzi?</strong> Sprawdź folder „Spam” albo „Oferty”.
        Jeśli nadal nic nie ma, napisz do nas: {{ config('kuking.community.contact_email') }}
    </p>
</x-layout>
