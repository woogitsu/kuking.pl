@props(['rodzaj', 'ile', 'href' => null])

{{--
    JEDEN LICZNIK W NAGŁÓWKU PROFILU — Z ODMIENIONYM PODPISEM.

    PO CO OSOBNY SKŁADNIK
    Do tej zmiany podpisy stały wpisane na sztywno w `profile/show.blade.php`
    w jednej formie: „1 wpisów", „0 przepisów", „2 obserwujących". Polski ma
    trzy formy, nie jedną, i to jest pierwsza rzecz, którą właściciel
    zobaczył na produkcji. Formy muszą stać w JEDNYM miejscu, bo inaczej
    kolejny ekran wymyśli je po swojemu (dokładnie to zdarzyło się już
    z „Znaleziono 3 przepisów" w wyszukiwarce — patrz komentarz klasy
    `App\Support\Odmiana`).

    ODMIANĘ LICZY `App\Support\Odmiana`, a nie ten plik. Reguła „2, 3, 4
    biorą formę mnogą, ale 12, 13, 14 już nie" jest napisana i przetestowana
    raz (`tests/Unit/OdmianaTest.php`); tutaj zostaje wyłącznie TABLICA FORM,
    czyli jedyna rzecz, która jest naprawdę specyficzna dla tego ekranu.

    DLACZEGO `<li>` JEST W ŚRODKU SKŁADNIKA
    Lista liczników jest siatką (`.profil-liczniki`), a jej komórką jest
    pozycja listy. Gdyby `<li>` zostało w widoku, a składnik oddawał tylko
    jego wnętrze, to każde wywołanie musiałoby pamiętać o klasie komórki —
    czyli o tej jednej rzeczy, od której zależy równy układ.

    „razy Ugotowałem" — brzmienie z PR #235 (teksty wg COPY_STYLE.md).
    Wcześniej stało tu „razy ugotowała/ugotował", czyli ukośnik rodzajowy,
    którego COPY_STYLE.md §2 zakazuje i którego nie da się przeczytać na
    głos. Odmieniamy w tym miejscu wyłącznie liczebnik („raz" / „razy"),
    samego brzmienia nie ruszamy.

    NAZWA PRZYCISKU STOI W CUDZYSŁOWIE, I TO JEST POPRAWKA (D-091).
    Zgłoszenie właściciela: „0 razy Ugotowałem" na CUDZYM profilu brzmi
    jak zdanie w pierwszej osobie o czytającym — czyli dokładnie to, czego
    COPY_STYLE.md §2 zabrania. Sprawdzone: samo brzmienie jest umyślne
    i udokumentowane w dwóch miejscach naraz. `BRAND_EXTENDED.md` §3 każe
    nazwy własne funkcji pisać z wielkiej litery i NIE odmieniać („trzy razy
    Ugotowałem", nie „trzy ugotowałemy"), a wyjątek w
    `TekstyNiePrzypisujaPlciTest::WYJATKI` brzmi wprost: „nazwa przycisku
    W CUDZYSŁOWIE". Cudzysłowu w interfejsie jednak nie było — i bez niego
    nic nie odróżniało nazwy przycisku od czasownika.

    Dlatego poprawiamy INTERPUNKCJĘ, a nie brzmienie: „4 razy „Ugotowałem”"
    zostawia nazwę własną nietkniętą (ta sama forma w całym serwisie, tak
    jak żąda rejestr nazw), a cudzysłów mówi, że to nazwa przycisku, w który
    ta osoba klikała. Zamiana na neutralny rzeczownik („4 wykonania") byłaby
    SZÓSTĄ nazwą tej samej funkcji i złamałaby regułę „nazwa funkcji jest
    jedna i nie ma synonimów" — dlatego jej tu nie ma.

    Cudzysłów zamykający to „”" (U+201D), nie prosty znak: `{{ }}` przepuszcza
    go bez zmian, a prosty cudzysłów Blade zamieniłby na `&quot;`.
--}}

@php
    /*
     * Formy: [1, 2-4, 0 i 5+ oraz nastki].
     *
     * „obserwujący" i „obserwowany" mają dla 2-4 tę samą formę co dla 5+
     * („2 obserwujących", nie „2 obserwujący") — to rzeczownik osobowy
     * rodzaju męskiego i po liczebniku idzie dopełniacz. Nie jest to
     * pomyłka w tablicy: przy „wpisach" i „przepisach" te dwie formy
     * różnią się („2 wpisy", „5 wpisów"), przy osobach nie.
     */
    $formy = [
        'wpisy' => ['wpis', 'wpisy', 'wpisów'],
        'przepisy' => ['przepis', 'przepisy', 'przepisów'],
        'ugotowania' => ['raz „Ugotowałem”', 'razy „Ugotowałem”', 'razy „Ugotowałem”'],
        'obserwujacy' => ['obserwujący', 'obserwujących', 'obserwujących'],
        'obserwowani' => ['obserwowany', 'obserwowanych', 'obserwowanych'],
    ];

    // Literówka w nazwie licznika ma się skończyć wyjątkiem przy pierwszym
    // otwarciu strony, a nie pustym podpisem obok liczby.
    $wybrane = $formy[$rodzaj] ?? throw new InvalidArgumentException(
        'Nieznany licznik profilu: '.$rodzaj.'. Znane: '.implode(', ', array_keys($formy)).'.'
    );

    $liczba = (int) $ile;
    $podpis = \App\Support\Odmiana::rzeczownik($liczba, ...$wybrane);
@endphp

<li class="profil-licznik">
    @if($href)
        {{-- Odnośnik obejmuje CAŁĄ komórkę (liczbę i podpis), a nie samo
             słowo: „5" i „obserwujących" prowadzą w to samo miejsce, więc
             dzielenie tego na dwa cele kliknięcia byłoby tylko dwoma
             mniejszymi celami. `.profil-licznik-pole` daje mu 48 px
             wysokości (UX_50_PLUS.md). --}}
        <a class="profil-licznik-pole link-jak-tekst" href="{{ $href }}">
            <span class="stat-value">{{ $liczba }}</span> <span class="stat-label">{{ $podpis }}</span>
        </a>
    @else
        <span class="profil-licznik-pole">
            <span class="stat-value">{{ $liczba }}</span> <span class="stat-label">{{ $podpis }}</span>
        </span>
    @endif
</li>
