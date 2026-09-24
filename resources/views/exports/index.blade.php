{{--
    To jest plik, który człowiek faktycznie otworzy. `dane.json` jest dla
    programów; ten spis treści jest dla osoby, która chce zobaczyć swoje
    przepisy i zdjęcia — dzisiaj albo za dziesięć lat, bez internetu.
--}}
@php
    /**
     * Polska odmiana po liczbie: „1 wpis”, „2 wpisy”, „5 wpisów”.
     * Bez tego spis treści wita użytkowniczkę zdaniem „2 wpisów”,
     * co od razu zdradza, że tekst pisała maszyna.
     */
    $odmiana = function (int $n, string $jeden, string $kilka, string $wiele): string {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if ($n === 1) {
            return $jeden;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $kilka;
        }

        return $wiele;
    };
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Moje dane z Kuking — spis treści</title>
    @include('exports.styles')
</head>
<body>
<div class="strona">

    <div class="naglowek">
        <h1>Twoje dane z Kuking</h1>
        <p class="podpis">
            Paczka przygotowana {{ \App\Support\Czas::data($generatedAt, 'j F Y, H:i') }}
            @if($displayName) dla {{ $displayName }}@endif.
        </p>
    </div>

    {{--
        Zdanie otwierające mówi, co w paczce JEST — nie „wszystko”.
        Zmierzone na prawdziwym archiwum: cudzy przepis zapisany w zeszycie
        wychodzi w `dane.json` jako tytuł, autor, moja notatka i data zapisania
        (`CollectUserExportData::collections()`), bez składników, kroków
        i zdjęć. „Kopia wszystkiego” obiecywała więc pełne cudze przepisy,
        których tu nie ma — a to jest plik, który człowiek czyta przed
        skasowaniem konta. Zamiast wyliczać, czego brakuje, nazywamy jedno
        ograniczenie, które naprawdę może kogoś zaskoczyć.

        Słowo „notatki” wypadło świadomie: w Kuking nie ma encji „notatka”
        (są notatki przy wykonaniu, przy składniku i przy zapisie w zeszycie),
        więc na tej liście udawało osobny rodzaj treści. „Komentarze” są
        w paczce naprawdę i jako osobna sekcja (`moje_komentarze`).

        DWIE POPRAWKI PO NIEZALEŻNYM REVIEW.

        1. Zdanie o zeszycie stoi pod warunkiem, bo na koncie BEZ ANI JEDNEGO
           cudzego przepisu w zeszycie opisywało ograniczenie czegoś, czego
           w paczce nie ma. Świeże konto dostawało zdanie o „cudzych
           przepisach” przy pustym zeszycie — ta sama klasa usterki co
           katalogi, których w paczce nie ma.
        2. Wyliczenie pól było niepełne. `collections()` daje CZTERY pola
           (`tytul`, `autor`, `moja_notatka`, `zapisano`), a zdanie mówiło
           o dwóch — czyli zaniżało to, co człowiek w paczce naprawdę
           dostaje. Zakres danych się nie zmienia; zmienia się opis.
           Zgodność listy pól z rzeczywistością pilnuje asercja na klucze
           w `EksportWygladObietnicePaczkiTest`.
    --}}
    <div class="karta">
        <p style="margin-bottom:0;">
            To jest kopia Twoich przepisów, wpisów, zdjęć i komentarzy.
            @if($savedOtherRecipeCount > 0)
                Cudze przepisy zapisane w Twoim zeszycie są tu jako tytuł, autor,
                Twoja notatka i data zapisania — bez składników, kroków i zdjęć.
            @endif
            @if(($savedHiddenCount ?? 0) > 0)
                {{-- #1017: sama liczba, bez tytułów i autorów — tak jak ekran zeszytu. --}}
                Pozycje z Twoich zeszytów, których autorzy już Ci nie pokazują: {{ $savedHiddenCount }}.
                Nie ma ich w tej paczce. Zostały w zeszycie i wrócą, jeśli autor znów je udostępni.
            @endif
            Możesz to trzymać na swoim komputerze i czytać
            <strong>bez internetu</strong> — także wtedy, gdyby Kuking kiedyś
            przestał istnieć. Nic tutaj nie wymaga zakładania konta.
        </p>
    </div>

    <h2>Twoje przepisy ({{ count($recipes) }})</h2>
    @if(count($recipes) === 0)
        <p>W tej paczce nie ma jeszcze żadnego przepisu.</p>
    @else
        <ul class="spis">
            @foreach($recipes as $recipe)
                <li>
                    <a href="przepisy/{{ $recipe['plik'] }}">{{ $recipe['tytul'] }}</a>
                    @if($recipe['szkic'])
                        <span class="plakietka">szkic</span>
                    @endif
                    @if($recipe['data'])
                        <br><span class="podpis">{{ $recipe['data'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <h2>Twoje wpisy i wykonania</h2>
    <ul class="spis">
        <li>
            <a href="wpisy.html">Twoje wpisy i notatki z gotowania</a>
            <br><span class="podpis">
                {{ $postCount }} {{ $odmiana($postCount, 'wpis', 'wpisy', 'wpisów') }},
                {{ $cookedCount }} {{ $odmiana($cookedCount, 'zapisane wykonanie', 'zapisane wykonania', 'zapisanych wykonań') }}
            </span>
        </li>
    </ul>

    <h2>Zdjęcia ({{ $photoCount }})</h2>
    {{--
        Katalog `zdjecia/` powstaje w paczce TYLKO wtedy, gdy jest do niego
        co włożyć — ZipArchive nie tworzy pustych katalogów. Bez tego warunku
        człowiek bez ani jednego zdjęcia dostawał zaproszenie „Otwórz katalog
        ze zdjęciami" prowadzące do miejsca, którego w paczce nie ma:
        przeglądarka pokazywała wtedy „nie znaleziono pliku".

        Usterka wychodzi WYŁĄCZNIE u kogoś, kto nie dodał jeszcze zdjęcia —
        czyli u osoby najświeższej w serwisie, i to w pliku, który ma być
        dowodem, że jej dane są bezpieczne. Żaden test tego nie łapał, bo
        wszystkie budowały eksport z gotowymi zdjęciami.
    --}}
    @if($photoCount > 0)
        <p>
            {{-- „Zdjęcia z tej paczki", nie „wszystkie Twoje zdjęcia”: do paczki
                 wchodzą wyłącznie zdjęcia ze statusem `ready` (`ExportPhotoPlan`),
                 a konto potrafi mieć obok nich odrzucone albo skasowane. Liczba
                 w nagłówku wyżej opisuje paczkę i była prawdziwa — nieprawdziwe
                 było samo słowo „wszystkie”. --}}
            Zdjęcia z tej paczki leżą w katalogu <strong>zdjecia</strong>, obok tego pliku.
            Nazwa każdego pliku zaczyna się od daty, więc łatwo je posortować —
            na przykład <em>2027-03-14-rosol.webp</em>.
        </p>
        {{-- `akcja` — akapit, którego całą treścią jest jeden odnośnik.
             Klasa niesie cel dotknięcia 48 px ze wspólnego `styles.blade.php`;
             bez niej ten odnośnik miał zmierzone 22 px wysokości. --}}
        <p class="akcja"><a href="zdjecia/">Otwórz katalog ze zdjęciami</a></p>
    @elseif($photosStillProcessing === 0)
        {{--
            ZDANIE OPISUJE PACZKĘ, NIE KONTO.

            Ta gałąź nie znaczy „nie masz zdjęć". Znaczy: żadne zdjęcie nie
            weszło do paczki i żadne nie jest w drodze. `ExportPhotoPlan`
            liczy tylko `ready` oraz `pending`/`processing`, więc konto
            z SAMYMI zdjęciami odrzuconymi (albo skasowanymi) trafia tutaj —
            i słyszało „nie masz jeszcze w Kuking żadnego zdjęcia" o zdjęciach,
            które samo wgrało. Ta sama usterka była w `CZYTAJ-TO-NAJPIERW.txt`
            i naprawiamy ją w obu plikach naraz, żeby paczka nie mówiła
            dwóch rzeczy o jednej sytuacji.

            Zakres danych eksportu się nie zmienia: paczka dalej nie wypisuje
            zdjęć odrzuconych ani powodu odrzucenia.

            Przy zdjęciach w drodze to zdanie się NIE pojawia (issue #113) —
            wtedy wchodzi ostrzeżenie niżej.
        --}}
        <p>
            W tej paczce nie ma żadnego zdjęcia, więc nie ma w niej katalogu
            ze zdjęciami. Katalog pojawi się, gdy będziesz mieć w Kuking zdjęcie,
            które widać w serwisie, i poprosisz o paczkę ponownie.
        </p>
    @endif

    {{--
        Paczka, która WYGLĄDA na kompletną, a nie jest, jest gorsza od paczki,
        która wprost mówi o swoich brakach. RODO art. 15/20 — cytowane
        w `dane.json` — obiecuje dostęp do WSZYSTKICH danych, nie do tych,
        które akurat zdążyły się przetworzyć. Eksport i przetwarzanie zdjęć
        idą tą samą kolejką, więc na dużym koncie eksport realnie potrafi
        wystartować pierwszy (issue #113).
    --}}
    @if($photosStillProcessing > 0)
        @php
            // Liczebnik i czasownik odmieniają się tak samo (1 / 2-4 / 5+
            // i nastki) — obie formy z tej samej funkcji, inaczej wyszłoby
            // „3 zdjęć nie zmieściło się".
            $zdjecia = \App\Support\Odmiana::rzeczownik($photosStillProcessing, 'zdjęcie', 'zdjęcia', 'zdjęć');
            $zmiescilo = \App\Support\Odmiana::rzeczownik($photosStillProcessing, 'zmieściło', 'zmieściły', 'zmieściło');
            $przygotowywalo = \App\Support\Odmiana::rzeczownik($photosStillProcessing, 'przygotowywało', 'przygotowywały', 'przygotowywało');
        @endphp
        <div class="uwaga">
            <p>
                <strong>UWAGA: {{ $photosStillProcessing }} {{ $zdjecia }} nie {{ $zmiescilo }} się w tej paczce.</strong>
                W chwili jej budowania {{ $przygotowywalo }} się jeszcze do pokazania w serwisie.
                Poproś o nową paczkę, gdy przygotowywanie zdjęć się zakończy. Przed usunięciem konta sprawdź, czy zawiera wszystkie Twoje zdjęcia.
            </p>
        </div>
    @endif

    {{--
        ZDJĘCIA, KTÓRE DO PACZKI NIE WEJDĄ NIGDY (issue #692).

        To jest ta „inna wiadomość", którą `ExportPhotoPlan` zapowiadał
        od #113 w komentarzu przy `stillProcessing`, a której nikt nie
        napisał. Blok wyżej mówi „jeszcze się przetwarzają, poproś o nową
        paczkę". Tutaj taka rada byłaby nieprawdą: `rejected` i `deleted`
        są stanami końcowymi i kolejna paczka też ich nie przyniesie.

        DWA BLOKI, NIE JEDEN, I RÓŻNY TON.
        Odrzucone to STRATA, której człowiek nie wybierał — przygotowanie
        pliku padło (`ProcessUploadedImage`), a on o tym mógł nigdy nie
        usłyszeć. Stąd `uwaga` i rada, co da się zrobić: wgrać oryginał
        jeszcze raz. Skasowane to jego WŁASNA decyzja — krzyczenie na
        kogoś „UWAGA!" za to, że sam coś skasował, byłoby hałasem, a nie
        informacją. Stąd zwykły akapit: potwierdzenie, nie alarm.
        To jest odpowiedź na pytanie z issue, czy te dwa stany zasługują
        na jedno zdanie, czy na dwa — na dwa, bo różnią się i przyczyną,
        i tym, co człowiekowi zostaje do zrobienia.

        OBIE GAŁĘZIE STOJĄ POD WARUNKIEM `> 0` — i to jest ta sama strona
        granicy, co zdanie o cudzych przepisach w zeszycie. `index.html`
        czyta CZŁOWIEK i opisuje mu TĘ paczkę, więc zdanie o brakach,
        których w niej nie ma, opisywałoby nieobecne. Reguła — że takie
        zdjęcia nie wchodzą do żadnej paczki — stoi BEZWARUNKOWO po
        drugiej stronie, w `czego_nie_zawiera` w `dane.json`, razem
        z licznikami, które są tam zawsze, także przy zerze.

        Bloki są niezależne od ostrzeżenia o zdjęciach w drodze i od
        siebie nawzajem: konto może mieć wszystkie trzy rzeczy naraz.

        Zakres danych paczki nie zmienia się o nic — tu jest wyłącznie
        LICZBA. Żadnego zdjęcia, żadnego powodu odrzucenia, żadnego
        identyfikatora.
    --}}
    @if($photosRejected > 0)
        @php
            // Liczebnik, czasownik w przeszłości i w przyszłości odmieniają
            // się tak samo (1 / 2-4 / 5+ i nastki) — wszystkie z tej samej
            // funkcji, inaczej wyszłoby „3 zdjęć nie weszły".
            $zdjeciaOdrzucone = \App\Support\Odmiana::rzeczownik($photosRejected, 'zdjęcie', 'zdjęcia', 'zdjęć');
            $weszloOdrzucone = \App\Support\Odmiana::rzeczownik($photosRejected, 'weszło', 'weszły', 'weszło');
            $wejdzieOdrzucone = \App\Support\Odmiana::rzeczownik($photosRejected, 'wejdzie', 'wejdą', 'wejdzie');
            $ichOdrzucone = \App\Support\Odmiana::rzeczownik($photosRejected, 'go', 'ich', 'ich');
        @endphp
        <div class="uwaga">
            <p>
                <strong>UWAGA: {{ $photosRejected }} {{ $zdjeciaOdrzucone }} nie {{ $weszloOdrzucone }} do tej paczki i nie {{ $wejdzieOdrzucone }} do żadnej następnej.</strong>
                Nie udało się {{ $ichOdrzucone }} przygotować do pokazania w serwisie, a tego już się nie cofnie —
                nowa paczka nic tu nie zmieni.
                Oryginały, które masz u siebie na komputerze albo w telefonie, możesz wgrać do Kuking jeszcze raz.
            </p>
        </div>
    @endif

    @if($photosDeleted > 0)
        @php
            // „które skasowano" jest tu nieodmienne (1: „zdjęcie, które
            // skasowano", 5: „zdjęć, które skasowano"), więc form jest trzy,
            // nie pięć.
            $zdjeciaSkasowane = \App\Support\Odmiana::rzeczownik($photosDeleted, 'zdjęcie', 'zdjęcia', 'zdjęć');
            $weszloSkasowane = \App\Support\Odmiana::rzeczownik($photosDeleted, 'weszło', 'weszły', 'weszło');
            $wejdzieSkasowane = \App\Support\Odmiana::rzeczownik($photosDeleted, 'wejdzie', 'wejdą', 'wejdzie');
        @endphp
        <p>
            {{ $photosDeleted }} {{ $zdjeciaSkasowane }}, które skasowano z Kuking, nie {{ $weszloSkasowane }}
            do tej paczki i nie {{ $wejdzieSkasowane }} do żadnej następnej.
            Skasowane zdjęcie znika z serwisu razem ze swoimi plikami, więc nie ma już czego do paczki włożyć.
        </p>
    @endif

    <h2>Pliki techniczne</h2>
    <ul class="spis">
        <li>
            <a href="dane.json">dane.json</a>
            {{-- „Te same dane", nie „wszystkie": ten opis stoi dwa ekrany pod
                 zdaniem otwierającym, które przestało obiecywać komplet,
                 i opisuje plik, którego własne `co_zawiera` też przestało.
                 Paczka nie ma prawa przeczyć samej sobie o dwa akapity. --}}
            <br><span class="podpis">Te same dane w formacie, który zrozumie inny serwis albo program.
            To jest plik na przeniesienie danych, nie do czytania.</span>
        </li>
        <li>
            <a href="CZYTAJ-TO-NAJPIERW.txt">CZYTAJ-TO-NAJPIERW.txt</a>
            <br><span class="podpis">Krótkie wyjaśnienie, co jest w środku i jak to otworzyć.</span>
        </li>
    </ul>

    <p class="stopka">
        Paczkę przygotował Kuking.pl na Twoją prośbę
        (RODO art. 15 i 20 — prawo dostępu do danych i prawo do ich przeniesienia).
        Komentarze innych osób są tu z treścią, datą i podpisem — bez ich adresów e-mail.
    </p>
    {{-- #953: paczka nie realizuje art. 15 sama. Zdanie jest bezwarunkowe,
         bo wyłączenia są regułą eksportu (`InwentarzDanychKonta`), a nie
         cechą tego konta — pełna lista z powodami stoi w `dane.json`. --}}
    <p class="stopka">
        Kilku rodzajów danych w tej paczce nie ma, na przykład dziennika bezpieczeństwa konta
        i informacji o tym, kto zablokował Twoje konto. Plik dane.json wymienia je wszystkie
        z powodami (w polu „kategorie_poza_paczka”). Hasła i kodów do logowania nie wydajemy nikomu.
        Pozostałe dane wyślemy Ci na prośbę — napisz do nas: {{ config('kuking.community.contact_email') }}.
    </p>
</div>
</body>
</html>
