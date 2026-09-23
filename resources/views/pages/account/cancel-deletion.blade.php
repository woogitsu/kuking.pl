<x-layout title="Cofnij usunięcie konta" :noindex="true">
    <h1>Cofnij usunięcie konta</h1>

    <p class="mb-5">
        Jeśli chcesz cofnąć zgłoszone wcześniej usunięcie konta, potwierdź to poniżej swoim hasłem.
        Jeśli masz włączoną weryfikację dwuetapową, wpisz też kod z aplikacji w telefonie.
        Cofnięcie jest możliwe przez {{ $graceDays }} dni od zgłoszenia — potem dane zostają usunięte na stałe
        i tej strony nie da się już użyć.
    </p>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('account.delete.cancel.store') }}">
        @csrf

        <x-field name="login" label="Adres e-mail albo nazwa użytkownika" required
                 autocomplete="username"
                 help="Ten sam, którego używasz do logowania." />

        <x-field name="password" label="Hasło do konta" type="password" required autocomplete="current-password" />

        {{--
            Issue #1314: konto z weryfikacją dwuetapową nie cofa usunięcia
            samym hasłem. Jedno pole na kod z aplikacji ALBO kod zapasowy —
            stąd bez `inputmode="numeric"` (kod zapasowy ma litery).
        --}}
        <x-field name="code" label="Kod z aplikacji albo kod zapasowy" autocomplete="one-time-code"
                 help="Tylko jeśli masz włączoną weryfikację dwuetapową. Jeśli nie masz — zostaw to pole puste." />

        <x-turnstile miejsce="cofniecie_usuniecia" />

        <button class="btn btn-primary mt-5" type="submit">Cofnij usunięcie konta</button>
    </form>

    <p class="mt-6">
        Nie pamiętasz hasła? Skorzystaj z <a href="{{ route('password.request') }}">odzyskiwania hasła</a> —
        to działa niezależnie od stanu konta, więc zadziała także teraz.
    </p>
</x-layout>
