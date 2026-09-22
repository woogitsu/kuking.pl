{{--
    KREATOR PRZEPISU W TRZECH KROKACH — OD #364 EKRAN „DOPISZ SZCZEGÓŁY".

    Kreator PRZESTAŁ BYĆ ekranem tworzenia. Tworzy się na `/dodaj/przepis`:
    sześć rzeczy, jedno kliknięcie, koniec (issue #364). Tutaj się DOPISUJE —
    porcje, czasy, trudność, pochodzenie, grupy składników, uwagi, zdjęcia do
    kroków, kolejność wierszy. Kreator umiał to wszystko od dawna; brakowało
    mu tylko tego, żeby nie stawać na drodze przy pierwszej publikacji.

    Dwa wejścia, obie drogi prawdziwe:

      - `/przepisy/{slug}/szczegoly` — szczegóły opublikowanego przepisu,
      - `/dodaj/przepis?szkic={uuid}` — powrót do niedokończonego szkicu
        (tak linkuje `/dodaj`, i tak ma zostać).

    Kreator jest komponentem Livewire, więc wymaga JavaScriptu. Dlatego
    `<noscript>` prowadzi do tego samego zestawu pól na jednej stronie,
    zwykłym POST-em — i ten link jest widoczny ZAWSZE, nie tylko w
    `<noscript>`: przy słabym zasięgu skrypt potrafi się nie dociągnąć
    i wtedy strona wygląda normalnie, tylko nic nie robi po kliknięciu
    (AGENTS.md §5, D-053 — bez martwego przycisku).
--}}
@php
    $opublikowany = $draft !== null && $draft->isPublished();
    $naglowek = $opublikowany ? 'Dopisz szczegóły' : ($draft === null ? 'Dodaj przepis' : 'Dokończ przepis');

    // Dokąd prowadzi droga bez JavaScriptu. Dla przepisu, który już istnieje,
    // jest nią ten sam zestaw pól na jednej stronie; dla wejścia bez przepisu
    // zostaje formularz jednostronicowy.
    $bezSkryptu = $draft === null
        ? route('recipes.create.simple')
        : route('recipes.edit', $draft->slug);
@endphp

<x-layout :title="$naglowek" :noindex="true" :livewire="true">
    <noscript>
        <div class="notice">
            <p class="mt-0"><strong>Ta przeglądarka nie wykonuje skryptów, więc kreator w krokach nie zadziała.</strong></p>
            <p class="mb-0">
                Nic nie szkodzi — jest druga droga.
                <a href="{{ $bezSkryptu }}">Otwórz formularz na jednej stronie</a>.
                Zapisuje przepis dokładnie tak samo.
            </p>
        </div>
    </noscript>

    <h1>{{ $naglowek }}</h1>
    <p class="mb-5">
        @if($opublikowany)
            Przepis jest już opublikowany — tu dopisujesz to, co chcesz dodać:
            porcje, czasy, po kim jest ten przepis, zdjęcia do kroków.
            <strong>Nazwa przepisu jest wymagana. Do publikacji i zapisu opublikowanego przepisu potrzebny jest też co najmniej jeden krok przygotowania. Pozostałe szczegóły są opcjonalne.</strong>
            Przechodzimy przez to w trzech krokach, a zmiany zapisują się po drodze.
        @else
            Przechodzimy przez to w trzech krokach: najpierw o przepisie, potem składniki,
            potem przygotowanie. Na końcu zobaczysz podgląd. Do zapisania szkicu wystarczy nazwa; do publikacji potrzebny jest też co najmniej jeden krok przygotowania. Pozostałe szczegóły są opcjonalne.
            <strong>Szkic zapisuje się sam</strong> — możesz przerwać w każdej chwili i wrócić później.
        @endif
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
        <a href="{{ $bezSkryptu }}">Otwórz formularz na jednej stronie</a>.
    </p>
</x-layout>
