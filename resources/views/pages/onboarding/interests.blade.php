<x-layout title="Co lubisz gotować?" :noindex="true">
    <section class="marka-onboarding" aria-labelledby="onboarding-title">
        <p class="wizard-steps">
            <span class="wizard-steps-current">Krok 1 z 3</span>
            <span class="wizard-steps-track" aria-hidden="true">
                <span class="wizard-steps-dot" data-done="true"></span>
                <span class="wizard-steps-dot"></span>
                <span class="wizard-steps-dot"></span>
            </span>
        </p>
        <h1 id="onboarding-title">Co lubisz gotować?</h1>
        {{-- Konto istnieje przed tym krokiem; wybór zainteresowań nie jest warunkiem korzystania. --}}
        <p class="onboarding-status-konta">
            Twoje konto już działa — możesz od razu publikować i przeglądać <x-kuking-word />.
            Te trzy kroki są opcjonalne. Każdy z nich możesz pominąć.
        </p>

        @if($tags->isEmpty())
            <p class="onboarding-empty">
                Nie mamy jeszcze listy tagów do zaproponowania.
                Tagi możesz zacząć obserwować później.
            </p>
            <div class="form-actions">
                <a class="btn btn-primary" href="{{ route('onboarding.people') }}">Dalej</a>
            </div>
        @else
            <form method="POST" action="{{ route('onboarding.interests') }}">
                @csrf
                <fieldset class="onboarding-interests">
                    <legend>Zaznacz tematy, które chcesz obserwować.</legend>
                    <p id="onboarding-interests-help">Możesz zmienić wybór później albo przejść dalej bez zaznaczania.</p>
                    <div class="onboarding-interest-grid">
                        @foreach($tags as $tag)
                            <label class="onboarding-interest-tile">
                                <input type="checkbox" name="tags[]" value="{{ $tag->getKey() }}" aria-describedby="onboarding-interests-help" @checked(in_array((string) $tag->getKey(), (array) old('tags', []), true))>
                                <span class="onboarding-interest-name">{{ $tag->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Dalej</button>
                    <a class="btn btn-quiet" href="{{ route('onboarding.people') }}">Pomiń ten krok</a>
                </div>
            </form>
        @endif
    </section>
</x-layout>
