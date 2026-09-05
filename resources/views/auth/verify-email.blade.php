<x-layout title="Potwierdź adres e-mail" :noindex="true">
    <h1>Potwierdź swój adres e-mail</h1>

    <p>
        Wysłaliśmy wiadomość na <strong>{{ auth()->user()->email }}</strong>.
        Kliknij w niej link, żeby potwierdzić, że ten adres należy do Ciebie.
    </p>

    <p class="notice">
        <strong>Możesz już korzystać z Kuking.</strong> Potwierdzenie adresu nie jest potrzebne,
        żeby dodać zdjęcie czy przepis. Przyda się dopiero wtedy, gdy zapomnisz hasła
        albo zechcesz pobrać wszystkie swoje dane.
    </p>

    <div style="display:flex; gap:var(--spacing-3); flex-wrap:wrap; margin-top:var(--spacing-6);">
        <a class="btn btn-primary" href="{{ route('home') }}">Przejdź do Kuking</a>
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button class="btn btn-secondary" type="submit">Wyślij wiadomość jeszcze raz</button>
        </form>
    </div>

    <p class="meta" style="margin-top:var(--spacing-5);">Wiadomość nie przyszła? Zajrzyj do folderu „Spam”.</p>
</x-layout>
