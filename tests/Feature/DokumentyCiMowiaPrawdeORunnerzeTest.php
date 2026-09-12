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
 *  - **`deploy.yml`, `preview.yml`, `railway-iac.yml`.** Mają `runs-on:`
 *    taki sam jak `ci.yml`, ale ich nagłówki niosą jeszcze starą nieprawdę
 *    („Runnera nie wybiera już żadna zmienna repozytorium"). Poprawka tych
 *    trzech plików jest poza zakresem issue #342 i celowo NIE jest tu wymuszana
 *    — dopisanie ich do `DOKUMENTY` to jedna linia, gdy tamta zmiana wejdzie.
 *  - **Czy `workflow_dispatch` jest potrzebny.** Rozstrzyga komentarz przy nim
 *    w `ci.yml`. Tu sprawdzamy tylko, czy dokument nie każe go usuwać, skoro stoi.
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
        self::WORKFLOW,
        'docs/infra/SELF_HOSTED_RUNNER.md',
    ];

    /**
     * PROGI — bez nich ten test jest zielony na pustym zbiorze.
     *
     * Test skanujący pliki przechodzi także wtedy, gdy nie znajdzie NICZEGO
     * (`docs/PULAPKI_TESTOW.md`, pułapka 2). Zła ścieżka, zmiana wcięcia albo
     * regexp, który przestał łapać `runs-on:`, ma tu OBLAĆ z komunikatem
     * mówiącym, że zepsuł się TEST, a nie `ci.yml`.
     *
     * Zmierzone 12.09.2026: 9 jobów z `runs-on:`, 643 linie komentarza
     * w `ci.yml`, po jednym zacytowanym `runs-on:` w każdym z dwóch dokumentów.
     * Progi stoją z zapasem, żeby nie ruszać ich przy zwykłej pracy.
     */
    private const MIN_JOBOW = 7;

    private const MIN_LINII_KOMENTARZA = 200;

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
     * Zakaz jest wąski i dosłowny, bo dotyczy JEDNEGO pliku: `ci.yml`. Dokument
     * ma pełne prawo kazać odkomentować `on:` w `deploy.yml`, `preview.yml`
     * i `railway-iac.yml` — tam blok naprawdę jest zakomentowany.
     */
    private const MARTWE_INSTRUKCJE_WYZWALACZY = [
        'usuń workflow_dispatch',
        'usun workflow_dispatch',
        'zamień je: odkomentuj oryginalny blok',
        'na górze pliku jest zakomentowany blok on:',
        'tymczasowe on: workflow_dispatch',
    ];

    // ---------------------------------------------------------------
    // 1. Kontrola samego wykrywacza + jednolitość mechanizmu
    // ---------------------------------------------------------------

    public function test_joby_ci_wybieraja_runnera_jednym_i_tym_samym_sposobem(): void
    {
        $deklaracje = $this->deklaracjeRunsOnZJobow();

        $this->assertGreaterThanOrEqual(
            self::MIN_JOBOW,
            count($deklaracje),
            'W '.self::WORKFLOW.' widać tylko '.count($deklaracje).' jobów z `runs-on:`, '
            .'a spodziewamy się co najmniej '.self::MIN_JOBOW.'. Liczba jobów w CI nie '
            .'spada — to usterka TEGO TESTU, nie workflow-a: sprawdź ścieżkę do pliku '
            .'i wzorzec czytający `runs-on:` spoza komentarzy. Test, który nie znalazł '
            .'ani jednego joba, przechodzi na pustym zbiorze i nie pilnuje niczego.',
        );

        $this->assertCount(
            1,
            array_unique($deklaracje),
            'Joby w '.self::WORKFLOW.' wybierają runnera NA DWA RÓŻNE SPOSOBY: '
            .implode(' | ', array_unique($deklaracje)).'. Żaden dokument nie opisze tego '
            .'jednym zdaniem, a przebiegi rozjadą się po dwóch pulach. Zastane wartości '
            .'wyżej — ujednolić albo opisać ten podział świadomie i przepisać ten test.',
        );
    }

    // ---------------------------------------------------------------
    // 2. Obietnica z dokumentu = to, co naprawdę stoi w jobach
    // ---------------------------------------------------------------

    public function test_dokumenty_cytuja_runs_on_dokladnie_tak_jak_stoi_w_jobach(): void
    {
        $wKodzie = $this->deklaracjaRunsOnWKodzie();

        foreach (self::DOKUMENTY as $dokument) {
            $cytaty = $this->cytatyRunsOn($this->trescDokumentu($dokument));

            $this->assertNotEmpty(
                $cytaty,
                $dokument.' nie cytuje już ani jednej linii `runs-on: …`. Bez tego cytatu '
                .'ten test nie ma czego porównywać z kodem i przechodzi na pustym zbiorze. '
                .'Jeśli cytat zniknął z dokumentu celowo, przepisz ten test — nie zostawiaj '
                .'strażnika, który świeci na zielono, bo nic nie znalazł.',
            );

            foreach ($cytaty as $cytat) {
                $this->assertSame(
                    $wKodzie,
                    $cytat,
                    $dokument.' cytuje `runs-on: '.$cytat.'`, a w jobach '.self::WORKFLOW
                    .' stoi `runs-on: '.$wKodzie."`.\n"
                    .'Dokument i kod mówią dwie różne rzeczy o tym, GDZIE chodzi CI. '
                    ."Popraw tę stronę, która jest nieprawdziwa — a stroną prawdziwą jest\n"
                    .'KOD (D-157 punkt 1); mechanizm wyboru runnera rozstrzyga D-121 i nie '
                    ."zmienia się przy okazji porządkowania dokumentacji.\n"
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
                .'(`'.$this->deklaracjaRunsOnWKodzie().'`), więc zdanie odmawiające jej tej '
                .'roli jest nieprawdą — i to nieprawdą kierunkową: każe szukać etykiet '
                .'w kodzie zamiast wartości zmiennej w ustawieniach repozytorium.'
            : 'Joby w '.self::WORKFLOW.' NIE czytają już żadnej zmiennej repozytorium '
                .'(`'.$this->deklaracjaRunsOnWKodzie().'`), więc zdanie oddające jej wybór '
                .'runnera jest nieprawdą. Dokument ma przestać mówić o zmiennej.';

        $this->assertSame([], $this->trafienia($zakazane), $powod);
    }

    public function test_dokumenty_nie_zaprzeczaja_istnieniu_zapasu_w_runnerach_githuba(): void
    {
        $zapas = $this->zapasWRunnerachGithuba();

        $zakazane = $zapas !== null ? self::ZAPRZECZENIA_ZAPASU : self::POTWIERDZENIA_ZAPASU;

        $powod = $zapas !== null
            ? 'Joby w '.self::WORKFLOW.' MAJĄ zapas w runnerach GitHuba i jest on wartością '
                .'domyślną: `'.$this->deklaracjaRunsOnWKodzie().'` schodzi bez zmiennej na '
                .'`'.$zapas.'`. Zdanie „te joby nie mają już zapasu" opisuje inny wariant '
                .'`runs-on:` niż ten, który tu stoi — a różnica jest praktyczna: przy '
                .'zapasie wyjściem awaryjnym z zakolejkowanego CI jest SKASOWANIE zmiennej, '
                .'nie czekanie na maszyny.'
            : 'W `runs-on:` nie ma już zapasu w runnerach GitHuba (`'
                .$this->deklaracjaRunsOnWKodzie().'`), a dokument dalej go obiecuje. '
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
            .'okazji kazałaby zabrać jedyny ręczny wyzwalacz bramki deployu. Odkomentowania '
            .'wymagają `deploy.yml`, `preview.yml` i `railway-iac.yml` i o nich pisać wolno.',
        );
    }

    // ---------------------------------------------------------------
    // Odczyt kodu
    // ---------------------------------------------------------------

    /**
     * Wartości `runs-on:` ze WSZYSTKICH jobów, z pominięciem linii komentarza.
     *
     * Komentarze są tu wycięte świadomie: nagłówek `ci.yml` cytuje `runs-on:`
     * w treści, a to jest obietnica dokumentu, nie kod. Pomieszanie jednego
     * z drugim dałoby test, który porównuje nagłówek sam ze sobą.
     *
     * @return list<string>
     */
    private function deklaracjeRunsOnZJobow(): array
    {
        $deklaracje = [];

        foreach ($this->linie(self::WORKFLOW) as $linia) {
            if ($this->jestKomentarzem($linia)) {
                continue;
            }

            if (preg_match('/^\s+runs-on:\s*(\S.*?)\s*$/', $linia, $trafienie) === 1) {
                $deklaracje[] = $this->bezNadmiarowychSpacji($trafienie[1]);
            }
        }

        return $deklaracje;
    }

    /** Jedna, uzgodniona wartość `runs-on:` z jobów. */
    private function deklaracjaRunsOnWKodzie(): string
    {
        $deklaracje = array_unique($this->deklaracjeRunsOnZJobow());

        $this->assertCount(
            1,
            $deklaracje,
            'Joby w '.self::WORKFLOW.' nie mają jednej wspólnej wartości `runs-on:` '
            .'(znalezione: '.count($deklaracje).'). Powód i co z tym zrobić opisuje '
            .'test_joby_ci_wybieraja_runnera_jednym_i_tym_samym_sposobem.',
        );

        return (string) reset($deklaracje);
    }

    /** Czy `runs-on:` sięga po zmienną repozytorium (`vars.…`). */
    private function kodCzytaZmiennaRepozytorium(): bool
    {
        return preg_match('/\bvars\.[A-Z0-9_]+/', $this->deklaracjaRunsOnWKodzie()) === 1;
    }

    /**
     * Etykieta zapasowa z `|| '"…"'`, albo `null`, gdy zapasu nie ma.
     *
     * To jest dokładnie ta konstrukcja, której istnieniu przeczył nagłówek:
     * `fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"')`.
     */
    private function zapasWRunnerachGithuba(): ?string
    {
        $wzorzec = '/\|\|\s*\'"([^"]+)"\'/';

        return preg_match($wzorzec, $this->deklaracjaRunsOnWKodzie(), $trafienie) === 1
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
    private function blokWyzwalaczy(): string
    {
        $wBloku = false;
        $blok = [];

        foreach ($this->linie(self::WORKFLOW) as $linia) {
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
     * Z `ci.yml` bierzemy WYŁĄCZNIE linie komentarza — reszta pliku to kod,
     * a kod jest tu stroną prawdziwą, nie badanym twierdzeniem.
     */
    private function trescDokumentu(string $plik): string
    {
        if ($plik !== self::WORKFLOW) {
            return implode("\n", $this->linie($plik));
        }

        $komentarze = array_values(array_filter(
            $this->linie($plik),
            fn (string $linia): bool => $this->jestKomentarzem($linia),
        ));

        $this->assertGreaterThanOrEqual(
            self::MIN_LINII_KOMENTARZA,
            count($komentarze),
            'W '.self::WORKFLOW.' widać tylko '.count($komentarze).' linii komentarza, '
            .'a spodziewamy się co najmniej '.self::MIN_LINII_KOMENTARZA.'. Ten plik jest '
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
