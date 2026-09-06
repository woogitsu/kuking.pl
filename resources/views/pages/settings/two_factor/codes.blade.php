{{--
    Kody zapasowe — pokazane RAZ (issue #12).

    Ta strona nie pokaże ich drugi raz po odświeżeniu: kontroler czyta je
    z sesji tylko przy TYM JEDNYM wejściu (patrz TwoFactorSettingsController).
--}}
<x-layout title="Zapisz swoje kody zapasowe" :noindex="true">
    <h1>Zapisz swoje kody zapasowe</h1>

    <p class="mb-5">
        Weryfikacja dwuetapowa jest już włączona. Te osiem kodów pokazujemy <strong>tylko teraz</strong> —
        po opuszczeniu tej strony nie zobaczysz ich już nigdzie w serwisie. Zapisz je albo wydrukuj
        i schowaj w bezpiecznym miejscu.
    </p>

    <div class="card mb-5">
        <p class="mt-0"><strong>Do czego służą?</strong> Jeśli zgubisz telefon albo stracisz dostęp
            do aplikacji uwierzytelniającej, każdy z tych kodów pozwala zalogować się <strong>zamiast</strong>
            kodu z aplikacji. Bez nich, po zgubieniu telefonu, konto zostaje zamknięte na dobre.</p>
        <p class="mb-0">Każdy kod działa <strong>tylko raz</strong>.</p>
    </div>

    <ul class="card" style="list-style:none; padding:var(--spacing-5); font-size:20px; font-weight:700; letter-spacing:0.06em;">
        @foreach($kody as $kod)
            <li class="py-2">{{ $kod }}</li>
        @endforeach
    </ul>

    <p class="my-5">
        Wydrukuj tę stronę (<kbd>Ctrl</kbd>+<kbd>P</kbd> na komputerze) albo przepisz kody na kartkę,
        zanim klikniesz dalej.
    </p>

    <div class="form-actions">
        <a class="btn btn-primary" href="{{ route('settings.two_factor.edit') }}">Zapisałem/am kody — gotowe</a>
    </div>
</x-layout>
