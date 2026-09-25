{{--
    Zgłoszenie nielegalnej treści — DSA art. 16 (audyt G-08, W5-01).

    DLACZEGO OSOBNY EKRAN, SKORO JEST JUŻ „ZGŁOŚ"
    Tamten obsługuje NASZE ZASADY: spam, chamstwo, niebezpieczna porada.
    Wymaga zalogowania i to jest w porządku — to nasza kolejka i nasze reguły.

    Ten obsługuje OBOWIĄZEK Z PRZEPISU i musi być dostępny dla każdego, także
    bez konta. Trafia tu prawnik w imieniu klienta, rodzic, który rozpoznał
    dziecko na cudzym zdjęciu, autor tekstu przepisanego bez zgody. Żadna
    z tych osób nie ma konta w serwisie kulinarnym.

    Formularz zbiera dokładnie to, czego wymaga art. 16 ust. 2, i ani jednego
    pola więcej — a każde tłumaczy po ludzku, bo wypełnia to człowiek
    zdenerwowany, nie prawnik z listą przepisów w ręku.
--}}
{{--
    BEZ `noindex` — świadomie, wbrew regule dla reszty ekranów formularzy.

    DSA art. 16 ust. 1 wymaga mechanizmu ŁATWO DOSTĘPNEGO. Człowiek, który
    znalazł tu swoje zdjęcie albo swój tekst, nie zna naszej stopki — wpisuje
    w wyszukiwarkę „kuking zgłoszenie nielegalnej treści". Strona wyjęta
    z indeksu jest wtedy stroną, której nie ma.

    Ekran potwierdzenia zostaje `noindex`: niesie numer sprawy.
--}}
{{--
    Meta description (znalezisko przy okazji issue #191, poza jego pierwotnym
    zakresem — ta strona jest ŚWIADOMIE indeksowana, patrz uzasadnienie
    wyżej, więc dokładnie tak samo jak tag i strony prawne cierpiała na
    brak `description` w `<x-layout>`, tylko nikt tego jeszcze nie zmierzył).
--}}
<x-layout title="Zgłoś treść niezgodną z prawem"
    description="Zgłoś zdjęcie, tekst albo przepis, który łamie prawo. Formularz jest dostępny dla każdego, także bez konta w Kuking.">
    <h1>Zgłoś treść niezgodną z prawem</h1>

    <p class="mb-5">
        Ten formularz jest dla każdego — nie musisz mieć konta w Kuking.
        Jeśli widzisz tu treść, która Twoim zdaniem łamie prawo, opisz nam ją.
        Sprawdzimy zgłoszenie i odpiszemy Ci z decyzją.
    </p>

    <div class="ramka-pomocnicza mb-5">
        <h2 class="mt-0">Chodzi o coś innego?</h2>
        <p>
            Jeśli treść nie łamie prawa, ale łamie zasady Kuking — jest spamem,
            kogoś obraża albo doradza coś niebezpiecznego — użyj przycisku
            <strong>Zgłoś</strong> przy przepisie albo komentarzu, a przy wpisie
            <strong>Zgłoś ten wpis</strong> w menu z trzema kropkami. Ta droga jest szybsza,
            ale wymaga zalogowania.
        </p>
        <p>
            A jeśli chodzi o coś zupełnie innego — coś w Kuking nie działa,
            masz pomysł albo chcesz nam po prostu coś powiedzieć —
            <a href="{{ route('kontakt') }}">napisz do nas</a>. Tamta droga nie
            kończy się decyzją moderatora; to zwykła rozmowa.
        </p>
        <p class="mb-0">
            Nie wiesz, którą wybrać? Wypełnij ten formularz. Przeczytamy każde
            zgłoszenie tak samo uważnie.
        </p>
    </div>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('zglos.nielegalna.store') }}">
        @csrf

        {{-- Tożsamość TEGO wysłania formularza (ADR
             docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md, pytanie P4).
             Zgłoszenia bez konta są poza indeksem częściowym w bazie
             (`reporter_id` jest tu NULL), a duplikat zakłada DRUGĄ sprawę
             z własnym terminem odpowiedzi z DSA art. 16.

             Zwykłe ukryte pole, bez JavaScriptu. Nazwa bez fragmentu
             „token", inaczej pole ginie na ekranie 419 (ADR §1.4.4). --}}
        @if(($kluczWyslania ?? null) !== null)
            <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">
        @endif

        <x-field name="target_url" label="Adres strony z tą treścią" required
                 :value="old('target_url')"
                 placeholder="https://kuking.pl/przepis/..."
                 help="Skopiuj adres z paska przeglądarki. Jeśli nie masz adresu, opisz poniżej, gdzie to jest." />

        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0 mt-5" id="f-reason"
                  @error('reason') tabindex="-1" aria-invalid="true" aria-describedby="f-reason-error" @enderror>
            <legend class="font-bold mb-3">Czego dotyczy zgłoszenie?</legend>
            <div class="stack-tight">
                @foreach($reasons as $value => $label)
                    <label class="choice">
                        <input type="radio" name="reason" value="{{ $value }}" @checked(old('reason') === $value)>
                        <span class="choice-label">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <x-blad-grupy name="reason" />
        </fieldset>

        <x-field name="illegality_explanation" label="Dlaczego uważasz, że ta treść łamie prawo?"
                 type="textarea" :rows="6" required :value="old('illegality_explanation')"
                 help="Napisz własnymi słowami. Nie musisz znać numerów przepisów — wystarczy, żebyśmy zrozumieli, na czym polega problem i czego dotyczy." />

        <h2>Jak się z Tobą skontaktować</h2>

        <x-field name="notifier_name" label="Imię i nazwisko albo nazwa instytucji"
                 :value="old('notifier_name')"
                 help="Możesz zostawić puste — zgłoszenie i tak sprawdzimy. Prawo wprost pozwala zgłosić najcięższe sprawy anonimowo, a my nie chcemy, żeby ktokolwiek milczał, bo boi się podpisać. Jeśli zgłaszasz w czyimś imieniu, podaj, w czyim." />

        <x-field name="notifier_email" label="Adres e-mail" type="email"
                 :value="old('notifier_email')"
                 help="Wyślemy tu potwierdzenie odbioru, a potem naszą decyzję. Możesz zostawić puste — wtedy nie damy znać, co ustaliliśmy." />

        {{--
            Oświadczenie o dobrej wierze — art. 16 ust. 2 lit. d. Tekst jest
            po ludzku, ale mówi dokładnie to, czego wymaga przepis: że wedle
            najlepszej wiedzy zgłaszającego informacje są prawdziwe i pełne.
        --}}
        <label class="choice mt-5" for="f-good_faith">
            <input id="f-good_faith" type="checkbox" name="good_faith" value="1"
                   @error('good_faith') aria-invalid="true" aria-describedby="f-good_faith-error" @enderror @checked(old('good_faith'))>
            <span class="choice-label">
                Oświadczam, że w dobrej wierze uważam podane informacje za prawdziwe i pełne
            </span>
        </label>
        <x-blad-grupy name="good_faith" />

        <x-turnstile miejsce="zgloszenie_nielegalnej_tresci" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij zgłoszenie</button>
        </div>
    </form>

    <div class="ramka-pomocnicza mt-5">
        <h2 class="mt-0">Co się stanie dalej</h2>
        <ol class="lista-krokow">
            <li>Dostaniesz e-mailem potwierdzenie z numerem sprawy — jeśli podasz adres.</li>
            <li>Człowiek z naszego zespołu przeczyta zgłoszenie i sprawdzi treść.</li>
            <li>Napiszemy Ci, co postanowiliśmy — także wtedy, gdy uznamy, że treść zostaje.
                W takim liście będzie powód i informacja, co możesz zrobić dalej.</li>
        </ol>
    </div>
</x-layout>
