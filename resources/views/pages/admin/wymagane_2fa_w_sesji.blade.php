{{--
    Konto ma włączone 2FA, ale ta sesja kodu nie widziała (#930).

    Powstała na samym haśle przed włączeniem 2FA albo odtworzyła się ze
    starego ciasteczka „zapamiętaj mnie". Do panelu wpuszcza dopiero
    logowanie z kodem — mówimy wprost, co zrobić, i dajemy przycisk.
--}}
<x-layout title="Zaloguj się z kodem — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Weryfikacja dwuetapowa" />

    <section class="marka-panel-bramka" aria-labelledby="panel-wymaga-kodu">
        <h1 id="panel-wymaga-kodu">Zaloguj się ponownie, podając kod</h1>

        <p class="mb-5">
            Weryfikacja dwuetapowa na Twoim koncie jest włączona, ale w tej przeglądarce zalogowano się
            bez kodu z aplikacji. Wyloguj się i zaloguj jeszcze raz — po haśle poprosimy o kod,
            a potem panel moderacji otworzy się normalnie.
        </p>

        <div class="form-actions">
            <x-wyloguj class="btn btn-primary">Wyloguj się i zaloguj z kodem</x-wyloguj>
            <a class="btn btn-quiet" href="{{ route('home') }}">Wróć na stronę główną</a>
        </div>
    </section>
</x-layout>
