@props(['profile', 'isOwner', 'zeszyty', 'tagi', 'stats'])

{{--
    Prawa szyna profilu `/@nazwa` (issue #205).

    CO CZŁOWIEK, KTÓRY TU STOI, CHCE MIEĆ POD RĘKĄ — I DLACZEGO SĄ DWIE
    RÓŻNE ODPOWIEDZI

    Na WŁASNYM profilu nikt nie przychodzi czytać o sobie. Przychodzi coś
    zrobić: dodać dzisiejsze zdjęcie, wreszcie zmienić to zdjęcie profilowe,
    zajrzeć do swojego zeszytu. Te trzy rzeczy leżały do tej pory za menu
    i za Ustawieniami — a prawa trzecia ekranu stała pusta.

    Na CUDZYM profilu przychodzi się z jednym pytaniem: „co ta osoba gotuje".
    Odpowiadają na nie tagi z jej wpisów i jej publiczne zeszyty — czyli
    rzeczy, których nie widać ani w nagłówku, ani w zakładkach. „Obserwuj"
    świadomie NIE jest tu powtórzone: stoi w nagłówku, kilka centymetrów
    wyżej, i drugi taki sam przycisk na jednym ekranie każe się zastanawiać,
    czy to na pewno to samo.

    LICZBY O OSOBIE STOJĄ TU OD D-091 — I JEST TO ZMIANA WOBEC PIERWOTNEJ
    TREŚCI TEGO KOMENTARZA, WIĘC NAZYWAMY JĄ WPROST.
    Do września 2026 stało tu zdanie „żadnej liczby obserwujących", jednym
    tchem z zakazem rankingów. To było zlanie dwóch różnych rzeczy w jedną.
    AGENTS.md §12 zakazuje PORÓWNYWANIA LUDZI ZE SOBĄ: miejsc w tabeli,
    odznak, „najaktywniejszych", „więcej niż 80% kuKINGów". Nie zakazuje
    pokazania, ile ta osoba ma własnych wpisów — te same pięć liczb stało
    przez cały ten czas w karcie profilu dwa centymetry wyżej i nikt nie
    uznał ich za ranking, bo nim nie są.

    Właściciel poprosił wprost: „prawa kolumna jest marnowana, można tam dać
    info o użytkowniku (ile wpisów, przepisów, obs, obserwuj itp itd, a nie
    na środku przez co wpisy są dużo niżej".

    GRANICA ZOSTAJE TA SAMA I JEST OSTRA: liczby są WYŁĄCZNIE o treści tej
    osoby, nigdy o jej pozycji wobec innych. Blok nie sortuje, nie wyróżnia,
    nie nagradza i nie ma progu „od ilu to już dużo". Szyna dalej jest tym
    miejscem, w którym ranking wchodzi najłatwiej — bo wygląda niewinnie
    jako „ciekawostka obok" — i dalej go tu nie ma.

    ZERO NA WIDOKU. „0 przepisów" pokazujemy tak samo jak „12 przepisów".
    Chowanie zer zamieniłoby informację w wyróżnienie: widoczna liczba
    znaczyłaby wtedy „ta osoba ma czym się pochwalić", a jej brak — coś
    przeciwnego. To jest dokładnie ten sam mechanizm co ranking, tylko
    wpisany w puste miejsce.

    PUSTA SZYNA JEST DOPUSZCZALNYM WYNIKIEM — ale dotyczy to dwóch bloków
    niżej, nie liczb. Cudzy profil bez tagów i bez publicznych zeszytów nie
    dostaje ani jednego z nich. Karta „Ta osoba nie ma jeszcze nic" byłaby
    wypełniaczem, a przy grupie 50+ każdy element to coś, co trzeba
    przeczytać i pominąć (issue #205).

    PRYWATNOŚĆ. Widok NIE filtruje niczego sam — dostaje z kontrolera
    (`ProfileController::show()`) wyłącznie to, co oglądający i tak ma prawo
    zobaczyć: tagi policzone z wpisów przepuszczonych przez ten sam filtr
    widoczności co lista wpisów, a zeszyty tylko publiczne i tylko wtedy,
    gdy między tymi dwiema osobami nie ma blokady. Filtrowanie w Blade byłoby
    drugą implementacją reguły, która już istnieje — a to jest rzecz, która
    w tym repozytorium pęka najczęściej.
--}}

{{--
    LICZBY O OSOBIE — PIERWSZY BLOK SZYNY (D-091).

    STOI PIERWSZY, BO ZASTĘPUJE TO, CO STAŁO NAJWYŻEJ W KARCIE. Człowiek,
    który wczoraj widział te liczby pod opisem profilu, ma je dziś znaleźć
    na tej samej wysokości ekranu, tylko w drugiej kolumnie. Blok pod nim
    („Twoje skróty" / „Co gotuje") jest akcją albo tematem — czyli czymś
    innym niż liczby i dlatego niżej.

    TYLKO DLA ZALOGOWANEGO, I NIE JEST TO KWESTIA PRYWATNOŚCI.
    Te liczby gość widzi w karcie, tak jak dotąd, i to jest CAŁY powód.

    SPROSTOWANIE, 11 WRZEŚNIA 2026 (D-122). Stało tu, że gość „dostaje
    `.app-body-solo`, czyli JEDNĄ kolumnę na każdej szerokości — szyna leci
    u niego pod treścią nawet przy 1512 px". Od dziś nieprawda: gość na
    ekranie z szyną (profil jest takim ekranem) ma od 80rem dwie kolumny,
    a szyna stoi obok treści. Wniosek zostaje ten sam, ale opiera się teraz
    na czym innym: liczby już raz stoją w KARCIE, której gościowi nie
    chowamy (reguła w `ekran-profilu.css` wyklucza układ gościa), więc
    wypisanie tego bloku byłoby wypisaniem DRUGIEGO, widocznego egzemplarza
    tych samych liczb na jednym ekranie — a nie, jak dawniej, martwego
    egzemplarza na dole strony. Tym gorzej, nie lepiej.

    Egzemplarz w karcie chowa się od 80rem — para reguł przy
    `.profil-liczby-*` w `ekran-profilu.css`. Poniżej 80rem jest odwrotnie
    i to TEN blok znika, bo szyna ląduje wtedy pod całym archiwum wpisów.
--}}
@auth
    <div class="profil-liczby-szyna">
        <x-szyna-blok
            :tytul="$isOwner ? 'Twoje liczby' : 'Ta osoba w liczbach'"
            id="szyna-liczby-profilu"
            ikona="user">
            <x-liczby-profilu :stats="$stats" :username="$profile->username" wariant="szyna" />
        </x-szyna-blok>
    </div>
@endauth

@if($isOwner)
    {{-- BEZ „Zajmuje niecałą minutę" przy pierwszym skrócie: obietnica
         z miarą, której nie mierzymy, a czas zależy od tego, jak szybko pójdzie
         zdjęcie z telefonu. Podpis mówi teraz, z czego ten wpis się składa —
         to samo zdanie co w `AGENTS.md` §1. --}}
    <x-szyna-blok tytul="Twoje skróty" id="szyna-skroty" ikona="plus">
        <x-szyna-linki :pozycje="[
            [
                'href' => route('posts.create'),
                'nazwa' => 'Dodaj zdjęcie i kilka słów',
                'podpis' => 'Najprostsza rzecz. Wystarczy zdjęcie i kilka słów.',
            ],
            [
                'href' => route('recipes.create'),
                'nazwa' => 'Napisz przepis',
                'podpis' => 'Żeby ktoś inny mógł to u siebie zrobić.',
            ],
            [
                'href' => route('settings.avatar'),
                'nazwa' => $profile->avatar?->isReady() ? 'Zmień zdjęcie profilowe' : 'Dodaj zdjęcie profilowe',
                'podpis' => 'Po zdjęciu ludzie poznają, że po drugiej stronie jest człowiek.',
            ],
        ]" />
    </x-szyna-blok>
@elseif($tagi->isNotEmpty())
    <x-szyna-blok tytul="Co gotuje" id="szyna-tagi" ikona="chef">
        {{-- Chipy, nie lista odnośników: tag jest krótkim słowem, a nie
             pozycją z wyjaśnieniem. `.chip` ma 48 px wysokości i 18 px
             tekstu, więc wybór jest ten sam co na „Szukaj". --}}
        <nav class="chipsy mt-0 mb-0" aria-label="Tagi z wpisów tej osoby">
            @foreach($tagi as $tag)
                <a class="chip" href="{{ route('tags.show', $tag) }}">{{ $tag->name }}</a>
            @endforeach
        </nav>
    </x-szyna-blok>
@endif

@if($zeszyty->isNotEmpty())
    <x-szyna-blok
        :tytul="$isOwner ? 'Twoje zeszyty' : 'Zeszyty tej osoby'"
        id="szyna-zeszyty-profilu"
        ikona="book"
        :wiecej="$isOwner ? route('collections.index') : null">
        {{-- ŚWIADOMIE BEZ LICZNIKA „12 przepisów".

             Licznik `withCount` liczy WSZYSTKO, co w zeszycie leży, a strona
             zeszytu pokazuje tylko to, co oglądający ma prawo zobaczyć.
             Rozjazd między tymi dwiema liczbami zdradzałby, że coś tam jednak
             jest — ten sam „oracle istnienia", który `ProfileController`
             naprawił już przy licznikach obserwujących. Nazwa zeszytu wystarczy
             do tego, po co ta lista jest: żeby do niego wejść. --}}
        <x-szyna-linki :pozycje="$zeszyty->map(fn ($zeszyt) => [
            'href' => route('collections.show', $zeszyt),
            'nazwa' => $zeszyt->name,
            'podpis' => $zeszyt->description,
        ])->all()" />
    </x-szyna-blok>
@endif
