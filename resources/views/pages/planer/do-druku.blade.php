{{--
    „WYDRUKUJ TEN TYDZIEŃ” (#2498, V2, decyzja właściciela z 2.10.2026).

    Czytelna kartka z planem wybranego tygodnia — do położenia w kuchni albo
    pokazania domownikowi bez otwierania konta na jego urządzeniu. Siedem dni
    z pełnymi datami (z rokiem) i wszystkie pozycje w kolejności planera.
    To zwykła strona HTML: drukuje ją przeglądarka (Ctrl+P, „Zapisz jako PDF”)
    tym samym arkuszem co ściągawka, karta QR i kartka zakupów
    (`resources/css/wydruk-przepisu.css`, rama `.sciagawka`). Drugiego wydruku
    ani generatora PDF nie ma.

    Na kartce WYŁĄCZNIE plan: bez formularzy, wyszukiwania, przycisków,
    nawigacji, danych konta, adresów przepisów, składników i list zakupów.
    Prywatne dopiski i oznaczenia „Zrobione” zostają w planerze — kartka to
    kopia do przekazania, a nie widok właściciela. Przepis niedostępny lub
    usunięty jest pozycją bez tytułu. Odczyt nic nie zapisuje.

    „Wydrukuj” działa jak „Drukuj przepis”: zwykły odnośnik z `?druk=1`, który
    `drukuj-przepis.js` zamienia w `window.print()`, a bez skryptu prowadzi do
    zdania, co nacisnąć (D-053). Strona ma `noindex`.
--}}
@use('App\Domain\Planer\PlanerTygodnia')
@php
    $zakres = \App\Support\Czas::data($poniedzialek, 'j F Y').' – '.\App\Support\Czas::data($poniedzialek->addDays(6), 'j F Y');
    $maPozycje = collect($dni)->contains(fn ($d) => $d['pozycje'] !== []);
@endphp
<x-layout title="Plan tygodnia — wydruk" :noindex="true">
    <div class="druk-podpowiedz">
        <h1>Wydrukuj ten tydzień</h1>
        <p class="mb-3">
            Kartka z planem na {{ $zakres }}: siedem dni i wszystko, co w nich zaplanowano. Bez przycisków, wyszukiwania i listy zakupów.
            Twoje prywatne dopiski i oznaczenia „Zrobione” nie trafiają na papier.
            To kopia z chwili otwarcia tej strony — jeśli zmienisz plan, otwórz wydruk jeszcze raz.
        </p>
        @unless($maPozycje)
            <p class="notice">W tym tygodniu nic jeszcze nie zaplanowano. Kartka będzie pokazywać puste dni — możesz najpierw dodać posiłki w planerze.</p>
        @endunless
        <div class="form-actions">
            <a class="btn btn-primary" href="{{ route('planer.print', ['tydzien' => $poniedzialek->toDateString(), 'druk' => 1]) }}#jak-wydrukowac" rel="nofollow" data-drukuj-przepis>Wydrukuj kartkę</a>
            <a class="btn btn-quiet" href="{{ route('planer.show', ['tydzien' => $poniedzialek->toDateString()]) }}">Wróć do planera</a>
        </div>
        @if(request()->boolean('druk'))
            <div class="notice mt-4" id="jak-wydrukowac" role="status">
                <p class="m-0"><strong>Jak wydrukować tę kartkę:</strong></p>
                <p class="m-0">Na komputerze naciśnij razem klawisze <kbd>Ctrl</kbd> i <kbd>P</kbd> (na komputerze Apple: <kbd>Cmd</kbd> i <kbd>P</kbd>).</p>
                <p class="m-0">Na telefonie otwórz menu przeglądarki (trzy kropki albo „Udostępnij”) i wybierz „Drukuj”.</p>
                <p class="m-0">Żeby zapisać plik zamiast drukować, wybierz drukarkę „Zapisz jako PDF”.</p>
                <p class="m-0">Na kartce będzie sam plan — bez menu i przycisków. Zamknięcie okna drukowania niczego w planerze nie zmienia.</p>
            </div>
        @endif
        <p class="mt-6 mb-3">Tak będzie wyglądać kartka:</p>
    </div>

    <article class="sciagawka kartka-planu sekcja-strony" aria-labelledby="kartka-planu-tytul">
        <h2 id="kartka-planu-tytul">Plan na tydzień</h2>
        <p class="kartka-planu-zakres"><strong>{{ $zakres }}</strong></p>
        <p class="kartka-planu-data">Stan z: {{ $dataOdczytu }}. To kopia — nie zmienia się razem z planerem.</p>

        @foreach($dni as $dzien)
            <section class="kartka-planu-dzien" aria-labelledby="kartka-planu-{{ $dzien['dzien']->toDateString() }}">
                <h3 id="kartka-planu-{{ $dzien['dzien']->toDateString() }}">{{ \Illuminate\Support\Str::ucfirst(\App\Support\Czas::data($dzien['dzien'], 'l, j F Y')) }}</h3>
                @if($dzien['pozycje'] === [])
                    <p class="kartka-planu-pusty">Nic nie zaplanowano.</p>
                @else
                    <ul class="kartka-planu-lista">
                        @foreach($dzien['pozycje'] as $pozycja)
                            <li>
                                @if($pozycja['stan'] === PlanerTygodnia::STAN_PRZEPIS)
                                    {{ $pozycja['przepis']->title }}
                                @elseif($pozycja['stan'] === PlanerTygodnia::STAN_WLASNY)
                                    {{ $pozycja['wpis']->label }}
                                @elseif($pozycja['stan'] === PlanerTygodnia::STAN_NIEDOSTEPNY)
                                    Przepis niedostępny
                                @else
                                    Przepis usunięty
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </article>
</x-layout>
