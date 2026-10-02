{{--
    „WYDRUKUJ DO KUPIENIA” (#2495, V2, decyzja właściciela z 2.10.2026).

    Kartka z samymi nieodhaczonymi pozycjami listy zakupów i pustym kwadratem
    przy każdej — do odhaczania długopisem w sklepie. To zwykła strona HTML:
    drukuje ją przeglądarka (Ctrl+P, „Zapisz jako PDF”), tym samym arkuszem
    co ściągawka i karta QR (`resources/css/wydruk-przepisu.css`, rama
    `.sciagawka`). Drugiego wydruku ani generatora PDF nie ma.

    Na kartce WYŁĄCZNIE teksty pozycji, dokładnie w kolejności listy, bez
    parsowania i łączenia linii. Nie ma na niej pochodzenia pozycji, adresów
    przepisów, nazwy konta ani żadnych przycisków, formularzy i nawigacji.
    Jest tytuł, data i godzina odczytu oraz zdanie, że to kopia, która nie
    zmienia się razem z listą. Odczyt nic nie zapisuje: ani nie odhacza, ani
    nie usuwa; anulowanie okna drukowania niczego nie zmienia.

    „Wydrukuj” działa jak „Drukuj przepis”: zwykły odnośnik z `?druk=1`, który
    `drukuj-przepis.js` zamienia w `window.print()`, a bez skryptu prowadzi do
    zdania, co nacisnąć (D-053). Strona ma `noindex`.
--}}
<x-layout title="Do kupienia — wydruk" :noindex="true">
    <div class="druk-podpowiedz">
        <h1>Wydrukuj do kupienia</h1>
        @if($pozycje === [])
            <p class="notice">Na liście zakupów nie ma teraz nic do kupienia, więc nie ma czego drukować. Odhaczone pozycje nie trafiają na kartkę. Dopisz, co trzeba kupić, i wróć tutaj.</p>
            <div class="form-actions">
                <a class="btn btn-secondary" href="{{ route('shopping.index') }}">Wróć do listy zakupów</a>
            </div>
        @else
            <p class="mb-3">
                Jedna kartka z tym, co jeszcze trzeba kupić, i pustym kwadratem przy każdej pozycji — do odhaczania długopisem.
                To kopia z chwili otwarcia tej strony: późniejsze zmiany na liście nie zmienią kartki. Jeśli coś dopiszesz, otwórz wydruk jeszcze raz.
            </p>
            <div class="form-actions">
                <a class="btn btn-primary" href="{{ route('shopping.print', ['druk' => 1]) }}#jak-wydrukowac" rel="nofollow" data-drukuj-przepis>Wydrukuj kartkę</a>
                <a class="btn btn-quiet" href="{{ route('shopping.index') }}">Wróć do listy zakupów</a>
            </div>
            @if(request()->boolean('druk'))
                <div class="notice mt-4" id="jak-wydrukowac" role="status">
                    <p class="m-0"><strong>Jak wydrukować tę kartkę:</strong></p>
                    <p class="m-0">Na komputerze naciśnij razem klawisze <kbd>Ctrl</kbd> i <kbd>P</kbd> (na komputerze Apple: <kbd>Cmd</kbd> i <kbd>P</kbd>).</p>
                    <p class="m-0">Na telefonie otwórz menu przeglądarki (trzy kropki albo „Udostępnij”) i wybierz „Drukuj”.</p>
                    <p class="m-0">Żeby zapisać plik zamiast drukować, wybierz drukarkę „Zapisz jako PDF”.</p>
                    <p class="m-0">Na kartce będzie sama lista — bez menu i przycisków. Zamknięcie okna drukowania niczego na liście nie zmienia.</p>
                </div>
            @endif
            <p class="mt-6 mb-3">Tak będzie wyglądać kartka:</p>
        @endif
    </div>

    @if($pozycje !== [])
        <article class="sciagawka kartka-zakupow sekcja-strony" aria-labelledby="kartka-zakupow-tytul">
            <h2 id="kartka-zakupow-tytul">Do kupienia</h2>
            <p class="kartka-zakupow-data">Stan z: {{ $dataOdczytu }}. To kopia — nie zmienia się razem z listą.</p>
            <ul class="kartka-zakupow-lista">
                @foreach($pozycje as $tekst)
                    <li>
                        <span class="kartka-zakupow-pole" aria-hidden="true"></span>
                        <span class="kartka-zakupow-tekst">{{ $tekst }}</span>
                    </li>
                @endforeach
            </ul>
        </article>
    @endif
</x-layout>
