<x-layout title="Przepisz z kartki" :noindex="true">
    <h1>Przepisz z kartki lub zeszytu</h1>
    <p class="mb-6">Zrób zdjęcie jednej kartki albo jednej strony zeszytu. Komputer odczyta pismo, a Ty sprawdzisz tekst ze zdjęciem obok.</p>

    {{-- INFORMACJA PRZED KLIKNIĘCIEM, NIE PO (D-297). Przycisk zostaje:
         zdjęcie i tak trafi do szkicu, a odczyt można powtórzyć później. --}}
    @if($limitOsoby !== null)
        <div class="notice" role="status">
            <p class="m-0">
                @if($limitOsoby === 'dzien')
                    Dziś odczytaliśmy już {{ $naDzien }} Twoich przepisów — to dzienny limit. Jutro rano będzie można dalej.
                @else
                    W tym miesiącu wykorzystano już limit odczytów. Od pierwszego dnia miesiąca będzie można dalej.
                @endif
                Zdjęcie możesz dodać już teraz — zostanie zapisane w szkicu, a tekst wpiszesz ręcznie albo odczytasz później.
            </p>
        </div>
    @elseif($brakBudzetu !== null)
        <div class="notice" role="status">
            <p class="m-0">
                Odczytywanie przepisów jest {{ $brakBudzetu === 'dzien' ? 'na dziś' : 'w tym miesiącu' }} wstrzymane — wyczerpał się limit w serwisie.
                Zdjęcie możesz dodać już teraz — zostanie zapisane w szkicu, a odczyt powtórzysz {{ $brakBudzetu === 'dzien' ? 'jutro' : 'w przyszłym miesiącu' }}.
            </p>
        </div>
    @endif

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('import.zlec') }}" enctype="multipart/form-data">
        @csrf
        @if(($kluczWyslania ?? null) !== null)
            <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">
        @endif

        {{-- Zwykłe pole pliku z `capture` — aparat w telefonie bez skryptu
             i bez gestów. Ten sam wzór pola co na ekranie dodawania przepisu. --}}
        <div class="field @error('zdjecie') has-error @enderror">
            <span class="pole-zdjecia-nazwa" id="f-zdjecie-etykieta">Zdjęcie kartki</span>
            <input class="visually-hidden pole-zdjecia-input" id="f-zdjecie" type="file" name="zdjecie" required
                   accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}" capture="environment"
                   aria-labelledby="f-zdjecie-etykieta f-zdjecie-tytul"
                   aria-describedby="f-zdjecie-help"
                   @error('zdjecie') aria-invalid="true" @enderror>
            <label class="pole-zdjecia" for="f-zdjecie">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-zdjecie-tytul">Zrób zdjęcie albo wybierz je z telefonu</span>
                <span class="field-help" id="f-zdjecie-help">Połóż kartkę na stole, przy oknie. Zdjęcie z góry, cała kartka w kadrze.</span>
            </label>
            @error('zdjecie')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <p class="field-help">Przypomnienie: zdjęcie odczyta komputer firmy OpenAI. Jeśli na kartce są czyjeś dane, zasłoń je przed zrobieniem zdjęcia.</p>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Odczytaj przepis</button>
            <a class="btn btn-secondary" href="{{ route('recipes.create') }}">Wpiszę sam</a>
        </div>
    </form>
</x-layout>
