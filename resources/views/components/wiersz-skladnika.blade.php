@props(['skladnik'])

{{--
    JEDEN WIERSZ SKŁADNIKA — JEDNO ŹRÓDŁO DLA WSZYSTKICH EKRANÓW.

    PO CO OSOBNY SKŁADNIK
    Ten sam wiersz stoi na stronie przepisu (`pages/recipes/show.blade.php`)
    i w trybie gotowania (`pages/recipes/cooking.blade.php`). Dopóki był
    przepisany w obu plikach, dwie gałęzie mogły w dobrej wierze nadać mu
    dwa sprzeczne kontrakty i nic ich nie zderzyło — git nie zgłasza
    konfliktu między plikami, które się nie dotykają. Teraz zmiana wiersza
    trafia w JEDEN plik, więc druga zmiana tego samego wiersza wywoła
    konflikt, zamiast po cichu wpuścić dwie różne obietnice dla tego samego
    składnika. To ten sam wzór, którym rozwiązano grupy składników:
    `App\Domain\Recipes\GrupySkladnikow::ulozyc()` liczy układ raz, a widoki
    go tylko czytają.

    „BEZ PODANEJ ILOŚCI", NIE „DO SMAKU" (#878 rozstrzyga #764).
    Do tej zmiany składnik z `no_amount` dostawał dopisek „— do smaku".
    To zdanie NIE jest prawdziwe dla większości takich składników: „mleko
    ile weźmie" mówi o konsystencji ciasta, a „olej do smażenia" o tym, do
    czego olej służy. Ani jedno, ani drugie nie jest doprawianiem, a ekran
    twierdził, że jest.

    Sam brak dopisku też nie wystarcza — bez niego pusta ilość wygląda jak
    przeoczenie autora, a #764 domaga się dokładnie tego rozróżnienia.
    Dlatego zostaje oznaczenie, ale NEUTRALNE.

    DLACZEGO TE SŁOWA
    - „bez podanej ilości" opisuje, czego na ekranie nie ma, i ani słowem
      nie mówi, ile czego wsypać — a każde zdanie o dozowaniu („ile kto
      lubi", „według uznania", „do smaku") byłoby zgadywaniem za autora
      i wróciłoby do kłamstwa, które #878 zmierzyło.
    - „podanej" niesie to, o co prosi #764: ilość nie została podana, bo
      autor jej nie podaje, a nie dlatego, że coś się zgubiło po drodze.
    - Słowa są codzienne i krótkie, bez terminów z formularza („flaga",
      „bez ilości" jako etykieta pola) — czytelnik 50+ ma to zrozumieć
      z jednego przeczytania, nie kojarzyć z ustawieniem w kreatorze.

    OZNACZENIE DOSTAJE KAŻDY SKŁADNIK Z `no_amount`, bez wyjątków.
    Poprzednia wersja wygaszała dopisek, gdy autor sam napisał w tekście
    „do smaku" — bo „sól do smaku — do smaku" wyglądało jak usterka. To
    obchodzenie powtórzenia przestało być potrzebne razem z dopiskiem,
    który je powodował: „sól do smaku — bez podanej ilości" nie powtarza
    niczego i jest prawdą. Wyjątek zniknął, więc nie ma już reguły, która
    czyta tekst autora i na jego podstawie zmienia kontrakt ekranu.
--}}
<li>
    {{ $skladnik->ingredient_text }}
    @if($skladnik->no_amount)<span class="meta"> — bez podanej ilości</span>@endif
    @if($skladnik->note)<span class="meta"> — {{ $skladnik->note }}</span>@endif
</li>
