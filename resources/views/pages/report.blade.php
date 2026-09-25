@php
    $cel = $cel ?? \App\Domain\Moderation\CelZgloszenia::dla($target);
@endphp

<x-layout :title="'Zgłoś: ' . $cel->nazwa" :noindex="true">
    <h1>Zgłoś: {{ $cel->nazwa }}</h1>

    @if($cel->cytat)
        <p class="cel-zgloszenia-cytat mb-4">{{ $cel->cytat }}</p>
    @endif

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

        {{-- Limit 2000 znaków widoczny PRZED wysłaniem (issue #1381) — ta sama
             liczba co `max:2000` w `ReportController::store()`. Licznik tylko
             informuje; nie obcina wklejonego tekstu, rozstrzyga serwer. --}}
        <x-field name="details" label="Chcesz coś dopisać?" type="textarea" :rows="4"
                 :licznik-znakow="2000"
                 help="Nie musisz. Ale każde zdanie pomaga nam szybciej zrozumieć sprawę." />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij zgłoszenie</button>
            {{-- Cel liczony z autoryzowanego celu zgłoszenia, nie z Referera
                 (issue #795) — patrz `ReportController::wracajDo()`. --}}
            <a class="btn btn-quiet" href="{{ $powrot }}">Wróć</a>
        </div>
    </form>
</x-layout>
