<x-layout title="Popraw wykonanie" :noindex="true">
    <h1>Popraw wykonanie</h1>
    <p class="mb-5">
        @if($przepis !== null)
            To Twoje wykonanie przepisu „{{ $przepis->title }}”.
        @else
            To Twoje wykonanie.
        @endif
        Poprawiasz ten sam wpis — nie dodajesz nowego, więc nikt nie dostanie kolejnego powiadomienia.
    </p>

    <x-error-summary :field-ids="['wersja' => 'f-wersja']" />

    {{-- Co wolno poprawić, a co zostaje: powiedziane PRZED polami (#2459). --}}
    <p class="notice">
        <strong>Co możesz poprawić?</strong>
        Uwagę, opis zmian „po swojemu” i czas. Zdjęcia, dzień dodania, odpowiedzi „Zrobisz to jeszcze raz?” i „Jak trudne to było?” oraz rozmowa pod wykonaniem zostają bez zmian.
        Po zapisie przy wykonaniu będzie widać napis „Poprawiono” z datą. Poprzedniego tekstu nie przechowujemy.
    </p>

    {{-- Konflikt albo pola, których nie zapisano: błąd ma własne miejsce,
         a pod nim stan zapisany TERAZ — żeby tekst z formularza nie przykrył
         po cichu cudzej, nowszej poprawki. --}}
    @if($errors->has('wersja'))
        <div class="notice" id="f-wersja" tabindex="-1" role="alert">
            <p class="m-0"><strong>{{ $errors->first('wersja') }}</strong></p>
            <p class="mt-3 mb-0"><strong>Tak jest zapisane teraz:</strong></p>
            <ul class="stack-tight">
                <li>Jak wyszło: {{ $event->note !== null && $event->note !== '' ? $event->note : 'bez uwagi' }}</li>
                <li>Po swojemu: {{ $event->changes_note !== null && $event->changes_note !== '' ? $event->changes_note : 'bez opisu' }}</li>
                <li>Czas: {{ $event->actual_minutes !== null ? \App\Support\Czas::czasPrzepisu((int) $event->actual_minutes) : 'bez czasu' }}</li>
            </ul>
        </div>
    @endif

    <form class="panel-formularza" method="POST" action="{{ route('cooked.update', $event) }}" novalidate>
        @csrf
        @method('PUT')
        <input type="hidden" name="wersja" value="{{ $wersja }}">

        @if(isset($zablokowane['note']))
            <div class="field">
                <span class="font-bold">Jak wyszło?</span>
                <p class="tekst-jak-napisano m-0">{{ $event->note !== null && $event->note !== '' ? $event->note : 'Bez uwagi.' }}</p>
                <p class="m-0 meta meta-samodzielne">{{ $zablokowane['note'] }}</p>
            </div>
        @else
            <x-field name="note" label="Jak wyszło?" type="textarea" :rows="4" :value="$event->note"
                     help="Możesz poprawić literówkę albo całkiem wyczyścić pole." />
        @endif

        @if(isset($zablokowane['changes_note']))
            <div class="field">
                <span class="font-bold">Coś po swojemu?</span>
                <p class="tekst-jak-napisano m-0">{{ $event->changes_note !== null && $event->changes_note !== '' ? $event->changes_note : 'Bez opisu.' }}</p>
                <p class="m-0 meta meta-samodzielne">{{ $zablokowane['changes_note'] }}</p>
            </div>
        @else
            <x-field name="changes_note" label="Coś po swojemu?" type="textarea" :rows="3" :value="$event->changes_note"
                     help="Zamiana składnika, inny czas, inna forma. Pole można wyczyścić." />
        @endif

        <x-field name="actual_minutes" label="Ile Ci to zajęło (w minutach)" type="number"
                 inputmode="numeric" :min="0" :max="10080" :value="$event->actual_minutes"
                 help="Na przykład 20, a nie 120. Pole można wyczyścić, jeśli nie chcesz podawać czasu." />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz poprawkę</button>
            <a class="btn btn-quiet" href="{{ route('cooked.show', $event) }}">Wróć bez zmian</a>
        </div>
    </form>
</x-layout>
