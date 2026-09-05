<x-layout title="Kogo obserwować" :noindex="true">
    <p class="wizard-steps">
        <span class="wizard-steps-current">Krok 2 z 3</span>
        <span class="wizard-steps-track" aria-hidden="true">
            <span class="wizard-steps-dot" data-done="true"></span>
            <span class="wizard-steps-dot" data-done="true"></span>
            <span class="wizard-steps-dot"></span>
        </span>
    </p>

    <h1>Kogo chcesz obserwować?</h1>
    <p style="margin-bottom:var(--spacing-5);">
        To są ludzie, którzy tu gotują. Zaznacz, kogo chcesz widzieć na swojej stronie głównej.
        Zawsze możesz to zmienić.
    </p>

    <form method="POST" action="{{ route('onboarding.people') }}">
        @csrf

        @if($people->isEmpty())
            <x-empty-state title="Nie mamy jeszcze kogo Ci pokazać">
                Kuking dopiero się zaczyna. Za to Ty możesz być jedną z pierwszych osób,
                które tu coś pokażą.
            </x-empty-state>
        @else
            <div class="stack-tight">
                @foreach($people as $person)
                    <label class="choice">
                        <input type="checkbox" name="follow[]" value="{{ $person->profile->username }}">
                        <span style="display:flex; gap:var(--spacing-3); align-items:center; flex:1;">
                            <x-avatar :user="$person" :size="48" />
                            <span>
                                <span class="choice-label">{{ $person->displayName() }}</span>
                                <span class="choice-help">
                                    {{ $person->profile->speciality ?? 'Gotuje w Kuking' }}
                                    · {{ $person->posts_count }} {{ $person->posts_count === 1 ? 'wpis' : 'wpisów' }}
                                </span>
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
        @endif

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Dalej</button>
            <a class="btn btn-quiet" href="{{ route('onboarding.done') }}">Pomiń ten krok</a>
        </div>
    </form>
</x-layout>
