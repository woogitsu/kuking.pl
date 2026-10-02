{{--
    „Przenieś na inny dzień” (#2447, V2). Prywatny, `noindex`.

    Zwykły formularz bez skryptu: jedna pozycja, jedno pole z datą i dwa
    przyciski. Przenosimy TĘ SAMĄ pozycję (nie kopiujemy), więc na dawnym dniu
    jej nie będzie. Ukryte pole `stan` niesie dzień, na którym pozycję widać —
    jeśli ktoś w innym oknie przeniósł ją wcześniej, akcja to rozpozna.

    Nazwa pozycji przychodzi z kontrolera już po bramce widoczności: przepis,
    którego właściciel planu już nie widzi, ma tu neutralny podpis.
--}}
@use('App\Domain\Planer\PlanerTygodnia')
@use('App\Domain\Planer\ZakresDatPlanu')
<x-layout title="Przenieś na inny dzień" :noindex="true">
    <h1>Przenieś na inny dzień</h1>

    @php
        $dzis = \Carbon\CarbonImmutable::parse(\App\Support\Czas::dzisiajData());
        $dzienPlanu = $dzien->toDateString();
        // Błąd przy polu i w podsumowaniu prowadzą do jedynego pola formularza.
        $celeBledow = ['day' => 'f-day', 'stan' => 'f-day'];
    @endphp
    <x-error-summary :field-ids="$celeBledow" />

    <p class="mb-5">
        <strong>{{ $nazwa }}</strong><br>
        Teraz w planie na {{ PlanerTygodnia::naDzien($dzien) }}. Ta sama pozycja zostanie przeniesiona — nie powstanie jej kopia, a na dawnym dniu jej nie będzie.
    </p>

    <form class="panel-formularza" method="POST" action="{{ route('planer.move', $wpis) }}" novalidate>
        @csrf @method('PATCH')
        <input type="hidden" name="stan" value="{{ $dzienPlanu }}">
        <x-field name="day" type="date" label="Nowy dzień" :value="$dzienPlanu" :required="true" :bez-oznaczenia="true"
                 :min="$dzis->subDays(ZakresDatPlanu::DNI_WSTECZ)->toDateString()"
                 :max="$dzis->addDays(ZakresDatPlanu::DNI_DO_PRZODU)->toDateString()"
                 :help="'Planer przyjmuje dni '.ZakresDatPlanu::opis().'.'" />
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Przenieś</button>
            <a class="btn btn-quiet" href="{{ route('planer.show', ['tydzien' => $dzienPlanu]) }}#dzien-{{ $dzienPlanu }}">Anuluj, zostaw jak jest</a>
        </div>
    </form>
</x-layout>
