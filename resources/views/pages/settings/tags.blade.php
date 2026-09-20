{{--
    „Twoje tagi" — jeden ekran z listą do zaznaczania (D-021, zastępuje
    `pages/settings/topics.blade.php`).

    Lista to SUMA tagów już obserwowanych i tagów promowanych (D-021, „tag
    promowany — lista gospodarza") — patrz komentarz w
    `TagFollowController::edit()`, dlaczego to musi być suma, nie sama lista
    promowana: wszechświat tagów nie jest zamknięty.

    ────────────────────────────────────────────────────────────────────────
    #858: FILTR I „POKAŻ KOLEJNE" — I DLACZEGO BEZ JEDNEJ LINII SKRYPTU

    Powód jest zmierzony: sto tagów to 8 377 px wysokości przy 320 px,
    a przy tekście 200% — 27 515 px (`docs/research/OBSERWOWANIE_TAGOW_2026-09-20.md`).

    WARIANT BEZ SKRYPTU WYBRANY ŚWIADOMIE, I JEST TO WARIANT JEDYNY.
    D-053 pozwala wymagać JavaScriptu poza formularzami chronionymi captchą
    i zabrania jednego: przycisku, który po kliknięciu milczy. Drugą
    dopuszczalną drogą było „filtrowanie skryptem, a bez skryptu cała lista".
    Odrzucona, bo zostawia 27 515 px dokładnie tym osobom, którym skrypt się
    NIE DOCIĄGNĄŁ — czyli tym na jednej kresce zasięgu, dla których ten ekran
    jest najdroższy. Odwracałaby więc poprawkę tam, gdzie najbardziej boli.

    Zamiast tego filtr i doładowanie są zwykłymi przyciskami `submit` tego
    samego formularza. Skutki, wszystkie po stronie zysku:

    * martwego przycisku nie da się tu zrobić — nie ma skryptu, który mógłby
      się nie dociągnąć (D-053 spełniona przez nieobecność, nie przez obietnicę);
    * jedna droga zamiast dwóch, więc nie ma wersji zapasowej, której nikt
      nie używa, nikt nie testuje i która cicho gnije;
    * każde naciśnięcie zabiera ze sobą CAŁY wybór, bo wysyła formularz —
      odnośnik zabrałby tylko adres i skasował zaznaczenia zrobione wcześniej.

    Cena: jedno przeładowanie strony na filtrowanie i jedno na doładowanie.
    Przy grupie 50+ jest to cena właściwa — przeładowanie jest widoczne
    i przewidywalne, a lista, która przestawia się sama pod palcami, nie jest.

    KOLEJNOŚĆ PRZYCISKÓW NIE JEST KOSMETYKĄ. Enter w polu tekstowym wyzwala
    PIERWSZY przycisk `submit` formularza. „Pokaż pasujące" stoi więc przed
    „Zapisz" — Enter po wpisaniu frazy filtruje, a nie zapisuje. Filtrowanie
    niczego nie zmienia i wraca z pełnym wyborem, więc pomyłka nic nie kosztuje;
    odwrotna kolejność zapisywałaby zmiany, których nikt nie potwierdził.

    „Załaduj więcej" przyciskiem, nie nieskończonym przewijaniem — AGENTS.md §5
    i decyzja właściciela z 20.09.2026: przewijanie bez końca odbiera orientację,
    gdzie się jest.
--}}
<x-layout title="Twoje tagi" :noindex="true">
    <h1>Twoje tagi</h1>

    <p class="text-lead">
        Gdy nie ma wpisów od obserwowanych osób, pokazujemy wpisy z Twoich tagów. Jeśli i tam jest pusto, zobaczysz najnowsze publiczne wpisy.
    </p>

    <x-error-summary />

    @if($wszystkich === 0)
        <div id="f-tags" tabindex="-1"><x-blad-grupy name="tags" /></div>
        <x-empty-state
            title="Nie obserwujesz jeszcze żadnego tagu"
            action="Zobacz wszystkie tagi"
            :href="route('tags.index')">
            <p class="mb-0">
                Wybierz tag i kliknij „Obserwuj ten tag" — albo wróć tutaj,
                gdy gospodarz doda pierwsze propozycje.
            </p>
        </x-empty-state>
    @else
        <form method="POST" action="{{ route('settings.tags.update') }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="form_scope" value="{{ $formScope }}">
            <input type="hidden" name="ile" value="{{ $ile }}">

            {{--
                FILTR. Etykieta widoczna, nie placeholder — `docs/UX_50_PLUS.md`.
                `type="search"` daje na telefonie krzyżyk czyszczący pole, ale samo
                wyczyszczenie pola listy jeszcze nie zmienia — dlatego obok stoi
                przycisk, a przy włączonym filtrze drugi, wychodzący z niego naraz.
            --}}
            <div class="field tagi-filtr">
                <label for="f-szukaj">Szukaj wśród tagów</label>
                <span class="field-help" id="f-szukaj-help">
                    Wpisz kawałek nazwy, na przykład „zupa". Polskie znaki nie mają znaczenia —
                    „zurek" znajdzie „żurek". Tagi, których filtr nie pokazuje, zostają zaznaczone
                    tak jak były.
                </span>
                <div class="tagi-filtr-wiersz">
                    <input class="field-input" id="f-szukaj" name="szukaj" type="search"
                           value="{{ $szukaj }}" aria-describedby="f-szukaj-help" autocomplete="off">
                    <button class="btn btn-secondary" type="submit" name="akcja" value="filtruj">Pokaż pasujące</button>
                    {{-- Wyjście z filtra JEDNYM naciśnięciem. Samo pole `type="search"`
                         daje krzyżyk, ale wyczyszczenie pola jeszcze nic nie zmienia —
                         trzeba by je wyczyścić I nacisnąć „Pokaż pasujące". Ten przycisk
                         stoi tylko wtedy, gdy jest z czego wychodzić. --}}
                    @if($szukaj !== '')
                        <button class="btn btn-secondary" type="submit" name="akcja" value="wszystkie">Pokaż wszystkie tagi</button>
                    @endif
                </div>
            </div>

            <fieldset id="f-tags" class="choice-fieldset" @error('tags') aria-invalid="true" aria-describedby="f-tags-error" tabindex="-1" @enderror>
                <legend class="sr-only">Wybierz tagi do obserwowania</legend>

                {{--
                    WYBÓR, KTÓREGO NIE WIDAĆ, A KTÓRY MUSI DOJECHAĆ NA SERWER.
                    Tag zaznaczony i schowany przez filtr albo stojący za oknem
                    doładowania nie ma tu pola wyboru — bez tych pól ukrytych
                    zapis policzyłby go jako odznaczony i odobserwował temat,
                    którego człowiek nawet nie widział. Uzasadnienie i granice:
                    `App\Domain\Tags\TagFollowWindow`.
                --}}
                @foreach($ukryte as $ukrytyId)
                    <input type="hidden" name="tags[]" value="{{ $ukrytyId }}">
                @endforeach

                @if($tags->isEmpty())
                    {{-- Komunikat mówi, CO ZROBIĆ, i od razu daje czym to zrobić. --}}
                    <p class="tagi-pusto">
                        Żaden z Twoich tagów ani z propozycji gospodarza nie pasuje do „{{ $szukaj }}".
                        Wpisz krótszy fragment nazwy i naciśnij „Pokaż pasujące" — albo naciśnij
                        „Pokaż wszystkie tagi", żeby wrócić do całej listy. Twoje zaznaczenia zostają.
                    </p>
                @else
                    {{-- `role="status"` — po przeładowaniu czytnik ekranu ma powiedzieć,
                         ile z czego widać, zamiast kazać liczyć pozycje. --}}
                    <p class="tagi-licznik" role="status">
                        @if($szukaj === '')
                            Widzisz {{ $tags->count() }} z {{ $wszystkich }} {{ \App\Support\Odmiana::rzeczownik($wszystkich, 'tagu', 'tagów', 'tagów') }}.
                        @else
                            Do „{{ $szukaj }}" pasuje {{ $pasujacych }} {{ \App\Support\Odmiana::rzeczownik($pasujacych, 'tag', 'tagi', 'tagów') }}; widzisz {{ $tags->count() }}.
                        @endif
                    </p>

                    <div class="choice-grid">
                        @foreach($tags as $index => $tag)
                            <label class="choice" id="f-tags-{{ $index }}">
                                <input type="checkbox" name="tags[]" value="{{ $tag->getKey() }}"
                                       @checked(in_array($tag->getKey(), $wybrane, true))>
                                <span class="choice-label">{{ $tag->name }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif

                <x-blad-grupy name="tags" />
            </fieldset>

            {{-- Przycisk pojawia się TYLKO wtedy, gdy naprawdę coś dojdzie,
                 i mówi ile — „Pokaż więcej" bez liczby nie daje orientacji,
                 gdzie się jest, a o to w tej decyzji chodziło. --}}
            @if($pozostalo > 0)
                <p class="tagi-doladuj">
                    <button class="btn btn-secondary" type="submit" name="akcja" value="wiecej">
                        Pokaż kolejne {{ $dojdzie }} {{ \App\Support\Odmiana::rzeczownik($dojdzie, 'tag', 'tagi', 'tagów') }}
                    </button>
                </p>
            @endif

            <div class="form-actions">
                <button class="btn btn-primary" type="submit" name="akcja" value="zapisz">Zapisz</button>
                <a class="btn btn-quiet" href="{{ route('home') }}">Wróć na stronę główną</a>
            </div>
        </form>
    @endif

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="tags" />
    </x-slot:rail>
</x-layout>
