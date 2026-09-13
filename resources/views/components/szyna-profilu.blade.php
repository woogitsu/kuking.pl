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

    PRYWATNOŚĆ. Widok NIE filtruje niczego sam — dostaje z kontrolera
    (`ProfileController::show()`) wyłącznie to, co oglądający i tak ma prawo
    zobaczyć: tagi policzone z wpisów przepuszczonych przez ten sam filtr
    widoczności co lista wpisów, a zeszyty tylko publiczne i tylko wtedy,
    gdy między tymi dwiema osobami nie ma blokady. Filtrowanie w Blade byłoby
    drugą implementacją reguły, która już istnieje — a to jest rzecz, która
    w tym repozytorium pęka najczęściej.
--}}



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
