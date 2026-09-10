@props(['ile' => 0])

{{--
    LICZNIK PRZY POZYCJI PANELU MODERACJI — „ile czeka na Ciebie w tej kolejce".

    ZERO NIE POKAZUJE NICZEGO, a nie „0". Pusta kolejka jest stanem
    normalnym i najczęstszym; pięć zer na każdym ekranie panelu to sam
    hałas — mówią tyle samo, co ich brak, tylko zajmują uwagę. Plakietka ma
    znaczyć „tu jest praca", więc pojawia się wyłącznie wtedy, gdy jest.

    CZYTNIK EKRANU SŁYSZY PEŁNE ZDANIE, NIE SAMĄ LICZBĘ. Widoczna liczba
    („2") jest dla oczu i dostaje `aria-hidden`; obok stoi ten sam licznik
    słowami, ukryty wzrokowo. Nazwę kolejki niesie już tekst odnośnika,
    w którym ta plakietka stoi, więc pozycja „Odwołania" czyta się jako
    „Odwołania, 2 czekają". Dwa osobne elementy, a nie liczba i doklejone
    słowo w jednym: liczba i tekst w jednym węźle sklejają się u części
    czytników w „2czekają".

    DLACZEGO „czeka/czekają", A NIE „nowe". Bo to jedno słowo obsługuje
    wszystkie pięć pozycji bez zgadywania rodzaju rzeczownika:
    „1 zgłoszenie nowe", „2 wiadomości nowe" i „5 sygnałów nowych" mają
    trzy różne formy przymiotnika i pierwsza wersja tego komponentu
    pisałaby połowę z nich błędnie. Czasownik zgadza się z samą liczbą
    (`Odmiana::rzeczownik`, ta sama klasa co „N kuKINGów" w stopce)
    i dodatkowo mówi WPROST to, co ta plakietka ma znaczyć — że coś czeka,
    a nie że czegoś jest tyle.

    ROZMIAR 18 px (`--text-body`), nie 16 px jak zwykła `.badge`.
    W plakietce liczba jest CAŁĄ informacją, nie dopiskiem obok większego
    tekstu — a wyjątek na 16 px z `docs/design/DESIGN_SYSTEM.md` §2.1
    dotyczy właśnie dopisków. AGENTS.md §5 obowiązuje tu bez wyjątku
    (D-051 jest wyjątkiem dla stopki, nie dla panelu).

    KOLOR: `--color-accent-tint`, ten sam co licznik nieprzeczytanych
    powiadomień w tym samym menu — NIGDY `--color-danger`. Dwie plakietki
    tuż obok siebie znaczące „coś nowego czeka" muszą wyglądać tak samo,
    inaczej trzeba się uczyć dwóch sygnałów zamiast jednego. Czerwony
    zostaje dla awarii; kolejka moderacji to miejsce pracy, nie alarm
    (ta sama reguła, którą stosuje sekcja „Panel moderacji" w menu).
--}}
@if($ile > 0)
    <span class="badge licznik-kolejki">
        <span aria-hidden="true">{{ $ile }}</span>
        <span class="visually-hidden">{{ $ile }} {{ \App\Support\Odmiana::rzeczownik($ile, 'czeka', 'czekają', 'czeka') }}</span>
    </span>
@endif
