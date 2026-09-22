{{--
    ZAKŁADKI DODAWANIA — „Zdjęcie i kilka słów” albo „Cały przepis” (issue #366).

    PO CO TO JEST
    Ekran `/dodaj` z dwoma kaflami istnieje od dawna i jest dobrze napisany —
    tylko prawie nikt na niego nie trafia. Policzone w #366: z jedenastu drzwi
    do dodawania OSIEM wpada od razu w prosty formularz zdjęcia, a trzy w
    kreator przepisu. Człowiek, który wszedł kaflem „Dodaj zdjęcie tego, co
    ugotowałeś” na stronie głównej, nie miał jak się dowiedzieć, że drugie
    wyjście w ogóle istnieje. Stąd zgłoszenie właściciela: „nieważne gdzie
    kliknie by coś opublikować (…) powinny być dwie opcje do wyboru”.

    DLACZEGO DWA ODNOŚNIKI, A NIE PRZEŁĄCZNIK PRZEBUDOWUJĄCY FORMULARZ
    Właściciel zaproponował przełącznik u góry formularza, który wymienia pola
    pod palcem. Rozstrzygnięcie w #366 jest inne i ma trzy powody:

    1. Strona przebudowująca się w trakcie to przy 50+ realny koszt — człowiek
       gubi miejsce, w którym był, i nie ma jak wrócić do stanu sprzed zmiany.
    2. Dwa zwykłe odnośniki działają bez JavaScriptu. D-053 pozwala wymagać
       skryptu, ale nie każe — a tu nie ma za co płacić.
    3. Adres da się wysłać rodzinie. Przełącznik nie ma adresu.

    Efekt dla użytkownika jest ten sam, o który prosił właściciel: wchodzi
    jednymi drzwiami i OD RAZU widzi, że są dwie możliwości.

    CEL DOTYKOWY JEST LICZONY W PIKSELACH, NIE W `rem` (D-082, D-107)
    48 px zakładki istnieje dla palca, a palec nie rośnie, gdy ktoś powiększy
    czcionkę w przeglądarce. Zapis w `rem` podwaja się razem z korzeniem
    i przy czcionce 200% robi z rzędu zakładek pasek na pół ekranu — dokładnie
    to, co D-107 wycięło z dolnej belki. Docelowy blok CSS (patrz niżej) ma
    więc `min-height: 48px`, a nie `var(--control-height-min)` (3rem).

    KLASY: DOCELOWE PLUS TYMCZASOWE
    `.zakladki-dodawania` i `.zakladka-dodawania` to nazwy docelowe — po nie
    sięgnie arkusz, gdy właściciel `app.css` doda blok z issue #366. Do tego
    czasu wygląd niosą ISTNIEJĄCE klasy `.chipsy` / `.chip` (pigułka z obwódką
    2 px, `min-height` 48 px, pismo 18 px, stan `aria-current` na tle marki)
    i utility `gap-2` na odstęp między ikoną a napisem. Dzięki temu zakładki
    są czytelne i klikalne od pierwszego wdrożenia, a nie dopiero po scaleniu
    zmiany w arkuszu. Po dodaniu bloku CSS `chip` i `gap-2` można stąd zdjąć.

    IKONA NIGDY SAMA (AGENTS.md §5): obok każdej ikony stoi pełny napis,
    a `x-ikona` jest z założenia niemy dla czytnika ekranu.
--}}

@props(['aktywna'])

@php
    $pozycje = [
        'zdjecie' => [
            'href' => route('posts.create'),
            'ikona' => 'image',
            // Ta sama nazwa co kafel na `/dodaj` — dwa różne napisy na tę samą
            // rzecz znaczą dla człowieka dwie różne rzeczy.
            'napis' => 'Zdjęcie i kilka słów',
        ],
        'przepis' => [
            'href' => route('recipes.create'),
            'ikona' => 'chef',
            'napis' => 'Cały przepis',
        ],
    ];

    if (! array_key_exists($aktywna, $pozycje)) {
        // Błąd programisty, nie użytkownika: bez tego rzutu literówka w nazwie
        // zakładki dałaby ekran, na którym ŻADNA nie jest bieżąca — czyli
        // cichą utratę tego, co ten komponent ma nieść.
        throw new \InvalidArgumentException(
            'Zakładka dodawania przyjmuje „zdjecie” albo „przepis”, dostała: '.$aktywna
        );
    }
@endphp

<nav class="zakladki-dodawania chipsy" aria-label="Co chcesz dodać">
    @foreach($pozycje as $klucz => $pozycja)
        <a class="zakladka-dodawania chip gap-2"
           href="{{ $pozycja['href'] }}"
           @if($klucz === $aktywna) aria-current="page" @endif>
            <x-ikona :nazwa="$pozycja['ikona']" :rozmiar="24" />
            <span class="zakladka-dodawania-napis">{{ $pozycja['napis'] }}</span>
        </a>
    @endforeach
</nav>
