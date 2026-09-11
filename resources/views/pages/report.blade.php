<x-layout title="Zgłoś treść" :noindex="true">
    <h1>Zgłoś tę treść</h1>
    <p class="mb-5">
        Powiedz nam, co jest nie tak. Sprawdzimy to i odpiszemy Ci, co zrobiliśmy.
        Zgłoszenie jest anonimowe dla osoby, której dotyczy.
    </p>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('reports.store', ['type' => $targetType, 'id' => $targetId]) }}">
        @csrf

        <fieldset class="border-0 p-0">
            <legend class="font-bold mb-3">Co jest nie tak?</legend>
            <div class="stack-tight">
                @foreach($reasons as $value => $label)
                    <label class="choice">
                        <input type="radio" name="reason" value="{{ $value }}" @checked(old('reason') === $value)>
                        <span class="choice-label">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @error('reason')<span class="field-error">{{ $message }}</span>@enderror
        </fieldset>

        <x-field name="details" label="Chcesz coś dopisać?" type="textarea" :rows="4"
                 help="Nie musisz. Ale każde zdanie pomaga nam szybciej zrozumieć sprawę." />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij zgłoszenie</button>
            <a class="btn btn-quiet" href="{{ url()->previous() }}">Wróć</a>
        </div>
    </form>
</x-layout>
