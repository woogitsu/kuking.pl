<x-layout title="Kogo obserwować" :noindex="true">
    <p class="wizard-steps">
        <span class="wizard-steps-current">Krok 2 z 3</span>
        <span class="wizard-steps-track" aria-hidden="true">
            <span class="wizard-steps-dot" data-done="true"></span>
            <span class="wizard-steps-dot" data-done="true"></span>
            <span class="wizard-steps-dot"></span>
        </span>
    </p>

    <h1>Kogo chcesz obserwować?</h1>
    <p class="mb-5">
        To są ludzie, którzy tu gotują. Zaznacz, kogo chcesz widzieć na swojej stronie głównej.
        Zawsze możesz to zmienić.
    </p>

    {{--
        „ZNASZ JUŻ KOGOŚ TUTAJ?" (docs/research/MIGRACJA_Z_GARNKA.md §3.1).

        Osobny, ZWYKŁY formularz GET — nie POST i nie jeden wspólny formularz
        z listą niżej, bo wyszukiwanie nie obserwuje nikogo samo z siebie,
        tylko odświeża tę stronę z dopasowaniami. Adres z `?q=` da się zapisać,
        wysłać i otworzyć ponownie bez JavaScriptu (AGENTS.md §5) — skrypt
        mógłby to później tylko przyspieszyć, nie jest warunkiem działania.

        Ta sama wyszukiwarka, co na `/szukaj` (`SearchQuery::people()`,
        wołane w `OnboardingController::people()`) — nie osobny mechanizm.
    --}}
    {{-- RAMKA POMOCNICZA, nie panel formularza — mimo że to jedyne pole
         na ekranie. Panel dałby temu krokowi najmocniejszą warstwę ekranu,
         czyli wizualnie zrobiłby z niego obowiązek; główną rzeczą jest lista
         osób do zaznaczenia i przycisk dalej. Pole w ramce nie ginie:
         `.ramka-pomocnicza .field-input` odwraca mu tło na podniesione
         (tokens.css).

         Akapit pod nagłówkiem mówił wcześniej „to pomoc w odnalezieniu kogoś,
         kogo już znasz, a nie kolejny obowiązkowy krok". Zdjęte w grupie C4:
         opcjonalność niesie przycisk „Pomiń ten krok" niżej, a nie zdanie
         o tym, czym ten krok nie jest (`docs/brand/GLOS_MARKI.md` §5). --}}
    <div class="ramka-pomocnicza mb-6">
        <h2>Znasz już kogoś w <x-kuking-word />?</h2>
        <p class="mb-4">
            Czasem ważniejsza od ośmiu nieznajomych jest jedna znajoma osoba.
            Wpisz imię albo nazwę użytkownika, żeby ją tu znaleźć.
        </p>
        <form method="GET" action="{{ route('onboarding.people') }}">
            <div class="field">
                <label for="f-q">Imię lub nazwa użytkownika</label>
                <input class="field-input" id="f-q" name="q" type="search"
                       value="{{ $phrase }}" placeholder="np. Basia" autocomplete="off">
            </div>
            <button class="btn btn-secondary mt-3" type="submit">Szukaj</button>
        </form>
    </div>

    <form method="POST" action="{{ route('onboarding.people') }}">
        @csrf

        @if($phrase !== '')
            {{--
                WYNIKI SZUKANIA — wyłącznie dopasowania do wpisanej frazy,
                NIGDY pełna lista kont. To jest wyszukiwanie jednej znanej
                osoby, nie katalog ludzi do przeglądania
                (docs/research/MIGRACJA_Z_GARNKA.md §3.1 i zadanie tego PR-a).
            --}}
            @if($zaKrotka)
                {{-- Ten sam, uczciwy powód co na `/szukaj`: poniżej dwóch
                     znaków `SearchQuery` w ogóle nie pyta bazy. --}}
                <p class="meta">
                    Fraza „{{ $phrase }}” jest za krótka, żeby zacząć szukać. Wpisz co najmniej dwa znaki.
                </p>
            @elseif($wynikiWyszukiwania->isEmpty())
                {{-- Tytuł „Nic nie znaleźliśmy" jak w docs/brand/COPY_STYLE.md
                     §6, treść jak w gałęzi „ludzie" na `/szukaj` — plus
                     konkretna wskazówka, co zrobić (docs/UX_50_PLUS.md: błąd
                     i pusty stan mają mówić, co zrobić, nie tylko że jest
                     pusto). --}}
                <x-empty-state title="Nic nie znaleźliśmy">
                    Nie ma tu osoby o nazwie „{{ $phrase }}”. Sprawdź pisownię
                    imienia albo nazwy użytkownika i spróbuj jeszcze raz.
                </x-empty-state>
            @else
                <h2 class="mt-0">Wyniki wyszukiwania</h2>
                <div class="stack-tight mb-4">
                    @foreach($wynikiWyszukiwania as $profil)
                        <label class="choice">
                            <input type="checkbox" name="follow[]" value="{{ $profil->username }}">
                            {{-- #793 rozszerzone na relacje: ten ekran ludzie
                                 przerywają i wracają do niego, więc nazwa
                                 zaznaczona teraz może przy wysłaniu należeć
                                 już do kogoś innego. Kontroler porównuje ten
                                 identyfikator z osobą, którą nazwa wskazuje
                                 w chwili wysłania. --}}
                            <input type="hidden" name="oczekiwani[{{ $profil->username }}]" value="{{ $profil->user_id }}">
                            <span class="flex gap-3 items-center flex-1">
                                <x-avatar :user="$profil->user" :size="48" />
                                <span>
                                    <span class="choice-label">{{ $profil->display_name }}</span>
                                    <span class="choice-help">
                                        &#64;{{ $profil->username }}
                                        @if($profil->speciality) · {{ $profil->speciality }} @endif
                                    </span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                @if($jestWiecejWynikow)
                    {{-- Nie ma tu „Pokaż więcej" — to zamieniłoby pomoc
                         w odnalezieniu jednej osoby w przeglądaną listę. --}}
                    <p class="meta mb-4">
                        Osób o takiej nazwie jest więcej. Wpisz dokładniejsze imię
                        albo pełną nazwę użytkownika, żeby zawęzić wynik.
                    </p>
                @endif
            @endif

            <p class="mb-6">
                <a class="btn btn-quiet" href="{{ route('onboarding.people') }}">Wyczyść wyszukiwanie</a>
            </p>

            <h2>Osoby, które polecamy</h2>
        @endif

        @if($people->isEmpty())
            <x-empty-state title="Nie mamy jeszcze kogo Ci pokazać">
                <x-kuking-word /> dopiero się zaczyna. Za to Ty możesz być jedną z pierwszych osób,
                które tu coś pokażą.
            </x-empty-state>
        @else
            <div class="stack-tight">
                @foreach($people as $person)
                    <label class="choice">
                        <input type="checkbox" name="follow[]" value="{{ $person->profile->username }}">
                        {{-- Jak wyżej (#793 rozszerzone na relacje). --}}
                        <input type="hidden" name="oczekiwani[{{ $person->profile->username }}]" value="{{ $person->getKey() }}">
                        <span class="flex gap-3 items-center flex-1">
                            <x-avatar :user="$person" :size="48" />
                            <span>
                                <span class="choice-label">{{ $person->displayName() }}</span>
                                {{-- Świadomie BEZ liczby wpisów: osiem osób
                                     obok siebie z licznikami zamienia wybór
                                     w porównywanie. Lista jest już posortowana
                                     po tym, kto ostatnio coś pokazał. --}}
                                <span class="choice-help">
                                    {{ $person->profile->speciality ?? 'Gotuje w Kuking' }}
                                    @if($person->profile->region) · {{ $person->profile->region }} @endif
                                </span>
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
        @endif

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Dalej</button>
            <a class="btn btn-quiet" href="{{ route('onboarding.done') }}">Pomiń ten krok</a>
        </div>
    </form>
</x-layout>
