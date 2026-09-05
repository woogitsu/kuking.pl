<x-layout title="Co lubisz gotować?" :noindex="true">
    <p class="wizard-steps">
        <span class="wizard-steps-current">Krok 1 z 3</span>
        <span class="wizard-steps-track" aria-hidden="true">
            <span class="wizard-steps-dot" data-done="true"></span>
            <span class="wizard-steps-dot"></span>
            <span class="wizard-steps-dot"></span>
        </span>
    </p>

    <h1>Co lubisz gotować?</h1>
    <p style="margin-bottom:var(--spacing-5);">
        Zaznacz, co Cię interesuje — podpowiemy Ci ludzi, którzy gotują podobnie.
        Możesz też nic nie zaznaczać i przejść dalej.
    </p>

    <form method="POST" action="{{ route('onboarding.interests') }}">
        @csrf
        <div class="choice-grid">
            @foreach($interests as $value => $label)
                <label class="choice">
                    <input type="checkbox" name="interests[]" value="{{ $value }}">
                    <span class="choice-label">{{ $label }}</span>
                </label>
            @endforeach
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Dalej</button>
            <a class="btn btn-quiet" href="{{ route('onboarding.people') }}">Pomiń ten krok</a>
        </div>
    </form>
</x-layout>
