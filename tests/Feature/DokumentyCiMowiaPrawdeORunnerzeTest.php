<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Dokumenty CI nie twierdzą o wyborze runnera czegoś innego, niż stoi w `runs-on:`.
 *
 * SKĄD TO SIĘ WZIĘŁO — PLIK PRZECZYŁ SAM SOBIE W DWUDZIESTU LINIACH
 * `.github/workflows/ci.yml` miał w nagłówku zdanie:
 *
 *     „GDZIE TO CHODZI: na własnej puli (…), wskazanej ZESTAWEM ETYKIET,
 *      nie nazwą runnera i NIE ZMIENNĄ REPOZYTORIUM"
 *
 * a wszystkie dziewięć jobów tego samego pliku miało:
 *
 *     runs-on: ${{ fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"') }}
 *
 * czyli dokładnie zmienną repozytorium. Ten sam nagłówek zapisywał jako „koszt
 * decyzji", że joby „NIE mają już zapasu w runnerach GitHuba" — a `|| '"ubuntu-latest"'`
 * jest właśnie tym zapasem, i to zapasem DOMYŚLNYM. `docs/infra/SELF_HOSTED_RUNNER.md`
 * powtarzał obie nieprawdy w kroku „Włączenie CI w repozytorium", 160 linii nad
 * własnym pomiarem, który mówił coś przeciwnego (D-121, przebieg CI nr 661).
 *
 * DLACZEGO TO JEST GROŹNE, A NIE TYLKO NIECHLUJNE
 * Ta nieprawda ma kierunek: każe następnej osobie SZUKAĆ ETYKIET W KODZIE i nie
 * szukać zmiennej w ustawieniach repozytorium. Kto czyta „nie zmienną
 * repozytorium", ten nie sprawdzi wartości `CI_RUNS_ON` — a to właśnie ta wartość
 * (ustawiona na samo `self-hosted`) wysyłała przebiegi na starą pulę WSL-ową,
 * czyli tam, gdzie komplet sześciu etykiet miał ich NIE wpuścić. Instrukcja
 * „odkomentuj blok `on:`, usuń `workflow_dispatch`" dokładała do tego polecenie
 * zrobienia rzeczy już zrobionej i usunięcia rzeczy zostawionej celowo.
 *
 * To ta sama klasa co D-157 (dokument marki podawał jako regułę wariant odrzucony
 * po pomiarze) i D-104 (tabela stacku obiecywała Sentry'ego, którego nie ma):
 * ZAPIS WYGLĄDAŁ NA ODPOWIEDŹ, WIĘC NIKT NIE SZUKAŁ DALEJ.
 *
 * KOD JEST STRONĄ PRAWDZIWĄ (D-157, punkt 1)
 * Mechanizm wyboru runnera rozstrzyga D-121 i `runs-on:` w jobach, nie akapit
 * w dokumencie. Ten test NIE ocenia, czy zmienna repozytorium jest dobrym
 * pomysłem — stoi przy pytaniu, czy dokument i kod mówią JEDNO.
 *
 * DLACZEGO PILNUJE OBU STRON
 * Sprawdzenie samego dokumentu złapałoby połowę. Druga połowa jest równie realna:
 * gdyby ktoś wpisał do jobów zestaw etykiet na sztywno, dokumenty dalej mówiłyby
 * o zmiennej `CI_RUNS_ON` — i znowu kierowałyby czytelnika w złe miejsce, tylko
 * w drugą stronę. Dlatego mechanizm jest ODCZYTYWANY z jobów, a zakazy zależą od
 * tego, co odczyt pokazał.
 *
 * DLACZEGO ZAKAZ NIE IDZIE NA SAM NAPIS „etykiety" (D-157, punkt 3)
 * Poprawiony nagłówek MUSI dalej tłumaczyć, dlaczego pula jest wskazywana
 * KOMPLETEM SZEŚCIU ETYKIET, a nie nazwą runnera — to uzasadnienie jest
 * najcenniejszą treścią tego komentarza i nie wolno go usunąć w imię ciszy
 * w teście. Pilnowana jest więc OBIETNICA, czyli zacytowana w dokumencie linia
 * w kształcie `runs-on: <wartość>`, oraz wąska lista zdań, które WPROST
 * zaprzeczają mechanizmowi. Wzmianka nie jest obietnicą.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE PILNUJE — TO GRANICA, NIE PRZEOCZENIE
 *  - **Wartości zmiennej `CI_RUNS_ON`.** Żyje w ustawieniach repozytorium
 *    (Settings → Secrets and variables → Actions → Variables) i z repozytorium
 *    jej nie widać. Ostatni pomiar jest zapisany w `ci.yml` i w dokumencie;
 *    zmiana wartości należy do właściciela, nie do kodu (D-121).
 *  - **Czy wartość `CI_RUNS_ON` jest dobrze wybrana.** Że stoi dziś na samym
 *    `self-hosted`, jest decyzją właściciela z 12.09.2026 (koszt i sygnał do
 *    odwrócenia: `docs/infra/SELF_HOSTED_RUNNER.md`, „Krok 1"). Ten test pilnuje
 *    zgodności zdań z kodem, nie tego, czy decyzja jest słuszna.
 *  - **Czy `workflow_dispatch` jest potrzebny.** Rozstrzyga komentarz przy nim
 *    w `ci.yml`. Tu sprawdzamy tylko, czy dokument nie każe go usuwać, skoro stoi.
 *
 * DŁUG Z D-165 SPŁACONY 12.09.2026 — I DLACZEGO NIE BYŁA TO „JEDNA LINIA"
 * D-165 zostawiło jawnie nazwany dług: `deploy.yml`, `preview.yml`
 * i `railway-iac.yml` niosły w nagłówkach dokładnie tę samą nieprawdę co
 * poprawiony `ci.yml`, przy identycznym `runs-on:`. Dopisanie ich do `DOKUMENTY`
 * rzeczywiście jest jedną linią — ale sama ta linia NICZEGO by nie złapała.
 *
 * Zmierzone przed zmianą: skan po surowej treści tych plików **nie znajdował**
 * zdania „nie wybiera już żadna zmienna repozytorium", bo w YAML-u jest ono
 * złamane między liniami i w środku stoi znacznik `#`
 * („Runnera nie\n#  wybiera już żadna zmienna repozytorium") — normalizacja
 * skleja linie, ale `#` zostaje w środku zdania. Dopiero zdjęcie znacznika
 * komentarza, czyli ta sama droga, którą od początku szedł `ci.yml`, daje
 * trafienie. Dlatego komentarze wycina się teraz z KAŻDEGO pliku workflow
 * (`WORKFLOWY`), a nie z jednego.
 *
 * Przy okazji wyszło drugie: nagłówki tych trzech plików kazały „odkomentować
 * blok `on:`" i twierdziły, że workflow jest wyłączony z automatu — a bloki
 * `on:` są w nich AKTYWNE (`deployment_status`, `pull_request`). To ta sama
 * martwa instrukcja, którą D-165 złapało w „Kroku 2" `SELF_HOSTED_RUNNER.md`,
 * tyle że w trzech kopiach (D-104). Pilnuje jej
 * `test_dokumenty_nie_kaza_wlaczac_workflowow_ktore_chodza_same`.
 */
class DokumentyCiMowiaPrawdeORunnerzeTest extends TestCase
{
    /** Plik wykonywalny, z którego odczytujemy PRAWDĘ o mechanizmie. */
    private const WORKFLOW = '.github/workflows/ci.yml';

    /**
     * Dokumenty, które opisują ten mechanizm i mają się z nim zgadzać.
     *
     * `ci.yml` jest na tej liście jako DOKUMENT (czytane są wyłącznie jego linie
     * komentarza) i jednocześnie jako KOD (czytane są wyłącznie linie `runs-on:`
     * spoza komentarzy). To nie pomyłka: cała usterka #342 polegała na tym, że
     * jeden plik przeczył sam sobie.
     */
    private const DOKUMENTY = [
        ...self::WORKFLOWY,
        'docs/infra/SELF_HOSTED_RUNNER.md',
    ];

    /**
     * Pliki wykonywalne, których KOMENTARZE są dokumentem (D-165).
     *
     * Z każdego z nich czytamy wyłącznie linie `#`, i to po zdjęciu znacznika:
     * bez tego zdanie złamane w YAML-u („Runnera nie\n#  wybiera już…") ucieka
     * przed każdym wzorcem — zmierzone na tych trzech plikach 12.09.2026.
     * Kodem, czyli stroną prawdziwą, jest tu wyłącznie `ci.yml` (`WORKFLOW`).
     */
    private const WORKFLOWY = [
        self::WORKFLOW,
        '.github/workflows/deploy.yml',
        '.github/workflows/preview.yml',
        '.github/workflows/railway-iac.yml',
    ];

    /**
     * Job, który dziś czyta osobną zmienną `CI_RUNS_ON_BROWSER` — decyzja
     * właściciela z 21.09.2026, ZAWĘŻONA tego samego dnia.
     *
     * Tego dnia własne runnery (`CI_RUNS_ON`) zaczęły dzielić maszynę z flotą
     * agentów. Przy pięciu równoległych pełnych zestawach testów `load average`
     * dobijał do 12, a joby przeglądarkowe odpadały z `TimeoutError` — `main`
     * poczerwieniał z tego powodu raz.
     *
     * PIERWSZA WERSJA tej decyzji przełączyła PIĘĆ jobów naraz (`assets`,
     * `port_panelu`, `port_marki`, `port_funkcje`, `dostepnosc`) — właściciel
     * to cofnął po zobaczeniu kosztu w minutach: pięć jobów sumują się do
     * 66 min na pełny przebieg (Panel marki 18 + Port marki 8 + Port marki —
     * rodziny ekranów 27 + Dostępność 13), czyli tylko SIEDEM przebiegów
     * z puli ~500 minut miesięcznie. Ostateczna decyzja: WYŁĄCZNIE
     * `port_funkcje` wraca na `ubuntu-latest` przez `CI_RUNS_ON_BROWSER`
     * (ten sam wzorzec `fromJSON(vars.… || '"ubuntu-latest"')`) — sam ten job
     * jest najdłuższy w całym CI (mediana 51 s, maksimum 1638 s na
     * 25 przebiegach) i to on padł na `main` z `TimeoutError` pod obciążeniem
     * floty. Pozostałe cztery joby (w tym `assets`, mimo że też instaluje
     * Chromium dla kroku „Proporcje małej skali") zostają na `CI_RUNS_ON` —
     * czy pod obciążeniem floty też zaczną padać, NIE WIADOMO; to jest
     * pytanie otwarte, nie rozstrzygnięcie tym zawężeniem.
     *
     * Lista jest ZAMKNIĘTA i sprawdzana przeciw plikowi: jeśli ten job
     * zniknie albo zmieni identyfikator, test niżej oblewa z nazwaną
     * przyczyną zamiast cicho przestać cokolwiek pilnować. Trzyma się formy
     * listy (nie pojedynczej stałej), żeby przywrócenie kolejnych jobów do
     * `CI_RUNS_ON_BROWSER` było dopisaniem elementu, a nie przepisaniem testu.
     *
     * @var list<string>
     */
    private const BROWSER_JOBY = [
        'port_funkcje',
    ];

    /**
     * PROGI — bez nich ten test jest zielony na pustym zbiorze.
     *
     * Test skanujący pliki przechodzi także wtedy, gdy nie znajdzie NICZEGO
     * (`docs/PULAPKI_TESTOW.md`, pułapka 2). Zła ścieżka, zmiana wcięcia albo
     * regexp, który przestał łapać `runs-on:`, ma tu OBLAĆ z komunikatem
     * mówiącym, że zepsuł się TEST, a nie `ci.yml`.
     *
     * Zmierzone 12.09.2026 (po dopisaniu trzech workflow-ów): 9 jobów
     * z `runs-on:` w `ci.yml`; linie komentarza — `ci.yml` 654, `deploy.yml` 293,
     * `preview.yml` 129, `railway-iac.yml` 123; po jednym zacytowanym `runs-on:`
     * w każdym z pięciu dokumentów. Progi stoją z zapasem, żeby nie ruszać ich
     * przy zwykłej pracy.
     */
    private const MIN_JOBOW = 7;

    /** Próg linii komentarza dla każdego pliku workflow z osobna. */
    private const MIN_LINII_KOMENTARZA = [
        self::WORKFLOW => 200,
        '.github/workflows/deploy.yml' => 150,
        '.github/workflows/preview.yml' => 60,
        '.github/workflows/railway-iac.yml' => 60,
    ];

    /** Zdania, które WPROST odmawiają zmiennej repozytorium roli wybierającej. */
    private const ZAPRZECZENIA_ZMIENNEJ = [
        'nie zmienną repozytorium',
        'nie wybiera już żadna zmienna repozytorium',
        'nie wybiera żadna zmienna repozytorium',
        'nie jest już wybierany zmienną repozytorium',
        'runnera nie wybiera zmienna repozytorium',
    ];

    /** Zdania, które twierdzą, że zapasu w runnerach GitHuba nie ma. */
    private const ZAPRZECZENIA_ZAPASU = [
        'nie mają już zapasu w runnerach githuba',
        'nie mają zapasu w runnerach githuba',
        'nie ma już zapasu w runnerach githuba',
        'nie ma zapasu w runnerach githuba',
    ];

    /** Zdania oddające wybór runnera zmiennej — zakazane, gdy joby jej NIE czytają. */
    private const POTWIERDZENIA_ZMIENNEJ = [
        'wybiera zmienna repozytorium',
        'czyta zmienną repozytorium',
        'rozstrzyga zmienna repozytorium',
    ];

    /** Zdania obiecujące zapas — zakazane, gdy `runs-on:` żadnego nie ma. */
    private const POTWIERDZENIA_ZAPASU = [
        'zapas w runnerach githuba jest',
        'bez tej zmiennej joby idą na ubuntu-latest',
        'bez niej stoi ubuntu-latest',
    ];

    /**
     * Stare polecenia z „Kroku 2", nieprawdziwe od chwili, gdy blok `on:` ożył.
     *
     * Zakaz jest wąski i dosłowny, bo dotyczy JEDNEGO pliku: `ci.yml` — te zdania
     * wskazują go z nazwy. Instrukcji włączania pozostałych workflow-ów pilnuje
     * osobno `MARTWE_INSTRUKCJE_WLACZANIA`, bo tam warunek jest inny: zależy od
     * bloku `on:` każdego z tych plików z osobna.
     */
    private const MARTWE_INSTRUKCJE_WYZWALACZY = [
        'usuń workflow_dispatch',
        'usun workflow_dispatch',
        'zamień je: odkomentuj oryginalny blok',
        'na górze pliku jest zakomentowany blok on:',
        'tymczasowe on: workflow_dispatch',
    ];

    /**
     * Zdania każące WŁĄCZYĆ workflow, który już chodzi sam.
     *
     * Zakazane tylko wtedy, gdy każdy plik z `WORKFLOWY` ma w swoim bloku `on:`
     * wyzwalacz inny niż `workflow_dispatch` — czyli gdy naprawdę nie ma czego
     * odkomentowywać. Gdyby ktoś kiedyś świadomie zakomentował `on:` w którymś
     * z tych plików, zakaz sam się wyłącza, a zdanie robi się z powrotem
     * prawdziwe. Warunek czytamy z plików, nie zakładamy go.
     */
    private const MARTWE_INSTRUKCJE_WLACZANIA = [
        'odkomentuj blok on: poniżej',
        'żeby włączyć ten workflow: odkomentuj',
        'wyłączony z automatycznego uruchamiania',
        'blok on: jest nadal zakomentowany',
        'odkomentowania wymagają',
    ];

    // ---------------------------------------------------------------
    // 1. Kontrola samego wykrywacza + jednolitość mechanizmu
    // ---------------------------------------------------------------

    public function test_joby_ci_wybieraja_runnera_jednym_i_tym_samym_sposobem(): void
    {
        $poJobie = $this->deklaracjeRunsOnPoJobie();

        $this->assertGreaterThanOrEqual(
            self::MIN_JOBOW,
            count($poJobie),
            'W '.self::WORKFLOW.' widać tylko '.count($poJobie).' jobów z `runs-on:`, '
            .'a spodziewamy się co najmniej '.self::MIN_JOBOW.'. Liczba jobów w CI nie '
            .'spada — to usterka TEGO TESTU, nie workflow-a: sprawdź ścieżkę do pliku '
            .'i wzorzec czytający `runs-on:` spoza komentarzy. Test, który nie znalazł '
            .'ani jednego joba, przechodzi na pustym zbiorze i nie pilnuje niczego.',
        );

        $brakujace = array_values(array_diff(self::BROWSER_JOBY, array_keys($poJobie)));

        $this->assertSame(
            [],
            $brakujace,
            'Jobów z BROWSER_JOBY brak w '.self::WORKFLOW.': '.implode(', ', $brakujace).'. '
            .'Job zniknął albo zmienił identyfikator — popraw listę BROWSER_JOBY albo '
            .'workflow, zanim ten test oceni cokolwiek innego.',
        );

        $przegladarkowe = array_intersect_key($poJobie, array_flip(self::BROWSER_JOBY));
        $pozostale = array_diff_key($poJobie, array_flip(self::BROWSER_JOBY));

        $this->assertCount(
            1,
            array_unique($przegladarkowe),
            'Joby przeglądarkowe ('.implode(', ', self::BROWSER_JOBY).') wybierają runnera '
            .'NA RÓŻNE SPOSOBY: '.implode(' | ', array_unique($przegladarkowe)).'. Od 21.09.2026 '
            .'mają czytać wspólnie `CI_RUNS_ON_BROWSER` — ujednolić albo opisać rozjazd świadomie '
            .'i przepisać ten test.',
        );

        $this->assertCount(
            1,
            array_unique($pozostale),
            'Pozostałe joby (poza BROWSER_JOBY) wybierają runnera NA RÓŻNE SPOSOBY: '
            .implode(' | ', array_unique($pozostale)).'. Mają czytać wspólnie `CI_RUNS_ON` — '
            .'ujednolić albo opisać rozjazd świadomie i przepisać ten test.',
        );

        // Z JEDNYM jobem w BROWSER_JOBY oba testy `assertCount(1, …)` wyżej są
        // prawdziwe NAWET WTEDY, gdy ktoś przez pomyłkę przywróci `port_funkcje`
        // na `CI_RUNS_ON` (zbiór jednoelementowy ma zawsze jedną unikalną
        // wartość — sam z sobą się nie różni). Tego dokładnie dotyczyła usterka
        // #342: cichy powrót do jednej wspólnej wartości. Dlatego to jest
        // JEDYNE miejsce w tym pliku, które wprost porównuje obie grupy ze sobą.
        $wartoscPrzegladarkowa = (string) reset($przegladarkowe);
        $wartoscPozostalych = (string) reset($pozostale);

        $this->assertNotSame(
            $wartoscPozostalych,
            $wartoscPrzegladarkowa,
            'Job(y) z BROWSER_JOBY ('.implode(', ', self::BROWSER_JOBY).') mają TĘ SAMĄ '
            .'wartość `runs-on:` co pozostałe joby: `'.$wartoscPrzegladarkowa.'`. To jest '
            .'dokładnie przypadkowy powrót do jednej wspólnej wartości, przed którym ten test '
            .'ma ostrzegać — z jednym jobem w grupie przeglądarkowej `assertCount(1, …)` wyżej '
            .'przechodzi także wtedy, gdy ta grupa po cichu scaliła się z resztą. Job '
            .'przeglądarkowy ma czytać `CI_RUNS_ON_BROWSER`, nie `CI_RUNS_ON`.',
        );

        $this->assertStringContainsString(
            'CI_RUNS_ON_BROWSER',
            $wartoscPrzegladarkowa,
            'Job(y) z BROWSER_JOBY mają `runs-on: '.$wartoscPrzegladarkowa.'`, co nie '
            .'wspomina zmiennej `CI_RUNS_ON_BROWSER` po nazwie. Decyzja właściciela '
            .'z 21.09.2026 mówi wprost, jaka zmienna ma tu stać — sama różnica od '
            .'wartości pozostałych jobów (sprawdzona wyżej) by tego nie wykryła, gdyby '
            .'ktoś podstawił inną, trzecią wartość.',
        );
    }

    // ---------------------------------------------------------------
    // 2. Obietnica z dokumentu = to, co naprawdę stoi w jobach
    // ---------------------------------------------------------------

    public function test_dokumenty_cytuja_runs_on_dokladnie_tak_jak_stoi_w_jobach(): void
    {
        $glowny = $this->runsOnGlownyWKodzie();
        $przegladarkowy = $this->runsOnPrzegladarkowyWKodzie();

        // `ci.yml` opisuje w komentarzach OBIE grupy naraz (jest dokumentem obu
        // mechanizmów), pozostałe dokumenty znają tylko główny mechanizm — żaden
        // z pozostałych trzech workflow-ów ani SELF_HOSTED_RUNNER.md nie ma dziś
        // joba przeglądarkowego.
        foreach (self::DOKUMENTY as $dokument) {
            $cytaty = $this->cytatyRunsOn($this->trescDokumentu($dokument));
            $dozwolone = $dokument === self::WORKFLOW ? [$glowny, $przegladarkowy] : [$glowny];

            $this->assertNotEmpty(
                $cytaty,
                $dokument.' nie cytuje już ani jednej linii `runs-on: …`. Bez tego cytatu '
                .'ten test nie ma czego porównywać z kodem i przechodzi na pustym zbiorze. '
                .'Jeśli cytat zniknął z dokumentu celowo, przepisz ten test — nie zostawiaj '
                .'strażnika, który świeci na zielono, bo nic nie znalazł.',
            );

            foreach ($cytaty as $cytat) {
                $this->assertContains(
                    $cytat,
                    $dozwolone,
                    $dokument.' cytuje `runs-on: '.$cytat.'`, a w jobach '.self::WORKFLOW
                    ." stoi:\n  główny (poza BROWSER_JOBY): `runs-on: ".$glowny."`\n"
                    .'  przeglądarkowy (BROWSER_JOBY): `runs-on: '.$przegladarkowy."`\n"
                    .'Dokument i kod mówią dwie różne rzeczy o tym, GDZIE chodzi CI. '
                    ."Popraw tę stronę, która jest nieprawdziwa — a stroną prawdziwą jest\n"
                    .'KOD (D-157 punkt 1); mechanizm wyboru runnera rozstrzyga D-121 (główny) '
                    .'i decyzja właściciela z 21.09.2026 (przeglądarkowy), i nie zmienia się '
                    ."przy okazji porządkowania dokumentacji.\n"
                    .'Tak właśnie wyglądało issue #342: nagłówek `ci.yml` cytował zestaw '
                    .'etykiet, a joby czytały zmienną repozytorium `CI_RUNS_ON`.',
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // 3. Zdania wprost zaprzeczające mechanizmowi i zapasowi
    // ---------------------------------------------------------------

    public function test_dokumenty_nie_zaprzeczaja_mechanizmowi_wyboru_runnera(): void
    {
        $czytaZmienna = $this->kodCzytaZmiennaRepozytorium();

        $zakazane = $czytaZmienna ? self::ZAPRZECZENIA_ZMIENNEJ : self::POTWIERDZENIA_ZMIENNEJ;

        $powod = $czytaZmienna
            ? 'Joby w '.self::WORKFLOW.' czytają zmienną repozytorium `CI_RUNS_ON` '
                .'(`'.$this->runsOnGlownyWKodzie().'`), więc zdanie odmawiające jej tej '
                .'roli jest nieprawdą — i to nieprawdą kierunkową: każe szukać etykiet '
                .'w kodzie zamiast wartości zmiennej w ustawieniach repozytorium.'
            : 'Joby w '.self::WORKFLOW.' NIE czytają już żadnej zmiennej repozytorium '
                .'(`'.$this->runsOnGlownyWKodzie().'`), więc zdanie oddające jej wybór '
                .'runnera jest nieprawdą. Dokument ma przestać mówić o zmiennej.';

        $this->assertSame([], $this->trafienia($zakazane), $powod);
    }

    public function test_dokumenty_nie_zaprzeczaja_istnieniu_zapasu_w_runnerach_githuba(): void
    {
        $zapas = $this->zapasWRunnerachGithuba();

        $zakazane = $zapas !== null ? self::ZAPRZECZENIA_ZAPASU : self::POTWIERDZENIA_ZAPASU;

        $powod = $zapas !== null
            ? 'Joby w '.self::WORKFLOW.' MAJĄ zapas w runnerach GitHuba i jest on wartością '
                .'domyślną: `'.$this->runsOnGlownyWKodzie().'` schodzi bez zmiennej na '
                .'`'.$zapas.'`. Zdanie „te joby nie mają już zapasu" opisuje inny wariant '
                .'`runs-on:` niż ten, który tu stoi — a różnica jest praktyczna: przy '
                .'zapasie wyjściem awaryjnym z zakolejkowanego CI jest SKASOWANIE zmiennej, '
                .'nie czekanie na maszyny.'
            : 'W `runs-on:` nie ma już zapasu w runnerach GitHuba (`'
                .$this->runsOnGlownyWKodzie().'`), a dokument dalej go obiecuje. '
                .'Zdanie o `ubuntu-latest` jako wartości domyślnej trzeba usunąć razem '
                .'z zapasem, a nie po kolejnym zakolejkowanym wdrożeniu.';

        $this->assertSame([], $this->trafienia($zakazane), $powod);
    }

    // ---------------------------------------------------------------
    // 4. Instrukcja włączenia CI opisuje stan faktyczny wyzwalaczy
    // ---------------------------------------------------------------

    public function test_dokumenty_nie_kaza_wlaczac_wyzwalaczy_ci_ktore_juz_dzialaja(): void
    {
        $blok = $this->blokWyzwalaczy();

        $this->assertNotSame(
            '',
            $blok,
            'W '.self::WORKFLOW.' nie widać aktywnego bloku `on:` w pierwszej kolumnie. '
            .'Albo wyzwalacze naprawdę zostały zakomentowane (wtedy CI nie chodzi wcale, '
            .'a Railway czeka na check suite, który nie powstaje — D-010), albo zepsuł się '
            .'ten test. Sprawdź jedno i drugie, zanim cokolwiek dopiszesz.',
        );

        foreach (['push', 'pull_request', 'workflow_dispatch'] as $wyzwalacz) {
            $this->assertStringContainsString(
                $wyzwalacz.':',
                $blok,
                'Blok `on:` w '.self::WORKFLOW.' nie ma już wyzwalacza `'.$wyzwalacz.'`. '
                .'Jeśli to zmiana celowa, popraw „Krok 2" w docs/infra/SELF_HOSTED_RUNNER.md '
                .'razem z nią — ten test pilnuje, żeby instrukcja i plik mówiły to samo.',
            );
        }

        $this->assertSame(
            [],
            $this->trafienia(self::MARTWE_INSTRUKCJE_WYZWALACZY),
            'Dokument każe włączyć w `ci.yml` wyzwalacze, które SĄ JUŻ WŁĄCZONE, albo usunąć '
            ."`workflow_dispatch`, który stoi tam CELOWO.\n"
            .'Zastany blok `on:` w '.self::WORKFLOW.":\n\n".$this->blokWyzwalaczy()."\n"
            .'`workflow_dispatch` pozwala puścić przebieg ręcznie bez pustego commita; powód '
            ."stoi przy nim w komentarzu.\n"
            .'Instrukcja, która każe zrobić rzecz zrobioną, uczy pomijania instrukcji — a przy '
            .'okazji kazałaby zabrać jedyny ręczny wyzwalacz bramki deployu. Pozostałe trzy '
            .'workflow-y też chodzą same (zmierzone 12.09.2026), więc i o nich nie wolno '
            .'napisać, że czekają na odkomentowanie — pilnuje tego '
            .'test_dokumenty_nie_kaza_wlaczac_workflowow_ktore_chodza_same.',
        );
    }

    public function test_dokumenty_nie_kaza_wlaczac_workflowow_ktore_chodza_same(): void
    {
        $samoczynne = [];

        foreach (self::WORKFLOWY as $plik) {
            $wyzwalacze = $this->wyzwalaczeSamoczynne($plik);

            if ($wyzwalacze !== []) {
                $samoczynne[$plik] = implode(', ', $wyzwalacze);
            }
        }

        $opis = (string) json_encode($samoczynne, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertCount(
            count(self::WORKFLOWY),
            $samoczynne,
            'Któryś z workflow-ów nie ma dziś ani jednego wyzwalacza poza '
            ."`workflow_dispatch`. Zastane:\n".$opis."\n"
            .'Albo blok `on:` naprawdę został gdzieś zakomentowany — wtedy zdanie „odkomentuj" '
            .'jest znowu prawdziwe i zakaz niżej ma prawo się wyłączyć, ale sprawdź, czy '
            ."Railway nie czeka przez to na check suite, który nie powstaje (D-010) —\n"
            .'albo zepsuł się odczyt bloku `on:` w tym teście. Bez tego odczytu zakaz niżej '
            .'skanuje zdania, nie wiedząc, czy są prawdziwe.',
        );

        $this->assertSame(
            [],
            $this->trafienia(self::MARTWE_INSTRUKCJE_WLACZANIA),
            'Dokument każe WŁĄCZYĆ workflow, który już chodzi sam, albo mówi, że jest '
            ."wyłączony z automatu. Zastane wyzwalacze poza `workflow_dispatch`:\n".$opis."\n"
            .'Zakomentowanego bloku `on:` nie ma dziś w żadnym z tych plików, więc nie ma tam '
            ."czego odkomentowywać.\n"
            .'To ta sama usterka co „Krok 2" z D-165, tylko w kopiach: instrukcja każąca zrobić '
            .'rzecz, której nie ma do zrobienia, uczy pomijania instrukcji, a przy okazji mówi, '
            .'że deploy i preview nie chodzą, choć chodzą. Kopia jest miejscem, w którym '
            .'nieprawda odrasta (D-104).',
        );
    }

    // ---------------------------------------------------------------
    // Odczyt kodu
    // ---------------------------------------------------------------

    /**
     * Wartości `runs-on:` ze WSZYSTKICH jobów, z pominięciem linii komentarza,
     * indeksowane identyfikatorem joba (`zakres`, `port_marki`, …).
     *
     * Komentarze są tu wycięte świadomie: nagłówek `ci.yml` cytuje `runs-on:`
     * w treści, a to jest obietnica dokumentu, nie kod. Pomieszanie jednego
     * z drugim dałoby test, który porównuje nagłówek sam ze sobą.
     *
     * Identyfikator joba łapie klucz na DWÓCH spacjach wcięcia z gołym
     * dwukropkiem na końcu linii (`  port_marki:`) — dokładnie tak wyglądają
     * klucze jobów pod `jobs:` w tym pliku. Kilka takich linii istnieje też
     * w bloku `on:` (`  push:`, `  workflow_dispatch:`) — nie szkodzi: żadna
     * linia `runs-on:` nie pojawia się, zanim skaner trafi na pierwszy
     * prawdziwy klucz joba pod `jobs:`, więc fałszywe „bieżące joby" z bloku
     * `on:` nigdy nie zbierają żadnej wartości.
     *
     * @return array<string, string>
     */
    private function deklaracjeRunsOnPoJobie(): array
    {
        $poJobie = [];
        $aktualnyJob = null;

        foreach ($this->linie(self::WORKFLOW) as $linia) {
            if ($this->jestKomentarzem($linia)) {
                continue;
            }

            if (preg_match('/^  ([a-z][a-z0-9_]*):\s*$/', $linia, $trafienieJobu) === 1) {
                $aktualnyJob = $trafienieJobu[1];

                continue;
            }

            if ($aktualnyJob !== null && preg_match('/^\s+runs-on:\s*(\S.*?)\s*$/', $linia, $trafienie) === 1) {
                $poJobie[$aktualnyJob] = $this->bezNadmiarowychSpacji($trafienie[1]);
            }
        }

        return $poJobie;
    }

    /** Jedna, uzgodniona wartość `runs-on:` z podanego wycinka jobów. */
    private function jedynaWartoscRunsOn(array $poJobie, string $opisGrupy): string
    {
        $deklaracje = array_unique($poJobie);

        $this->assertCount(
            1,
            $deklaracje,
            'Joby '.$opisGrupy.' w '.self::WORKFLOW.' nie mają jednej wspólnej wartości '
            .'`runs-on:` (znalezione: '.count($deklaracje).'). Powód i co z tym zrobić opisuje '
            .'test_joby_ci_wybieraja_runnera_jednym_i_tym_samym_sposobem.',
        );

        return (string) reset($deklaracje);
    }

    /** Wspólna wartość `runs-on:` jobów POZA `BROWSER_JOBY` (czyta `CI_RUNS_ON`). */
    private function runsOnGlownyWKodzie(): string
    {
        $poJobie = array_diff_key($this->deklaracjeRunsOnPoJobie(), array_flip(self::BROWSER_JOBY));

        return $this->jedynaWartoscRunsOn($poJobie, 'pozostałe (poza BROWSER_JOBY)');
    }

    /** Wspólna wartość `runs-on:` jobów z `BROWSER_JOBY` (czyta `CI_RUNS_ON_BROWSER`). */
    private function runsOnPrzegladarkowyWKodzie(): string
    {
        $poJobie = array_intersect_key($this->deklaracjeRunsOnPoJobie(), array_flip(self::BROWSER_JOBY));

        return $this->jedynaWartoscRunsOn($poJobie, 'przeglądarkowe (BROWSER_JOBY)');
    }

    /** Czy `runs-on:` sięga po zmienną repozytorium (`vars.…`). */
    private function kodCzytaZmiennaRepozytorium(): bool
    {
        return preg_match('/\bvars\.[A-Z0-9_]+/', $this->runsOnGlownyWKodzie()) === 1;
    }

    /**
     * Etykieta zapasowa z `|| '"…"'`, albo `null`, gdy zapasu nie ma.
     *
     * To jest dokładnie ta konstrukcja, której istnieniu przeczył nagłówek:
     * `fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"')`. Sprawdzana na głównym
     * mechanizmie — przeglądarkowy (`CI_RUNS_ON_BROWSER`) używa dziś tego
     * samego wzorca zapasu i żaden osobny dokument nie twierdzi o nim czegoś
     * innego, więc osobnego strażnika nie ma (D-157 punkt 3: zakaz na
     * rzeczywistą obietnicę, nie na każdą możliwą przyszłą).
     */
    private function zapasWRunnerachGithuba(): ?string
    {
        $wzorzec = '/\|\|\s*\'"([^"]+)"\'/';

        return preg_match($wzorzec, $this->runsOnGlownyWKodzie(), $trafienie) === 1
            ? $trafienie[1]
            : null;
    }

    /**
     * Aktywny (niezakomentowany) blok `on:` z `ci.yml`, razem z jego komentarzami.
     *
     * Kotwicą jest `on:` w PIERWSZEJ kolumnie — w YAML-u workflow-a tylko klucz
     * najwyższego poziomu tam stoi, więc `workflow_dispatch:` z wnętrza `jobs:`
     * (gdyby kiedyś powstał) nie zostanie wzięty za wyzwalacz.
     */
    private function blokWyzwalaczy(string $plik = self::WORKFLOW): string
    {
        $wBloku = false;
        $blok = [];

        foreach ($this->linie($plik) as $linia) {
            if (! $wBloku) {
                if (preg_match('/^on:\s*$/', $linia) === 1) {
                    $wBloku = true;
                }

                continue;
            }

            // Koniec bloku: pierwsza linia od pierwszej kolumny, która nie jest jego częścią.
            if ($linia !== '' && preg_match('/^\s/', $linia) !== 1) {
                break;
            }

            $blok[] = rtrim($linia);
        }

        return trim(implode("\n", $blok));
    }

    /**
     * Wyzwalacze danego workflow-a INNE niż `workflow_dispatch`.
     *
     * Czyli te, przez które plik uruchamia się sam. Klucze wyzwalaczy stoją
     * w bloku `on:` na dwóch spacjach (pierwsza linia bloku przychodzi tu bez
     * wcięcia, bo blok jest przycięty), a ich ustawienia — `types:`, `branches:`,
     * `paths:`, `inputs:` — głębiej, więc się nie łapią.
     *
     * @return list<string>
     */
    private function wyzwalaczeSamoczynne(string $plik): array
    {
        $wyzwalacze = [];

        foreach (explode("\n", $this->blokWyzwalaczy($plik)) as $linia) {
            if (preg_match('/^ {0,2}([a-z_]+):/', $linia, $trafienie) !== 1) {
                continue;
            }

            if ($trafienie[1] !== 'workflow_dispatch') {
                $wyzwalacze[] = $trafienie[1];
            }
        }

        return $wyzwalacze;
    }

    // ---------------------------------------------------------------
    // Odczyt dokumentów
    // ---------------------------------------------------------------

    /**
     * Zacytowane w dokumencie linie `runs-on: …`, znormalizowane.
     *
     * Obietnicą jest linia, która PODAJE WARTOŚĆ — w bloku kodu albo
     * w komentarzu. Wzmianka w zdaniu (``pilnuje `runs-on:` ``, ``co czyta
     * `runs-on` ``) obietnicą nie jest i nie jest tu łapana: zakaz na sam napis
     * kazałby usunąć z dokumentacji zdania, które ją tłumaczą (D-157, punkt 3).
     *
     * @return list<string>
     */
    private function cytatyRunsOn(string $tresc): array
    {
        $cytaty = [];

        foreach (explode("\n", $tresc) as $linia) {
            $linia = $this->bezZnacznikaKomentarza($linia);

            if (preg_match('/^runs-on:\s*(\S.*?)\s*$/', trim($linia), $trafienie) === 1) {
                $cytaty[] = $this->bezNadmiarowychSpacji($trafienie[1]);
            }
        }

        return $cytaty;
    }

    /**
     * Treść dokumentu do skanu zdaniami.
     *
     * Z pliku workflow bierzemy WYŁĄCZNIE linie komentarza, i to bez znacznika
     * `#` — reszta pliku to kod, a kod jest tu stroną prawdziwą, nie badanym
     * twierdzeniem. Zdjęcie znacznika nie jest kosmetyką: zdanie złamane
     * w YAML-u ma `#` w środku i bez tego kroku nie trafia w żaden wzorzec
     * (zmierzone na `deploy.yml` 12.09.2026 — patrz docblock klasy).
     */
    private function trescDokumentu(string $plik): string
    {
        if (! in_array($plik, self::WORKFLOWY, true)) {
            return implode("\n", $this->linie($plik));
        }

        $komentarze = array_values(array_filter(
            $this->linie($plik),
            fn (string $linia): bool => $this->jestKomentarzem($linia),
        ));

        $this->assertGreaterThanOrEqual(
            self::MIN_LINII_KOMENTARZA[$plik],
            count($komentarze),
            'W '.$plik.' widać tylko '.count($komentarze).' linii komentarza, '
            .'a spodziewamy się co najmniej '.self::MIN_LINII_KOMENTARZA[$plik].'. Ten plik jest '
            .'gęsto skomentowany i się z tego nie rozbiera — to usterka TEGO TESTU: '
            .'sprawdź wykrywanie linii `#`. Skan pustego zbioru zdań nie znajdzie żadnego '
            .'zakazanego zdania i przejdzie.',
        );

        return implode("\n", array_map($this->bezZnacznikaKomentarza(...), $komentarze));
    }

    /**
     * Które z zakazanych zdań naprawdę stoją w dokumentach — z nazwą pliku.
     *
     * @param  list<string>  $zakazane
     * @return list<string>
     */
    private function trafienia(array $zakazane): array
    {
        $znalezione = [];

        foreach (self::DOKUMENTY as $dokument) {
            $tekst = $this->doPorownania($this->trescDokumentu($dokument));

            foreach ($zakazane as $zdanie) {
                if (str_contains($tekst, $this->doPorownania($zdanie))) {
                    $znalezione[] = $dokument.': „'.$zdanie.'"';
                }
            }
        }

        return $znalezione;
    }

    /**
     * Tekst sprowadzony do postaci, w której porównanie zdań ma sens.
     *
     * Bez tego zdanie złamane na dwie linie („nie zmienną\n#  repozytorium")
     * ucieka przed każdym wzorcem — a właśnie tak było złamane to zdanie
     * w nagłówku `ci.yml`. Znikają też `**pogrubienia**`, odwrotne apostrofy
     * i różnice wielkości liter.
     */
    private function doPorownania(string $tekst): string
    {
        $tekst = str_replace(['*', '`', '„', '"', '"'], '', mb_strtolower($tekst));

        return $this->bezNadmiarowychSpacji($tekst);
    }

    // ---------------------------------------------------------------
    // Drobiazgi
    // ---------------------------------------------------------------

    /** @return list<string> */
    private function linie(string $plik): array
    {
        $sciezka = base_path($plik);

        $this->assertFileExists(
            $sciezka,
            'Nie ma '.$plik.'. Jeśli plik zmienił nazwę, popraw ten test — bez niego '
            .'rozjazd dokumentów z `runs-on:` przestaje być pilnowany.',
        );

        return explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($sciezka)));
    }

    private function jestKomentarzem(string $linia): bool
    {
        return preg_match('/^\s*#/', $linia) === 1;
    }

    private function bezZnacznikaKomentarza(string $linia): string
    {
        return (string) preg_replace('/^\s*#\s?/', '', $linia);
    }

    private function bezNadmiarowychSpacji(string $tekst): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $tekst));
    }
}
