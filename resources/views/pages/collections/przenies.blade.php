<x-layout title="Przenieś do innego zeszytu" :noindex="true">
    <h1>Przenieś do innego zeszytu</h1>
    <p class="mb-5">
        @if($tytul !== null)
            Przenosisz „{{ $tytul }}” z zeszytu „{{ $zeszyt->name }}”.
        @else
            Przenosisz tę pozycję z zeszytu „{{ $zeszyt->name }}”.
        @endif
        Notatka i data zapisu pójdą razem z pozycją. Nikt nie dostanie powiadomienia, a sam przepis ani jego historia gotowania się nie zmienią.
    </p>

    <x-error-summary :field-ids="['cel' => 'f-cel']" />

    @if($konflikt)
        {{-- Konflikt: cel już ma tę pozycję. Nic nie przeniesiono, żadna notatka
             nie została nadpisana ani połączona — pokazujemy obie, żeby człowiek
             zdecydował sam (np. usunął jedną pozycję ręcznie). --}}
        <div class="notice" role="status">
            <p class="m-0"><strong>Nic nie zostało przeniesione.</strong></p>
            <p class="mt-3 mb-0">Notatka w zeszycie „{{ $zeszyt->name }}”: {{ $konflikt['zrodlo_notatka'] ?? 'bez notatki' }}</p>
            <p class="mt-3 mb-0">Notatka w zeszycie „{{ $konflikt['cel'] }}”: {{ $konflikt['cel_notatka'] ?? 'bez notatki' }}</p>
        </div>
    @endif

    @if($cele->isEmpty())
        <p class="notice">Nie masz innego prywatnego zeszytu bez zaproszonych osób. Najpierw załóż nowy zeszyt na stronie „Twój zeszyt”, a potem wróć tutaj.</p>
        <p><a class="btn btn-secondary" href="{{ route('collections.show', $zeszyt) }}">Wróć do zeszytu</a></p>
    @else
        <form class="panel-formularza" method="POST" action="{{ route('collections.move.store', ['collection' => $zeszyt, 'typ' => $typ, 'pozycja' => $pozycja]) }}" novalidate>
            @csrf
            <fieldset class="border-0 p-0" id="f-cel" @error('cel') tabindex="-1" aria-invalid="true" aria-describedby="f-cel-error" @enderror>
                <legend class="font-bold mb-3">Do którego zeszytu?</legend>
                <div class="choice-grid">
                    @foreach($cele as $cel)
                        <label class="choice">
                            <input type="radio" name="cel" value="{{ $cel->getKey() }}" @checked(old('cel') === (string) $cel->getKey())>
                            <span class="choice-label">{{ $cel->name }}</span>
                        </label>
                    @endforeach
                </div>
                <x-blad-grupy name="cel" />
            </fieldset>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Przenieś</button>
                <a class="btn btn-quiet" href="{{ route('collections.show', $zeszyt) }}">Wróć bez przenoszenia</a>
            </div>
        </form>
    @endif
</x-layout>
