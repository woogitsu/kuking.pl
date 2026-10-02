{{-- „Zaplanuj wybrane przepisy” (V2, #2483): krok 1 — wybór przepisów z TEJ strony
     zeszytu i jeden wspólny dzień. Tylko właściciel zeszytu (Policy `planuj`).
     Zwykły formularz, bez JavaScriptu; nic nie jest zapisywane przed podglądem
     i jawnym zatwierdzeniem. --}}
<x-layout title="Zaplanuj przepisy z zeszytu" :noindex="true">
    <p>
        <a class="btn btn-quiet" href="{{ route('collections.show', $collection) }}">Wróć do zeszytu</a>
    </p>

    <h1>Zaplanuj przepisy z zeszytu</h1>
    <p class="meta meta-samodzielne">
        Zeszyt „{{ $collection->name }}”. Zaznacz przepisy, wybierz jeden dzień w swoim planerze i zobacz podgląd.
        Nic się nie doda, dopóki nie zatwierdzisz. Notatki z zeszytu nie przechodzą do planu.
    </p>

    @if($przepisy->total() === 0)
        <x-empty-state title="Nie ma tu przepisów do zaplanowania">
            W tym zeszycie nie ma teraz opublikowanych przepisów, które możesz zaplanować.
        </x-empty-state>
    @else
        <form class="panel-formularza" method="POST" action="{{ route('collections.planer.podglad', $collection) }}" novalidate>
            @csrf
            <input type="hidden" name="strona" value="{{ $przepisy->currentPage() }}">
            <x-error-summary />

            <x-field name="dzien" label="Dzień w planerze" type="date" :value="old('dzien')" :required="true"
                     help="Wybierz dzień, na który planujesz wszystkie zaznaczone przepisy." />

            <fieldset class="field @error('przepisy') has-error @enderror" aria-describedby="przepisy-zakres">
                <legend>Przepisy do zaplanowania</legend>
                <p class="field-help" id="przepisy-zakres">
                    Wybierasz spośród przepisów widocznych na tej stronie:
                    {{ $przepisy->firstItem() }}–{{ $przepisy->lastItem() }} z {{ $przepisy->total() }}.
                    Zaznaczenia z innych stron nie są pamiętane — zaplanuj te z tej strony, a potem wróć po resztę.
                </p>
                @error('przepisy')<span class="field-error" id="f-przepisy-error">{{ $message }}</span>@enderror
                <div class="choice-grid">
                    @foreach($przepisy as $przepis)
                        <label class="choice">
                            <input type="checkbox" name="przepisy[]" value="{{ $przepis->getKey() }}" @checked(in_array((string) $przepis->getKey(), array_map('strval', (array) old('przepisy', [])), true))>
                            <span class="choice-label">{{ $przepis->title }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Zobacz podgląd</button>
                <a class="btn btn-secondary" href="{{ route('collections.show', $collection) }}">Anuluj</a>
            </div>
        </form>

        <x-show-more :paginator="$przepisy" czego="przepisów" />
    @endif
</x-layout>
