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

    @if($tags->isEmpty())
        {{--
            Gospodarz jeszcze niczego nie promował (D-021, „tag promowany").
            Pusta siatka checkboxów wyglądałaby jak błąd, nie jak krok do
            pominięcia — więc mówimy to wprost i idziemy dalej.
        --}}
        <p class="mb-5">
            Nie mamy jeszcze listy tagów do zaproponowania. Możesz dodawać
            tagi bezpośrednio przy swoich wpisach — zawsze też możesz
            zajrzeć w ustawienia i zacząć obserwować tagi później.
        </p>

        <div class="form-actions">
            <a class="btn btn-primary" href="{{ route('onboarding.people') }}">Dalej</a>
        </div>
    @else
        <p class="mb-5">
            Zaznacz, co Cię interesuje. Z tych tagów zbudujemy Twoją stronę
            główną, żeby nie była pusta, zanim kogoś zaobserwujesz. Zawsze możesz
            to zmienić w ustawieniach. Możesz też nic nie zaznaczać i przejść dalej.
        </p>

        <form method="POST" action="{{ route('onboarding.interests') }}">
            @csrf
            <div class="choice-grid">
                @foreach($tags as $tag)
                    <label class="choice">
                        <input type="checkbox" name="tags[]" value="{{ $tag->getKey() }}">
                        <span class="choice-label">{{ $tag->name }}</span>
                    </label>
                @endforeach
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Dalej</button>
                <a class="btn btn-quiet" href="{{ route('onboarding.people') }}">Pomiń ten krok</a>
            </div>
        </form>
    @endif
</x-layout>
