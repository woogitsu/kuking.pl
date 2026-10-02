{{-- „Zaplanuj wybrane przepisy” (V2, #2483): krok 2 — podgląd przed zapisem.
     Pokazuje nazwy przepisów, które DODAMY, te, które już są w tym dniu
     (zostają bez zmian), i tylko LICZBĘ pominiętych — bez tytułów przepisów,
     które przestały być dostępne. Zatwierdzenie to osobny POST z odciskiem
     podglądu: gdy coś się zmieni, nic się nie zapisze. --}}
<x-layout title="Podgląd planu z zeszytu" :noindex="true">
    <h1>Podgląd planu na {{ $kiedy }}</h1>

    @if($ostrzezenie)
        <p class="notice" role="alert">{{ $ostrzezenie }}</p>
    @endif

    <p class="meta meta-samodzielne">
        Z zeszytu „{{ $collection->name }}”. Jeszcze nic nie zapisaliśmy.
        W tym dniu masz teraz {{ $podglad['dzien_ma'] }} z {{ $podglad['limit'] }} pozycji.
    </p>

    @if(count($podglad['nowe']) > 0)
        <section aria-labelledby="podglad-nowe">
            <h2 id="podglad-nowe">Dodamy {{ count($podglad['nowe']) }} {{ \App\Support\Odmiana::rzeczownik(count($podglad['nowe']), 'przepis', 'przepisy', 'przepisów') }}</h2>
            <ul>
                @foreach($podglad['nowe'] as $przepis)
                    <li>{{ $przepis->title }}</li>
                @endforeach
            </ul>
        </section>
    @else
        <p class="notice" role="status">Nie ma nic nowego do dodania w tym dniu.</p>
    @endif

    @if(count($podglad['juz_sa']) > 0)
        <section aria-labelledby="podglad-juz">
            <h2 id="podglad-juz">Już są w tym dniu ({{ count($podglad['juz_sa']) }}) — zostaną bez zmian</h2>
            <ul>
                @foreach($podglad['juz_sa'] as $przepis)
                    <li>{{ $przepis->title }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if($podglad['niedostepne'] > 0)
        <p class="meta">
            Pominięte, bo nie są już dostępne w tym zeszycie: {{ $podglad['niedostepne'] }}. Nie pokazujemy ich nazw.
        </p>
    @endif

    <form class="panel-formularza" method="POST" action="{{ route('collections.planer.store', $collection) }}" novalidate>
        @csrf
        <input type="hidden" name="dzien" value="{{ $dzien->toDateString() }}">
        <input type="hidden" name="strona" value="{{ $strona }}">
        <input type="hidden" name="odcisk" value="{{ $podglad['odcisk'] }}">
        @foreach($wybrane as $id)
            <input type="hidden" name="przepisy[]" value="{{ $id }}">
        @endforeach
        <div class="form-actions">
            @if(count($podglad['nowe']) > 0)
                <button class="btn btn-primary" type="submit">Dodaj do planu na {{ $kiedy }}</button>
            @endif
        </div>
    </form>

    {{-- „Zmień wybór” wraca do formularza z zachowanym dniem i zaznaczeniami. --}}
    <form method="POST" action="{{ route('collections.planer.podglad', $collection) }}" novalidate>
        @csrf
        <input type="hidden" name="akcja" value="zmien">
        <input type="hidden" name="dzien" value="{{ $dzien->toDateString() }}">
        <input type="hidden" name="strona" value="{{ $strona }}">
        @foreach($wybrane as $id)
            <input type="hidden" name="przepisy[]" value="{{ $id }}">
        @endforeach
        <button class="btn btn-secondary" type="submit">Zmień wybór</button>
        <a class="btn btn-quiet" href="{{ route('collections.show', $collection) }}">Anuluj i wróć do zeszytu</a>
    </form>
</x-layout>
