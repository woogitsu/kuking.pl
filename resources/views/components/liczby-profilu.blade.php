@props(['stats', 'username', 'wariant'])

{{--
    PIĘĆ LICZB O OSOBIE — JEDNA TREŚĆ, DWA MIEJSCA NA STRONIE (D-091).

    ZGŁOSZENIE WŁAŚCICIELA, DOSŁOWNIE
    „jestem na profilu użytkownika, patrz prawa kolumna jest marnowana, można
    tam dać info o użytkowniku (ile wpisów, przepisów, obs, obserwuj itp itd,
    a nie na środku przez co wpisy są dużo niżej".

    Pięć wierszy liczb stało w karcie profilu, między opisem a rzędem
    przycisków. Karta rosła przez to o pięć wierszy po 48 px, a pierwszy wpis
    — czyli treść, po którą się na profil wchodzi — zaczynał się poniżej
    zgięcia, mimo że prawa trzecia ekranu stała pusta na całej wysokości.

    PO CO OSOBNY SKŁADNIK, SKORO TO BYŁ ZWYKŁY `<ul>` W WIDOKU
    Bo od tej zmiany ta sama lista stoi w DWÓCH miejscach dokumentu: w karcie
    i w prawej szynie. Skopiowana zostałaby dwiema listami, które rozjeżdżają
    się przy pierwszej poprawce — a rozjazd byłby niewidoczny, bo w danej
    chwili widać dokładnie jedną z nich (patrz niżej).

    DLACZEGO DWA EGZEMPLARZE W HTML-U, A NIE JEDEN PRZESTAWIANY CSS-em
    Bo przestawić się nie da. Szyna (`<aside class="app-rail">`) jest
    RODZEŃSTWEM `<main>`, nie jego wnętrzem — żadne `order`, `float` ani
    `grid-area` nie wsunie elementu z szyny do środka karty profilu. Jedyne
    narzędzie, które by to zrobiło, to skrypt przenoszący węzeł przy zmianie
    szerokości, a AGENTS.md §5 wymaga, żeby ważne rzeczy działały BEZ
    JavaScriptu. Zostaje więc jedna treść wypisana dwa razy i arkusz, który
    pokazuje dokładnie jeden egzemplarz:

      - poniżej 80rem (telefon, tablet) widać egzemplarz W KARCIE,
      - od 80rem u zalogowanego widać egzemplarz W SZYNIE.

    Reguły stoją przy `.profil-liczby-*` w `ekran-profilu.css` i są PARĄ:
    jedna pokazuje, druga chowa, obie w tym samym zapytaniu o szerokość.
    Egzemplarz schowany przez `display: none` wypada z drzewa dostępności,
    więc czytnik ekranu czyta te liczby raz, nie dwa razy.

    NA TELEFONIE LICZBY NIE ZNIKAJĄ. To był główny sposób, w jaki ta poprawka
    mogła wyjść gorzej niż stan przed nią: `.app-rail` poniżej 80rem nie chowa
    się, tylko LĄDUJE POD TREŚCIĄ — czyli pod całym archiwum wpisów. Przenieść
    liczby do szyny „na stałe" znaczyłoby zepchnąć je na sam dół strony
    telefonu, kilkanaście ekranów przewijania od profilu.

    KOLEJNOŚĆ I ADRESY BEZ ZMIAN: wpisy, przepisy, Ugotowałem, obserwujący,
    obserwowani; dwa ostatnie dalej prowadzą do tych samych list osób.

    §12 (bez rankingów): to są liczby o WŁASNEJ treści tej osoby, bez
    porównania z kimkolwiek. Przeniesienie ich do szyny niczego w tym nie
    zmienia — nie ma tu miejsca w tabeli, odznaki ani „więcej niż 80%
    kuKINGów" i nie będzie.
--}}

<ul
    class="profil-liczniki {{ $wariant === 'szyna' ? 'profil-liczby-w-szynie' : 'profil-liczby-karta' }}"
    aria-label="Liczby tego profilu">
    <x-licznik-profilu rodzaj="wpisy" :ile="$stats['posts']" />
    <x-licznik-profilu rodzaj="przepisy" :ile="$stats['recipes']" />
    <x-licznik-profilu rodzaj="ugotowania" :ile="$stats['cooked']" />
    <x-licznik-profilu
        rodzaj="obserwujacy"
        :ile="$stats['followers']"
        :href="route('social.followers', $username)" />
    <x-licznik-profilu
        rodzaj="obserwowani"
        :ile="$stats['following']"
        :href="route('social.following', $username)" />
</ul>
