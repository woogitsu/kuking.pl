{{--
    WCZYTANIE WŁASNEJ PACZKI Z DANYMI — krok 2: podgląd i wybór (#1985).

    Pokazuje to, co PodgladPaczkiEksportu rozpoznał: pozycje nowe (z polem
    wyboru), pozycje, które już są na koncie albo powtarzają się w paczce
    (bez pola — konflikt rozstrzygamy jawnie: nie tworzymy drugiej), i
    odrzucone z powodem. Zapisu jeszcze nie było.
--}}
@php
    $sekcje = [
        'Przepisy' => $podglad->przepisy,
        'Własne wpisy' => $podglad->wpisy,
        'Zeszyty' => $podglad->zeszyty,
    ];
    $stany = [
        \App\Domain\Users\Import\PozycjaPodgladu::JUZ_JEST => 'Już to masz na koncie — nie wczytamy drugi raz.',
        \App\Domain\Users\Import\PozycjaPodgladu::POWTORZONA_W_PACZCE => 'Ta sama treść jest w paczce więcej niż raz — wczytamy tylko pierwszą.',
        \App\Domain\Users\Import\PozycjaPodgladu::ODRZUCONA => 'Nie wczytamy tej pozycji.',
    ];
    $iloscNowych = $liczby[\App\Domain\Users\Import\PozycjaPodgladu::NOWA];
@endphp
<x-layout title="Co jest w paczce" :noindex="true">
    <h1>Co jest w paczce</h1>

    <p>
        @if($dataPaczki)
            Paczka przygotowana {{ $dataPaczki }}.
        @endif
        Do wczytania: <strong>{{ $iloscNowych }}</strong>.
        Już na Twoim koncie albo powtórzone: <strong>{{ $liczby['juz_jest'] + $liczby['powtorzona_w_paczce'] }}</strong>.
        Nie do wczytania: <strong>{{ $liczby['odrzucona'] }}</strong>.
    </p>

    <section class="sekcja-strony">
        <h2 class="mt-0">Czego nie wczytamy</h2>
        <ul>
            @foreach($podglad->pominiete as $zdanie)
                <li>{{ $zdanie }}</li>
            @endforeach
        </ul>
    </section>

    <x-error-summary />

    <form method="POST" action="{{ route('settings.data.import.store', ['paczka' => $paczka]) }}">
        @csrf

        @foreach($sekcje as $naglowek => $pozycje)
            @if(count($pozycje) > 0)
                <section class="sekcja-strony">
                    <h2 class="mt-0">{{ $naglowek }} ({{ count($pozycje) }})</h2>
                    <ul>
                        @foreach($pozycje as $pozycja)
                            <li class="mb-4">
                                @if($pozycja->mozeBycUtworzona())
                                    <label class="field">
                                        <input type="checkbox" name="pozycje[]" value="{{ $pozycja->odcisk }}"
                                               @checked(in_array($pozycja->odcisk, (array) $zaznaczone, true))>
                                        <strong>{{ $pozycja->tytul }}</strong>
                                    </label>
                                @else
                                    <strong>{{ $pozycja->tytul }}</strong>
                                    <br><span class="field-help">{{ $pozycja->powod ?? $stany[$pozycja->stan] }}</span>
                                @endif
                                @foreach($pozycja->uwagi as $uwaga)
                                    <br><span class="field-help">{{ $uwaga }}</span>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach

        @if($iloscNowych > 0)
            <p class="field-help">
                Jedno kliknięcie wczytuje najwyżej {{ $limit }} pozycji. Jeśli zaznaczysz więcej, reszta poczeka —
                kliknij „Wczytaj zaznaczone” jeszcze raz. To samo wczytanie drugi raz niczego nie podwoi.
            </p>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Wczytaj zaznaczone</button>
            </div>
        @else
            <p>W tej paczce nie ma nic nowego do wczytania.</p>
        @endif
    </form>

    <p class="mt-6"><a href="{{ route('settings.data.import') }}">Wybierz inny plik</a></p>
</x-layout>
