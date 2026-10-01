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
    $parametrZdjec = $zeZdjeciami ? [] : ['bez-zdjec' => 1];
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
            <a class="btn btn-primary" href="{{ $adresStrony($parametrZdjec + ['druk' => 1]) }}#jak-wydrukowac" rel="nofollow" data-drukuj-przepis>Wydrukuj zeszyt</a>
            @if($zeZdjeciami)
                <a class="btn btn-secondary" href="{{ $adresStrony(['bez-zdjec' => 1]) }}" rel="nofollow">Bez zdjęć</a>
            @else
                <a class="btn btn-secondary" href="{{ $adresStrony() }}" rel="nofollow">Ze zdjęciami</a>
            @endif
            <a class="btn btn-quiet" href="{{ route('collections.show', $collection) }}">Wróć do zeszytu</a>
        </div>
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
                <p class="m-0">Ten zeszyt ma więcej przepisów, niż mieści jeden wydruk. Poniżej jest pierwszych {{ $limit }} w kolejności alfabetycznej. Resztę możesz wydrukować pojedynczo, przyciskiem „Drukuj przepis” na stronie przepisu.</p>
            </div>
        @endif
        <p class="mt-6 mb-3">Tak będzie wyglądać książka:</p>
    </div>

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
                {{ $liczbaPrzepisow }} {{ \App\Support\Odmiana::rzeczownik($liczbaPrzepisow, 'przepis', 'przepisy', 'przepisów') }}<br>
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
                $notatka = $dostepDoNotatek ? $przepis->pivot?->note : null;
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
                    <ol class="step-list">
                        @foreach($przepis->steps as $krok)
                            <li>
                                <span class="step-number" aria-hidden="true">{{ $krok->position + 1 }}</span>
                                <div>
                                    <span class="visually-hidden">Krok {{ $krok->position + 1 }}.</span>
                                    <p class="m-0 whitespace-pre-line">{{ $krok->instruction }}</p>
                                    @if($krok->timerLabel())
                                        <p class="meta m-0">Czas kroku: {{ $krok->timerLabel() }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
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
</x-layout>
