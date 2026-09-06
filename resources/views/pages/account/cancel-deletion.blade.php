<x-layout title="Cofnij usunięcie konta" :noindex="true">
    <h1>Cofnij usunięcie konta</h1>

    <p class="mb-5">
        Jeśli chcesz cofnąć zgłoszone wcześniej usunięcie konta, potwierdź to poniżej swoim hasłem.
        Cofnięcie jest możliwe przez {{ $graceDays }} dni od zgłoszenia — potem dane zostają usunięte na stałe
        i tej strony nie da się już użyć.
    </p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('account.delete.cancel.store') }}">
        @csrf

        <x-field name="login" label="Adres e-mail albo nazwa użytkownika" required
                 autocomplete="username"
                 help="Ten sam, którego używasz do logowania." />

        <x-field name="password" label="Hasło do konta" type="password" required autocomplete="current-password" />

        <button class="btn btn-primary mt-5" type="submit">Cofnij usunięcie konta</button>
    </form>

    <p class="mt-6">
        Nie pamiętasz hasła? Skorzystaj z <a href="{{ route('password.request') }}">odzyskiwania hasła</a> —
        to działa niezależnie od stanu konta, więc zadziała także teraz.
    </p>
</x-layout>
