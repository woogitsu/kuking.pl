<x-layout title="Zgłoś treść" :noindex="true">
    <h1>Zgłoś tę treść</h1>
    <p class="mb-5">
        Powiedz nam, co jest nie tak. Sprawdzimy to i odpiszemy Ci, co zrobiliśmy.
        Zgłoszenie jest anonimowe dla osoby, której dotyczy.
    </p>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('reports.store', ['type' => $targetType, 'id' => $targetId]) }}">
        @csrf

        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0" id="f-reason"
                  @error('reason') tabindex="-1" aria-invalid="true" aria-describedby="f-reason-error" @enderror>
            <legend class="font-bold mb-3">Co jest nie tak?</legend>
            <div class="stack-tight">
                @foreach($reasons as $value => $label)
                    <label class="choice">
                        <input type="radio" name="reason" value="{{ $value }}" @checked(old('reason') === $value)>
                        <span class="choice-label">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <x-blad-grupy name="reason" />
        </fieldset>

        <x-field name="details" label="Chcesz coś dopisać?" type="textarea" :rows="4"
                 help="Nie musisz. Ale każde zdanie pomaga nam szybciej zrozumieć sprawę." />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij zgłoszenie</button>
            {{-- Cel liczy `ReportController::adresTresci()`, NIE `url()->previous()`:
                 na ten formularz wchodzi się także wprost, a wtedy „poprzednim"
                 adresem jest on sam i „Wróć" prowadziło donikąd (issue #795). --}}
            <a class="btn btn-quiet" href="{{ $adresPowrotu }}">Wróć</a>
        </div>
    </form>
</x-layout>
