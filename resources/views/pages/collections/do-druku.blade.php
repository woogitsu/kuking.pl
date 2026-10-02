{{--
    „WYDRUKUJ ZESZYT” (#2351, F7, research z 30 września 2026).

    Cały zeszyt jako rodzinna książka: okładka, spis treści, potem każdy
    przepis na osobnej kartce. To zwykła strona HTML — drukuje ją przeglądarka
    (Ctrl+P, „Zapisz jako PDF”), bez generatora PDF na serwerze. Arkusz druku
    jest ten sam co przy „Drukuj przepis” (`resources/css/wydruk-przepisu.css`,
    blok `.zeszyt-druk`).

    Na ekranie: wstęp z przyciskami i podgląd książki. Na papier idzie sama
    `.zeszyt-druk`; wstęp (`.druk-podpowiedz`) i wszystkie przyciski chowa
    arkusz. „Wydrukuj zeszyt” działa jak „Drukuj przepis”: zwykły odnośnik
    z `?druk=1`, który skrypt `drukuj-przepis.js` zamienia w `window.print()`,
    a bez skryptu prowadzi do zdania, co nacisnąć (D-053).

    DOSTĘP I TREŚĆ: patrz `CollectionPrintController`. W widoku nie ma żadnego
    filtra — kontroler oddaje wyłącznie przepisy, które widzi oglądający.

    STRONA WYDRUKU NIGDY NIE IDZIE DO INDEKSU (`noindex`): to kopia treści
    zeszytu, a nie osobna strona do znalezienia.

    NUMERY STRON W SPISIE: przeglądarka nie umie ich policzyć z poziomu CSS
    (`target-counter()` nie działa w Chrome i Firefoksie), więc spis numeruje
    PRZEPISY, nie kartki, a każda pozycja jest odnośnikiem do przepisu.
--}}
@php
    $liczbaPrzepisow = $przepisy->count();
    $adresStrony = fn (array $parametry = []) => route('collections.print', ['collection' => $collection] + $parametry);
    // Wybory druku (#2438): zdjęcia i notatki z zeszytu są niezależne, a każdy
    // odnośnik niesie OBA, żeby zmiana jednego nie cofała drugiego. Domyślnie
    // bez notatek (D-333); notatki dołącza jawne `z-notatkami=1`.
    $parametrZdjec = $zeZdjeciami ? [] : ['bez-zdjec' => 1];
    $parametrNotatek = $zNotatkami ? ['z-notatkami' => 1] : [];
    // Wybór przepisów (#2463) jest niezależny od zdjęć i notatek (#2438): każdy
    // odnośnik niesie WSZYSTKIE trzy, więc zmiana jednego nie rozszerza po
    // cichu zestawu przepisów ani nie cofa wyboru notatek.
    $parametrWyboru = $wybrane ? ['tryb' => 'wybrane', 'przepisy' => $wybraneId] : [];
    $wybory = $parametrZdjec + $parametrNotatek + $parametrWyboru;
@endphp
<x-layout :title="'Zeszyt „'.$collection->name.'” do druku'" :noindex="true">
    <div class="druk-podpowiedz">
        <h1>Zeszyt do druku</h1>
        <p class="mb-3">
            Cały zeszyt „{{ $collection->name }}” na papierze: okładka, spis treści
            i każdy przepis na osobnej kartce. Znajdą się tu tylko przepisy,
            które widzisz Ty.
        </p>
        <div class="form-actions">
            @unless($pustyWybor)
                <a class="btn btn-primary" href="{{ $adresStrony($wybory + ['druk' => 1]) }}#jak-wydrukowac" rel="nofollow" data-drukuj-przepis>Wydrukuj zeszyt</a>
            @endunless
            @if($zeZdjeciami)
                <a class="btn btn-secondary" href="{{ $adresStrony(['bez-zdjec' => 1] + $parametrNotatek + $parametrWyboru) }}" rel="nofollow">Bez zdjęć</a>
            @else
                <a class="btn btn-secondary" href="{{ $adresStrony($parametrNotatek + $parametrWyboru) }}" rel="nofollow">Ze zdjęciami</a>
            @endif
            @if($dostepDoNotatek)
                @if($zNotatkami)
                    <a class="btn btn-secondary" href="{{ $adresStrony($parametrZdjec + $parametrWyboru) }}" rel="nofollow">Bez notatek</a>
                @else
                    <a class="btn btn-secondary" href="{{ $adresStrony($parametrZdjec + ['z-notatkami' => 1] + $parametrWyboru) }}" rel="nofollow">Z notatkami</a>
                @endif
            @endif
            <a class="btn btn-secondary" href="{{ route('collections.print.select', ['collection' => $collection] + $parametrZdjec + $parametrNotatek + ($wybrane ? ['przepisy' => $wybraneId] : [])) }}" rel="nofollow">Wybierz przepisy</a>
            @if($wybrane)
                <a class="btn btn-secondary" href="{{ $adresStrony($parametrZdjec + $parametrNotatek) }}" rel="nofollow">Cały zeszyt</a>
            @endif
            <a class="btn btn-quiet" href="{{ route('collections.show', $collection) }}">Wróć do zeszytu</a>
        </div>
        {{-- Aktualny wybór widać PRZED drukowaniem (#2438). Wcześniej zapisanych
             kopii nie da się zdalnie odwołać — dlatego mówimy to wprost. --}}
        <p class="meta mt-3 mb-0" data-wybory-druku>
            Ten wydruk będzie {{ $zeZdjeciami ? 'ze zdjęciami' : 'bez zdjęć' }}@if($dostepDoNotatek) i {{ $zNotatkami ? 'z notatkami z Twojego zeszytu' : 'bez notatek z zeszytu' }}@endif.
            @if($dostepDoNotatek && $zNotatkami)
                Jeśli dajesz kopię rodzinie, a notatki są tylko dla Ciebie, wybierz „Bez notatek” przed drukowaniem. Kopii, która już wyszła z domu, nie da się później zmienić.
            @elseif($dostepDoNotatek)
                Notatki z zeszytu są domyślnie pominięte, żeby kopia dla rodziny nie miała prywatnych dopisków. Jeśli chcesz je mieć na papierze, kliknij „Z notatkami” przed drukowaniem.
            @endif
        </p>
        @if($wybrane)
            {{-- Wybór przepisów (#2463): zakres widać PRZED drukowaniem. --}}
            <div class="notice mt-4" role="status" data-wybor-przepisow>
                @if($pustyWybor)
                    <p class="m-0"><strong>Nie wybrano żadnego przepisu.</strong> Kliknij „Wybierz przepisy”, zaznacz przynajmniej jeden przepis i naciśnij „Pokaż wybrane do druku”. Albo wybierz „Cały zeszyt”.</p>
                @else
                    <p class="m-0">Wydruk obejmuje tylko wybrane przepisy: {{ $liczbaPrzepisow }} z {{ $ileWZeszycie }}. Żeby zmienić wybór, kliknij „Wybierz przepisy”. Żeby wydrukować wszystko, kliknij „Cały zeszyt”.</p>
                @endif
                @if($niedostepneWybrane > 0)
                    <p class="m-0 mt-3">Wybrane przepisy, które nie są już dla Ciebie dostępne: {{ $niedostepneWybrane }}. Nie ma ich w wydruku. Sprawdź wybór jeszcze raz.</p>
                @endif
            </div>
        @endif
        @if(request()->boolean('druk'))
            <div class="notice mt-4" id="jak-wydrukowac" role="status">
                <p class="m-0"><strong>Jak wydrukować zeszyt:</strong></p>
                <p class="m-0">Na komputerze naciśnij razem klawisze <kbd>Ctrl</kbd> i <kbd>P</kbd> (na komputerze Apple: <kbd>Cmd</kbd> i <kbd>P</kbd>).</p>
                <p class="m-0">Na telefonie otwórz menu przeglądarki (trzy kropki albo „Udostępnij”) i wybierz „Drukuj”.</p>
                <p class="m-0">Żeby zapisać plik zamiast drukować, wybierz drukarkę „Zapisz jako PDF”.</p>
                <p class="m-0">Na kartkach będzie sam zeszyt — bez menu i przycisków.</p>
            </div>
        @endif
        @if($obcieto)
            <div class="notice mt-4" role="status">
                <p class="m-0">Ten zeszyt ma więcej przepisów, niż mieści jeden wydruk. Poniżej jest pierwszych {{ $limit }} {{ $kolejnoscReczna ? 'w kolejności ułożonej w zeszycie' : 'w kolejności alfabetycznej' }}. Resztę możesz wydrukować pojedynczo, przyciskiem „Drukuj przepis” na stronie przepisu.</p>
            </div>
        @endif
        <p class="mt-6 mb-3">Tak będzie wyglądać książka:</p>
    </div>

    @unless($pustyWybor)
    <div class="zeszyt-druk">
        <section class="zeszyt-okladka" aria-labelledby="zeszyt-tytul">
            <h2 id="zeszyt-tytul" class="zeszyt-tytul">{{ $collection->name }}</h2>
            @if(trim((string) $collection->description) !== '')
                <p class="zeszyt-opis whitespace-pre-line">{{ $collection->description }}</p>
            @endif
            <p class="zeszyt-podpis">
                @if($collection->owner)
                    Zeszyt osoby {{ $collection->owner->displayName() }}<br>
                @endif
                {{ $wybrane ? 'Wybrane przepisy: ' : '' }}{{ $liczbaPrzepisow }} {{ \App\Support\Odmiana::rzeczownik($liczbaPrzepisow, 'przepis', 'przepisy', 'przepisów') }}<br>
                Kuking, {{ $dataWydruku }}
            </p>
        </section>

        <section class="zeszyt-spis" aria-labelledby="zeszyt-spis-naglowek">
            <h2 id="zeszyt-spis-naglowek">Spis treści</h2>
            @if($liczbaPrzepisow === 0)
                <p>W tym zeszycie nie ma przepisów do wydrukowania.</p>
            @else
                <ol class="zeszyt-spis-lista">
                    @foreach($przepisy as $przepis)
                        <li><a href="#przepis-{{ $loop->iteration }}">{{ $przepis->title }}</a></li>
                    @endforeach
                </ol>
            @endif
        </section>

        @foreach($przepisy as $przepis)
            @php
                $notatka = $zNotatkami ? $przepis->pivot?->note : null;
                $czasMinut = $przepis->totalMinutes();
                $porcje = $przepis->servingsLabel();
                $wykonania = (int) $przepis->widoczne_wykonania_count;
            @endphp
            <article class="zeszyt-przepis" id="przepis-{{ $loop->iteration }}" aria-labelledby="przepis-{{ $loop->iteration }}-tytul">
                <p class="meta zeszyt-numer">Przepis {{ $loop->iteration }} z {{ $liczbaPrzepisow }}</p>
                <h2 id="przepis-{{ $loop->iteration }}-tytul">{{ $przepis->title }}</h2>
                <p class="meta m-0">{{ $przepis->attributionLine() }}</p>
                @if($przepis->family_since_year)
                    <p class="meta m-0">W rodzinie od {{ $przepis->family_since_year }}</p>
                @endif
                @if($porcje || $czasMinut)
                    <p class="meta m-0">
                        @if($porcje){{ $porcje }}@endif
                        @if($porcje && $czasMinut) · @endif
                        @if($czasMinut)Czas: {{ \App\Support\Czas::czasPrzepisu($czasMinut) }} @endif
                    </p>
                @endif
                @if($wykonania > 0)
                    <p class="meta m-0">W Kuking: {{ $wykonania }} {{ \App\Support\Odmiana::rzeczownik($wykonania, 'wykonanie', 'wykonania', 'wykonań') }}</p>
                @endif

                @if($zeZdjeciami && $przepis->heroMedia?->isReady() && $przepis->heroMedia->maWariantDoPokazania('thumb'))
                    <div class="zeszyt-zdjecie">
                        <x-photo :media="$przepis->heroMedia" variant="thumb" sizes="320px" :zoom="false" tresc="przepis" class="post-photo" />
                    </div>
                @endif

                @if($przepis->source_note)
                    <section class="recipe-story">
                        <h3>Skąd ten przepis</h3>
                        <p class="whitespace-pre-line m-0">{{ $przepis->source_note }}</p>
                    </section>
                @endif

                <h3>Składniki</h3>
                @if($przepis->ingredients->isEmpty())
                    <p class="meta">Autor nie dodał składników.</p>
                @else
                    @foreach(\App\Domain\Recipes\GrupySkladnikow::ulozyc($przepis->ingredients) as $grupa)
                        @if($grupa['nazwa'] !== null)
                            <h4 class="naglowek-grupy">{{ $grupa['nazwa'] }}</h4>
                        @endif
                        <ul class="ingredient-list">
                            @foreach($grupa['skladniki'] as $skladnik)
                                <li>{{ $skladnik->ingredient_text }}@if($skladnik->note)<span class="meta"> — {{ $skladnik->note }}</span>@endif @if($skladnik->substitutes)<span class="skladnik-zamiennik">Zamiast tego: {{ $skladnik->substitutes }}</span>@endif</li>
                            @endforeach
                        </ul>
                    @endforeach
                @endif

                {{-- Alergeny według autora (#1902): ten sam trzywariantowy komunikat co
                     na stronie przepisu — cisza na papierze nie może znaczyć „w porządku”.
                     Bez `id` i bez przycisków: stu przepisom nie wolno dzielić jednego `id`. --}}
                @if(config('kuking.alergeny.wlaczone'))
                    @php
                        $alergenyLista = $przepis->alergenyZdeklarowane() ? \App\Domain\Recipes\Alergeny\Alergen::nazwyZKodow($przepis->allergens) : '';
                    @endphp
                    <section class="zeszyt-alergeny" data-alergeny="{{ $przepis->alergenyZdeklarowane() ? 'declared' : 'unchecked' }}">
                        <h3>Alergeny</h3>
                        @if($alergenyLista !== '')
                            <p class="m-0">Alergeny według autora: {{ $alergenyLista }}. To zaznaczenie autora, nie badanie. Gotowe produkty mogą zawierać alergeny, których tu nie widać — przeczytaj etykiety.</p>
                        @elseif($przepis->alergenyZdeklarowane())
                            <p class="m-0">Autor nie zaznaczył żadnego z 14 alergenów. To tylko zaznaczenie autora, nie zapewnienie, że ich tam nie ma. Przeczytaj etykiety gotowych produktów.</p>
                        @else
                            <p class="m-0">Alergeny: nie sprawdzono. Autor nie zaznaczył, co zawiera ten przepis, więc nie wiemy, czy nadaje się dla osoby z alergią.</p>
                        @endif
                    </section>
                @endif

                <h3>Przygotowanie</h3>
                @if($przepis->steps->isEmpty())
                    <p class="meta">Autor nie opisał przygotowania.</p>
                @else
                    @foreach(\App\Domain\Recipes\EtapyPrzygotowania::grupy($przepis->steps) as $etap)
                    @if($etap['nazwa'] !== null)
                        <h4 class="naglowek-grupy">{{ $etap['nazwa'] }}</h4>
                    @endif
                    <ol class="step-list">
                        @foreach($etap['kroki'] as $krok)
                            <li>
                                <span class="step-number" aria-hidden="true">{{ $krok->position + 1 }}</span>
                                <div>
                                    <span class="visually-hidden">Krok {{ $krok->position + 1 }}.</span>
                                    <p class="m-0 whitespace-pre-line">{{ $krok->instruction }}</p>
                                    @if($krok->timerLabel())
                                        <p class="m-0">Czas kroku: {{ $krok->timerLabel() }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    @endforeach
                @endif

                @if($notatka !== null && trim($notatka) !== '')
                    <section class="zeszyt-notatka">
                        <h3>Notatka z zeszytu</h3>
                        <p class="whitespace-pre-line m-0">{{ $notatka }}</p>
                    </section>
                @endif

                <p class="meta przepis-adres-druk-zeszyt">Adres przepisu: {{ $przepis->url() }}</p>
            </article>
        @endforeach
    </div>
    @endunless
</x-layout>
