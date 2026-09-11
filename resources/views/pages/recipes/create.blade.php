{{--
    DODAWANIE PRZEPISU — SZEŚĆ RZECZY I KONIEC (issue #364).

    Co tu było wcześniej: formularz, na którym przepis o ośmiu składnikach
    i trzech krokach pokazywał ~89 kontrolek (9 pól u góry, 9 kółek wyboru,
    siedem pól NA KAŻDY składnik, pięć na każdy krok). Właściciel zmierzył to
    na sobie — „zbyt skomplikowane dla mnie, 32-latka, a co dopiero dla
    seniora" — i to nie była przesada: wkleił listę składników do pola
    „Krótko o przepisie", bo formularz kazał decydować o strukturze, zanim
    pozwolił cokolwiek napisać. Człowiek OBCHODZI wtedy formularz, zamiast go
    wypełniać, a to jest usterka kolejności pytań, nie usterka pola.

    Zostaje sześć rzeczy, w tej kolejności:

        zdjęcie · tytuł · składniki · przygotowanie · kto ma widzieć · Opublikuj

    SKŁADNIKI SĄ NIEOBOWIĄZKOWE — ZGODA WŁAŚCICIELA Z 11.09.2026.
    Przepis wolno opublikować bez ani jednego składnika. To nie jest
    przeoczenie ani luka w walidacji i nie wolno tego „naprawić": pilnuje tego
    test `DodawaniePrzepisuSzescKontrolekTest`, żeby za pół roku nikt nie
    uznał, że zgoda była przypadkiem.

    BAZA SIĘ NIE ZMIENIA. Oba pola tekstowe serwer rozbija z powrotem na
    `recipe_ingredients` i `recipe_steps` (`App\Domain\Recipes\TekstNaWiersze`),
    więc przeliczanie porcji i szukanie po składnikach działają dalej.

    RESZTA NIE ZNIKA, TYLKO PRZESTAJE STAĆ NA DRODZE. Krótko o przepisie,
    porcje, czasy, trudność, „po kim", historia, rok, źródło, grupy
    składników, uwagi, „Bez ilości", zdjęcia do kroków i przestawianie wierszy
    są na ekranie „Dopisz szczegóły" (`pages/recipes/szczegoly.blade.php`
    oraz kreator w trzech krokach) — PO opublikowaniu i nieobowiązkowo.
--}}
<x-layout title="Dodaj przepis" :noindex="true">
    <x-zakladki-dodawania aktywna="przepis" />

    <h1>Dodaj przepis</h1>
    <p class="mb-5">
        Wystarczy zdjęcie, nazwa i to, co robisz. Resztę — porcje, czasy, historię
        przepisu — dopiszesz później, jeśli zechcesz.
    </p>

    <x-error-summary />

    {{-- Jeden panel na cały formularz, bez sekcji: przy sześciu rzeczach nie
         ma czego rozdzielać nagłówkami (docs/design/ROLE_KART.md — cztery
         mocne obwódki na ekranie nie odróżniają już niczego). --}}
    <form class="panel-formularza" id="formularz-przepisu" method="POST"
          action="{{ route('recipes.store') }}" enctype="multipart/form-data">
        @csrf

        {{-- 1. ZDJĘCIE.
             Natywne pole pliku jest schowane dla oka (D-035) — rysowało
             angielskie „Choose File / No file chosen" w polskim formularzu.
             Zostaje pod klawiaturą i w drzewie dostępności, klikalna jest
             etykieta, a `<input>` MUSI stać bezpośrednio przed nią, bo
             obwódkę fokusu rysuje reguła sąsiedztwa w
             resources/css/ekran-dodawania.css. --}}
        <div class="field @error('hero_photo') has-error @enderror">
            <span class="pole-zdjecia-nazwa" id="f-hero_photo-etykieta">Zdjęcie gotowego dania</span>
            <input class="visually-hidden pole-zdjecia-input" id="f-hero_photo" type="file" name="hero_photo"
                   accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                   aria-labelledby="f-hero_photo-etykieta f-hero_photo-tytul"
                   aria-describedby="f-hero_photo-help">
            <label class="pole-zdjecia" for="f-hero_photo">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-hero_photo-tytul">Dodaj zdjęcie</span>
                <span class="field-help" id="f-hero_photo-help">To zdjęcie zobaczą ludzie na liście przepisów.</span>
            </label>
            @error('hero_photo')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        {{-- 2. TYTUŁ --}}
        <x-field name="title" label="Nazwa przepisu" required
                 placeholder="Rosół babci Zofii" />

        {{-- 3. SKŁADNIKI — jedno pole, jeden składnik na wiersz.

             „Pisz tak, jak mówisz" stoi w kreatorze od początku, a stało obok
             siedmiu pól na jeden składnik. Teraz jest prawdą: cały wiersz
             idzie do bazy tak, jak go człowiek napisał (D-017 — składnik jest
             wolnym tekstem, bo „tyle, żeby ciasto było miękkie" nie ma pola
             na ilość). --}}
        <x-field name="skladniki_tekst" label="Składniki" type="textarea" :rows="8"
                 placeholder="1 kurczak, najlepiej zagrodowy&#10;2 marchewki&#10;pietruszka&#10;sól do smaku"
                 help="Każdy składnik w osobnej linijce. Pisz tak, jak mówisz: „szklanka mąki”, „2 duże cebule”, „mleko — ile weźmie”. Nie musisz nic przeliczać na gramy. To pole możesz zostawić puste i dopisać składniki później." />

        {{-- 4. PRZYGOTOWANIE — jedno pole, pusta linia rozdziela kroki. --}}
        <x-field name="przygotowanie_tekst" label="Przygotowanie" type="textarea" :rows="10"
                 placeholder="Kurczaka zalej zimną wodą i zagotuj. Zbierz szumowiny.&#10;&#10;Wrzuć warzywa i gotuj na małym ogniu trzy godziny.&#10;&#10;Posól na końcu."
                 help="Pisz spokojnie, po swojemu. Zostaw pustą linijkę tam, gdzie zaczyna się nowa czynność — zrobimy z tego osobne kroki." />

        {{-- 5. KTO MA WIDZIEĆ — dwie opcje, bo to jest pytanie o prywatność,
             a nie o ustawienia. Trzecia możliwość („tylko obserwujący")
             została, ale na ekranie „Dopisz szczegóły": przy pierwszej
             publikacji rozstrzyga się „pokazać czy schować", a nie komu
             dokładnie. --}}
        <fieldset class="border-0 p-0 mt-6">
            <legend class="font-bold mb-3">Kto ma widzieć ten przepis?</legend>
            <div class="choice-grid">
                <label class="choice">
                    <input type="radio" name="visibility" value="public" @checked(old('visibility', 'public') === 'public')>
                    <span><span class="choice-label">Wszyscy</span><span class="choice-help">Także osoby bez konta. Przepis może pojawić się w Google.</span></span>
                </label>
                <label class="choice">
                    <input type="radio" name="visibility" value="private" @checked(old('visibility') === 'private')>
                    <span><span class="choice-label">Tylko ja</span><span class="choice-help">Twój prywatny zeszyt. Zmienisz to, kiedy zechcesz.</span></span>
                </label>
            </div>
            @error('visibility')<span class="field-error">{{ $message }}</span>@enderror
        </fieldset>

        {{-- 6. OPUBLIKUJ. Jeden przycisk, bo jedna decyzja.

             „Zapisz szkic" tu nie stoi i to jest świadome: przy sześciu
             rzeczach szkic jest wyborem bez treści — a przepis schowany
             wybiera się wyżej, kółkiem „Tylko ja", i wtedy jest normalnym,
             skończonym przepisem, a nie czymś niedokończonym. --}}
        <div class="form-actions">
            <button class="btn btn-primary" type="submit" name="action" value="publish">Opublikuj</button>
            <a class="btn btn-quiet" href="{{ route('home') }}">Nie teraz</a>
        </div>
    </form>

    <p class="field-help mt-8">
        Po opublikowaniu dopiszesz resztę: porcje, czasy, po kim jest ten przepis
        i jego historię. Nic z tego nie jest potrzebne teraz.
    </p>
</x-layout>
