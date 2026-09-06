{{--
    Strona kreatora przepisu (3 kroki + podgląd).

    Kreator jest komponentem Livewire, więc wymaga JavaScriptu. Dlatego:

    - `<noscript>` na samej górze prowadzi do formularza jednostronicowego,
      który publikuje przepis zwykłym POST-em i nie potrzebuje skryptu;
    - link do tego formularza jest widoczny ZAWSZE, nie tylko w <noscript> —
      przy słabym zasięgu skrypt potrafi się nie dociągnąć i wtedy strona
      wygląda normalnie, tylko nic nie robi po kliknięciu.
--}}
<x-layout title="Dodaj przepis" :noindex="true" :livewire="true">
    <noscript>
        <div class="notice">
            <p class="mt-0"><strong>Ta przeglądarka nie wykonuje skryptów, więc kreator w krokach nie zadziała.</strong></p>
            <p class="mb-0">
                Nic nie szkodzi — jest druga droga.
                <a href="{{ route('recipes.create.simple') }}">Otwórz formularz na jednej stronie</a>.
                Zapisuje przepis dokładnie tak samo.
            </p>
        </div>
    </noscript>

    <h1>{{ $draft === null ? 'Dodaj przepis' : 'Dokończ przepis' }}</h1>
    <p class="mb-5">
        Przechodzimy przez to w trzech krokach: najpierw o przepisie, potem składniki,
        potem przygotowanie. Na końcu zobaczysz podgląd.
        <strong>Szkic zapisuje się sam</strong> — możesz przerwać w każdej chwili i wrócić później.
    </p>

    @if($draft === null && $drafts->isNotEmpty())
        <div class="notice">
            <p class="mt-0">
                <strong>{{ $drafts->count() === 1 ? 'Masz niedokończony szkic.' : 'Masz niedokończone szkice.' }}</strong>
                Nic z nich nie zginęło — możesz wrócić do pisania.
            </p>
            <ul class="stack-tight list-none p-0 m-0">
                @foreach($drafts as $unfinished)
                    <li>
                        <a class="btn btn-secondary" href="{{ route('recipes.create', ['szkic' => $unfinished->getKey()]) }}">
                            Dokończ: {{ $unfinished->title }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <livewire:recipe-wizard :recipe-id="$draft?->getKey()" />

    <p class="field-help mt-8">
        Wolisz wszystko na jednej stronie, bez kroków?
        <a href="{{ route('recipes.create.simple') }}">Otwórz formularz na jednej stronie</a>.
    </p>
</x-layout>
