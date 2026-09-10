{{--
    Jedna wiadomość z „Napisz do nas" — podgląd i obsługa.

    WEJŚCIE PRZECHODZI PRZEZ `ContactMessagePolicy::view()`, nie przez to,
    że adres z UUID-em jest trudny do zgadnięcia (AGENTS.md §7).

    Adres do odpowiedzi jest tu pokazany OTWARTYM TEKSTEM i to jest celowe:
    ten ekran istnieje po to, żeby dało się komuś odpisać, a kopiowanie
    adresu z bazy przez konsolę byłoby gorsze pod każdym względem, także
    pod względem ochrony danych.
--}}
<x-layout title="Wiadomość do nas — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Wiadomości do nas" />

    <p class="meta">
        <a href="{{ route('admin.contact') }}">← Wróć do listy wiadomości</a>
    </p>

    <h1>{{ $wiadomosc->rodzajLabel() }}</h1>

    <p class="meta">
        {{ \App\Support\Czas::data($wiadomosc->created_at, 'j F Y, H:i') }} ·
        {{ $wiadomosc->statusLabel() }}
        @if($wiadomosc->handler && $wiadomosc->handled_at)
            · {{ $wiadomosc->handler->displayName() }},
            {{ \App\Support\Czas::data($wiadomosc->handled_at, 'j F Y, H:i') }}
        @endif
    </p>

    <article class="card">
        <p class="whitespace-pre-line mt-0 mb-0">{{ $wiadomosc->message }}</p>
    </article>

    <div class="card mt-5">
        <h2 class="mt-0">Skąd to przyszło</h2>
        <ul>
            <li>
                Kto:
                @if($wiadomosc->author)
                    {{ $wiadomosc->author->displayName() }}
                    (<a href="{{ route('profile.show', $wiadomosc->author->profile->username) }}">profil</a>)
                @else
                    osoba bez konta
                @endif
            </li>
            <li>
                Odpowiedź wysyłasz na:
                @if($wiadomosc->adresDoOdpowiedzi())
                    <a href="mailto:{{ $wiadomosc->adresDoOdpowiedzi() }}">{{ $wiadomosc->adresDoOdpowiedzi() }}</a>
                @else
                    <strong>nie ma adresu</strong> — ta osoba nie zostawiła kontaktu
                    i nie da się jej odpisać.
                @endif
            </li>
            <li>
                Strona, z której pisano:
                @if($wiadomosc->page_path)
                    <span class="kod-do-przepisania">{{ $wiadomosc->page_path }}</span>
                @else
                    nie wiadomo
                @endif
            </li>
            <li>Wydanie serwisu: {{ $wiadomosc->wydanie ?? 'nie wiadomo' }}</li>
        </ul>
    </div>

    <x-error-summary />

    <form class="card mt-5" method="POST" action="{{ route('admin.contact.update', $wiadomosc) }}">
        @csrf

        <fieldset class="border-0 p-0">
            <legend class="font-bold mb-3">Stan wiadomości</legend>
            <div class="choice-grid">
                @foreach(\App\Models\ContactMessage::STATUSY as $wartosc => $etykieta)
                    <label class="choice">
                        <input type="radio" name="status" value="{{ $wartosc }}"
                               @checked(old('status', $wiadomosc->status) === $wartosc)>
                        <span class="choice-label">{{ $etykieta }}</span>
                    </label>
                @endforeach
            </div>
            @error('status')<span class="field-error">{{ $message }}</span>@enderror
        </fieldset>

        <x-field name="handler_note" label="Notatka dla siebie" type="textarea" :rows="4"
                 :value="old('handler_note', $wiadomosc->handler_note)"
                 help="Widzi ją tylko obsługa. Na przykład: numer issue albo data, kiedy odpisano." />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
        </div>
    </form>

    <p class="meta mt-5">
        Ta wiadomość zniknie z bazy sama, {{ config('kuking.kontakt.retention_months') }}
        {{ \App\Support\Odmiana::rzeczownik((int) config('kuking.kontakt.retention_months'), 'miesiąc', 'miesiące', 'miesięcy') }}
        po oznaczeniu jako załatwiona. Dopóki jest otwarta, nie kasuje jej nic.
    </p>
</x-layout>
