<x-layout title="Nie pamiętam hasła" :noindex="true">
    <h1>Nie pamiętam hasła</h1>

    {{--
        DWA STANY TEGO EKRANU, BO SERWIS NIE ZAWSZE WYSYŁA POCZTĘ.

        Przy `MAIL_MAILER=log` Laravel zapisuje wiadomość do dziennika
        i zgłasza sukces — dla człowieka nie przychodzi nic. Ten ekran mówił
        w takiej sytuacji „Wyślemy na niego wiadomość z linkiem", a niżej
        doradzał sprawdzenie folderu „Spam": wysyłał osobę na poszukiwanie
        listu, który nigdy nie powstał.

        `App\Support\Poczta::dziala()` pyta wprost o sterownik poczty, więc
        ten ekran nie może się rozjechać z rzeczywistością — gdy dostawca
        zostanie wybrany i wpisany, formularz wraca sam, bez zmiany kodu.
    --}}
    @if(\App\Support\Poczta::dziala())
        <p class="mb-5">
            Podaj adres e-mail, na który jest założone konto. Wyślemy na niego wiadomość z linkiem do ustawienia nowego hasła.
        </p>

        <x-error-summary />

        <form class="card" method="POST" action="{{ route('password.email') }}">
            @csrf
            <x-field name="email" label="Twój adres e-mail" type="email" required autocomplete="email" />
            <x-turnstile miejsce="odzyskanie_hasla" />

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Wyślij link</button>
                <a class="btn btn-quiet" href="{{ route('login') }}">Wróć do logowania</a>
            </div>
        </form>

        <p class="notice mt-6">
            <strong>Wiadomość nie przychodzi?</strong> Sprawdź folder „Spam” albo „Oferty”.
            Jeśli nadal nic nie ma, napisz do nas: {{ config('kuking.community.contact_email') }}
        </p>
    @else
        {{--
            Bez formularza. Pole, które przyjmuje adres i nic nie robi, jest
            gorsze niż jego brak: człowiek wysyła, widzi „gotowe" i czeka.
        --}}
        <p class="notice">
            {{ \App\Support\Poczta::komunikatBrakuPoczty() }}
        </p>

        <p class="mt-5">
            <a class="btn btn-secondary" href="mailto:{{ config('kuking.community.contact_email') }}?subject={{ rawurlencode('Nie pamiętam hasła') }}">
                Napisz do nas w sprawie hasła
            </a>
            <a class="btn btn-quiet" href="{{ route('login') }}">Wróć do logowania</a>
        </p>
    @endif
</x-layout>
