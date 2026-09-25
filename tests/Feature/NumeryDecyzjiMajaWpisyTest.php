<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\DziennikDecyzji;
use Tests\TestCase;

/**
 * Każdy numer decyzji cytowany w kodzie ma wpis w dzienniku decyzji.
 *
 * Od 25.09.2026 dziennik to jeden plik na decyzję w `docs/decyzje/`
 * (`docs/DECISIONS.md` jest indeksem). Test czyta wpisy złączone przez
 * `Tests\Support\DziennikDecyzji::tresc()` — w tej samej postaci, co dawny
 * jednoplikowy dziennik — więc reguły niżej nie zmieniły się ani o znak.
 *
 * SKĄD TO SIĘ WZIĘŁO — TEN BŁĄD WYSTĄPIŁ DWA RAZY, DRUGI RAZ PO NAPRAWIE
 * 10 września 2026 okazało się, że kod powołuje się w SIEDEMNASTU miejscach
 * na decyzję **D-067, której nigdy nie napisano** (przekazanie pracy, §13.8).
 * Odnośniki przepięto na D-085, ale testu pilnującego nie napisano — i tego
 * samego wieczora dziura wróciła pod innym numerem: siedem miejsc cytowało
 * **D-066**, którego w dzienniku nie ma i nigdy nie było. Historia gita mówi
 * dokładnie, jak: wpis D-066 (96 linii) powstał na gałęzi PR #254, właściciel
 * zamknął #254 jako zastąpiony przez #288, a #301 przeniósł z niego
 * KOMENTARZE W KODZIE bez wpisu w dzienniku. Zero czerwonych przebiegów.
 *
 * CZEGO TO KOSZTUJE, GDY NIKT NIE PILNUJE
 * `AGENTS.md` §2 każe czytać `docs/DECISIONS.md`, „zanim zaproponujesz zmianę
 * architektury, pakiet albo inny sposób pisania tekstów", bo „połowa dobrych
 * pomysłów jest tam już rozstrzygnięta wraz z uzasadnieniem". Komentarz
 * odsyłający do numeru, którego nie ma, jest gorszy niż komentarz bez numeru:
 * wygląda na uzasadnienie, więc następna osoba nie szuka dalej, a szukając
 * i tak nic nie znajdzie. Numer decyzji jest w tym repozytorium ODNOŚNIKIEM,
 * a martwy odnośnik jest usterką dokumentacji.
 *
 * DWA KIERUNKI, KTÓRYCH TEN PLIK PILNUJE
 *  1. **Sierota** — kod cytuje numer, którego dziennik nie zawiera.
 *  2. **Duplikat** — dziennik ma dwa wpisy pod jednym numerem. Dziś takich
 *     nie ma. Jest to jednak awaria CICHSZA i groźniejsza od sieroty: test
 *     z punktu 1 przechodzi (numer JEST), a kod odsyłający do numeru trafia
 *     w dwie różne decyzje naraz. Przy pracy kilku osób równolegle nad jednym
 *     plikiem, dopisywanym zawsze na końcu, to jest realny scenariusz
 *     konfliktu scalania rozwiązanego „obie strony".
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE PILNUJE
 *  - **Kierunku „wpis bez cytowania w kodzie".** Połowa dziennika to decyzje,
 *    które w kodzie nie zostawiają śladu, bo są decyzjami o NIEROBIENIU
 *    czegoś — D-059 („newslettera redakcyjnego nie budujemy"), D-065 („trzy
 *    pakiety zostają na nie"), D-064 (HEIC odrzucany, libheif nie wchodzi).
 *    Wymaganie cytowania byłoby wymaganiem, żeby kod wspominał o kodzie,
 *    którego nie ma.
 *  - **Katalogu `docs/`.** Dokumentacja wymienia numery, których dziennik nie
 *    zawiera, i robi to legalnie — numery zarezerwowane dla równolegle
 *    pracujących osób, numery świadomie niewykorzystane i opisy historyczne
 *    w przekazaniach pracy, które MÓWIĄ o tym, że wpisu brakuje. Rozszerzenie
 *    skanu na `docs/` zamieniłoby ten test w tyle samo fałszywych alarmów
 *    pierwszego dnia, a to jest najkrótsza droga do wyłączenia go na stałe.
 *
 *    **TA LISTA SIĘ STARZEJE — I RAZ JUŻ SIĘ ZESTARZAŁA.** Stało tu, że bez
 *    wpisu jest JEDENAŚCIE numerów: D-062, D-067, D-069, D-070, D-073, D-074,
 *    D-084, D-086, D-089, D-092, D-093. Pięć z nich wpisy w międzyczasie
 *    DOSTAŁO (D-062, D-069, D-089, D-092, D-093) — bo to są numery
 *    zarezerwowane, a rezerwacja z definicji kiedyś się realizuje. Lista
 *    nazywająca wpis nieistniejącym, gdy on już istnieje, jest dokładnie tym
 *    samym martwym odnośnikiem, przed którym stoi cały ten plik, tylko
 *    obróconym w drugą stronę.
 *
 *    Zmierzone 11 września 2026 — bez wpisu w `docs/DECISIONS.md` jest dziś
 *    SIEDEM numerów wymienianych w `docs/`:
 *
 *      * **D-084, D-086, D-094** — puste ŚWIADOMIE i na stałe. Mówi to wprost
 *        nagłówek `docs/DECISIONS.md` („Osobno i wcześniej puste są D-084,
 *        D-086 i D-094"). To nie są luki do uzupełnienia.
 *      * **D-067, D-070, D-073, D-074** — numery zarezerwowane, o których
 *        piszą przekazania pracy i raporty z audytu. Te mogą zniknąć z listy
 *        w dowolnym dniu, w którym ktoś dopisze wpis.
 *
 *    Osobno i z innego powodu stoi **D-108 … D-112**: to NIE są luki dziennika
 *    głównego, tylko odstęp od CUDZEJ numeracji — system projektowy
 *    w `docs/design/system-v3.1/` ma własny dziennik i własne D-101 … D-112.
 *    Dziennik główny przeszedł z D-107 od razu na D-113 i te pięć numerów
 *    zostaje u niego pustych na zawsze. W kodzie ten sam mechanizm obsługuje
 *    stała `OBCA_NUMERACJA` niżej, para plik+numer.
 *
 *    Sprawdzenie zajmuje jedną linijkę i nie wymaga uruchamiania testów:
 *
 *    ```bash
 *    for n in 067 070 073 074 084 086 094; do \
 *      printf 'D-%s: %s\n' "$n" "$(ls docs/decyzje | grep -c "^D-$n-")"; done
 *    ```
 *  - **Formatu odnośnika.** „D-051", „(D-051)", „patrz D-051" i „D-051 §3"
 *    są tu równie dobre; ujednolicanie zapisu nie jest tym, co się zepsuło.
 */
class NumeryDecyzjiMajaWpisyTest extends TestCase
{
    /**
     * Katalogi z KODEM — czyli te, w których odnośnik do numeru decyzji jest
     * zawsze cytatem z `docs/DECISIONS.md`, nigdy rozmową o numeracji.
     *
     * `docs/` jest poza skanem świadomie (powód w docbloku klasy). `public/`
     * i `lang/` są w skanie, choć dziś nie mają ani jednego odnośnika: kosztu
     * to nie ma żadnego, a nowy odnośnik dopisany tam byłby poza kontrolą.
     *
     * @var list<string>
     */
    private const KATALOGI = [
        'app',
        'bootstrap',
        'config',
        'database',
        'lang',
        'public',
        'resources',
        'routes',
        'scripts',
        'tests',
    ];

    /**
     * WZORZEC CELOWO SZERSZY NIŻ DZISIEJSZA NUMERACJA.
     *
     * Naturalne byłoby `D-0\d\d`, bo dziennik stoi na D-091 i wszystkie
     * odnośniki mają dziś wiodące zero. Taki wzorzec przestałby jednak łapać
     * cokolwiek w dniu, w którym powstanie D-100 — i zrobiłby to po cichu,
     * czyli dokładnie tym sposobem, przed którym stoi cały ten plik
     * (`docs/PULAPKI_TESTOW.md`, pułapka 2). Trzy cyfry bez wymogu zera
     * na początku obsługują D-001…D-999 i nie wymagają pamiętania o niczym.
     */
    private const WZORZEC_ODNOSNIKA = '/\bD-(\d{3})\b/';

    /** Nagłówek wpisu w dzienniku: „## D-051 · …". */
    private const WZORZEC_NAGLOWKA = '/^## D-(\d{3})\b/m';

    /**
     * JEDYNY PLIK POZA SKANEM — TEN.
     *
     * Wyszło to na pierwszym uruchomieniu i jest właśnie tym, co ten test ma
     * łapać: docblock wyżej OPOWIADA historię D-066 i D-067, stoi w nim lista
     * numerów legalnie nieobsadzonych (D-062…D-094 oraz D-108…D-112 z cudzej
     * numeracji), D-100 w akapicie o wzorcu i D-999 w opisie kontroli ujemnej.
     * Skan zgłosił piętnaście sierot, wszystkie własne — i miał rację co do
     * liter, a nie co do rzeczy.
     *
     * Granica jest ta sama, którą docblock klasy stawia wobec `docs/`:
     * odnośnik cytujący decyzję JAKO UZASADNIENIE podlega kontroli, tekst
     * MÓWIĄCY O NUMERACJI nie, bo mówi też o numerach, których celowo nie ma.
     * Ten plik jest w całości tekstem drugiego rodzaju.
     *
     * Cena, świadoma: gdyby ktoś kiedyś napisał tutaj prawdziwy odnośnik do
     * decyzji, wymknąłby się kontroli. Jest to jeden znany plik na 964, a nie
     * cały katalog — i nie ma w nim czego uzasadniać numerem decyzji, bo cała
     * reguła, której pilnuje, stoi w `AGENTS.md` §2, nie w dzienniku.
     */
    private const POZA_SKANEM = 'tests/Feature/NumeryDecyzjiMajaWpisyTest.php';

    /**
     * WYJĄTKI — para „plik => numery z INNEJ numeracji", nie plik i nie numer.
     *
     * `D-107` w `resources/css/ekran-dodawania.css` nie jest odnośnikiem do
     * dziennika decyzji. Komentarz mówi wprost, czym jest: „ten sam wzorzec,
     * który SYSTEM PROJEKTOWY v3.1 opisuje jako D-107", i podaje ścieżkę do
     * komponentu w `docs/design/system-v3.1/`. To druga, niezależna
     * numeracja, która przypadkiem ma ten sam kształt.
     *
     * Wyjątek jest wąski celowo — para plik+numer, nie „cały ten plik" i nie
     * „numery od 100 w górę". Ten sam plik cytuje w linijce wyżej D-035
     * z dziennika i to cytowanie ma dalej podlegać kontroli; a gdy dziennik
     * dojdzie do D-107, kolizja ma być widoczna tutaj, a nie przemilczana.
     *
     * @var array<string, list<string>>
     */
    private const OBCA_NUMERACJA = [
        'resources/css/ekran-dodawania.css' => ['D-107'],
    ];

    /**
     * PROGI — bez nich ten test jest zielony na zawsze.
     *
     * Test skanujący pliki przechodzi także wtedy, gdy nie znajdzie ŻADNEGO
     * pliku i żadnego odnośnika: zbiór sierot z pustego skanu jest pusty, więc
     * asercja przechodzi (`docs/PULAPKI_TESTOW.md`, pułapka 2 — w tym
     * repozytorium jeden taki test był zielony przy PIĘCIU żywych usterkach).
     * Zła ścieżka, przeniesiony katalog albo literówka we wzorcu ma tu OBLAĆ.
     *
     * Progi są dobrane tak, żeby nie trzeba ich było ruszać przy każdym nowym
     * wpisie ani przy sprzątaniu odnośników — a nie „tuż pod stanem na dziś":
     *
     *  - MIN_PLIKOW = 700 przy 964 przeskanowanych dziś. Utrata najmniejszego
     *    z dwóch największych katalogów (`app/` — 296 plików, `tests/` — 372)
     *    zbija liczbę poniżej progu.
     *  - MIN_ODNOSNIKOW = 400 przy 885 wystąpieniach dziś. Próg jest wyższy
     *    niż CAŁA zawartość najbogatszego katalogu (`app/` — 343 wystąpienia),
     *    więc skan, który po zepsuciu czyta tylko jeden katalog, oblewa
     *    niezależnie od tego, który to katalog.
     *  - MIN_WPISOW = 70 przy 78 wpisach dziś. Dziennik jest dopisywany na
     *    końcu i nie kurczy się — ten próg pilnuje ścieżki do pliku i wzorca
     *    nagłówka, nie tempa pracy.
     */
    private const MIN_PLIKOW = 700;

    private const MIN_ODNOSNIKOW = 400;

    private const MIN_WPISOW = 70;

    public function test_kazdy_numer_decyzji_z_kodu_ma_wpis_w_dzienniku(): void
    {
        $wpisy = $this->numeryWpisowDziennika();

        // Dolna granica na dziennik. Gdyby ścieżka do `docs/DECISIONS.md`
        // albo wzorzec nagłówka przestały działać, zbiór wpisów byłby pusty,
        // a KAŻDY odnośnik z kodu zostałby zgłoszony jako sierota. Test
        // obleje wtedy tak czy inaczej, ale z komunikatem mówiącym o 58
        // usterkach w kodzie zamiast o jednej w tym teście.
        $this->assertGreaterThanOrEqual(
            self::MIN_WPISOW,
            count($wpisy),
            'W dzienniku (docs/decyzje/) widać mniej niż '.self::MIN_WPISOW.' wpisów '
            .'(znaleziono '.count($wpisy).'). Dziennik się nie kurczy, więc to '
            .'usterka tego testu: sprawdź ścieżkę do pliku i wzorzec nagłówka '
            .'„## D-NNN", a nie treść dziennika.',
        );

        [$odnosniki, $przeskanowane, $wystapienia] = $this->odnosnikiZKodu();

        $this->assertGreaterThanOrEqual(
            self::MIN_PLIKOW,
            $przeskanowane,
            'Skan przeczytał tylko '.$przeskanowane.' plików, a spodziewamy się '
            .'co najmniej '.self::MIN_PLIKOW.'. Test, który nic nie czyta, '
            .'niczego nie pilnuje — sprawdź stałą KATALOGI.',
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_ODNOSNIKOW,
            $wystapienia,
            'Skan znalazł tylko '.$wystapienia.' odnośników do numerów decyzji, '
            .'a spodziewamy się co najmniej '.self::MIN_ODNOSNIKOW.'. Sam kod '
            .'tyle odnośników nie traci — sprawdź WZORZEC_ODNOSNIKA i KATALOGI.',
        );

        $sieroty = [];

        foreach ($odnosniki as $numer => $miejsca) {
            if (! isset($wpisy[$numer])) {
                sort($miejsca);
                $sieroty[$numer] = $miejsca;
            }
        }

        $this->assertSame([], $sieroty, $this->wyjasnienieSierot($sieroty));
    }

    /**
     * Drugi kierunek: numer w dzienniku, ale zdublowany.
     *
     * Osobny test, nie druga asercja w teście wyżej — dwie awarie, które mają
     * różne przyczyny i różne naprawy, mają dawać dwa różne czerwone wyniki.
     */
    public function test_dziennik_nie_ma_dwoch_wpisow_pod_jednym_numerem(): void
    {
        $tresc = $this->trescDziennika();

        preg_match_all(self::WZORZEC_NAGLOWKA, $tresc, $trafienia);

        // Grupa 1, nie 0: komunikat ma nazwać NUMER („D-051"), a nie nagłówek
        // z markdownowymi kratkami („## D-051"). Człowiek szuka potem numeru.
        /** @var list<string> $numery */
        $numery = array_map(static fn (string $cyfry): string => 'D-'.$cyfry, $trafienia[1]);

        $this->assertGreaterThanOrEqual(
            self::MIN_WPISOW,
            count($numery),
            'W dzienniku (docs/decyzje/) widać mniej niż '.self::MIN_WPISOW.' nagłówków '
            .'(znaleziono '.count($numery).'). To usterka tego testu, nie '
            .'dziennika — sprawdź ścieżkę i wzorzec nagłówka.',
        );

        $ile = array_count_values($numery);
        $zdublowane = array_keys(array_filter($ile, static fn (int $n): bool => $n > 1));

        $this->assertSame(
            [],
            $zdublowane,
            'W dzienniku (docs/decyzje/) ten sam numer decyzji ma więcej niż jeden wpis: '
            .implode(', ', $zdublowane).'. Kod odsyłający do tego numeru trafia '
            .'w dwie różne decyzje naraz. Najczęstsza przyczyna: konflikt '
            .'scalania na końcu pliku rozwiązany „weź obie strony" — nadaj '
            .'jednemu z wpisów pierwszy wolny numer i przepnij odnośniki.',
        );
    }

    // ---------------------------------------------------------------
    // Skan
    // ---------------------------------------------------------------

    /**
     * Odnośniki znalezione w kodzie.
     *
     * @return array{0: array<string, list<string>>, 1: int, 2: int} numer =>
     *                                                               miejsca, liczba przeczytanych plików, liczba wystąpień
     */
    private function odnosnikiZKodu(): array
    {
        $odnosniki = [];
        $przeskanowane = 0;
        $wystapienia = 0;

        foreach ($this->plikiKodu() as $plik) {
            $tresc = @file_get_contents($plik);

            if ($tresc === false) {
                continue;
            }

            $przeskanowane++;

            if (preg_match_all(self::WZORZEC_ODNOSNIKA, $tresc, $trafienia, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            $skrot = $this->skrot($plik);

            if ($skrot === self::POZA_SKANEM) {
                continue;
            }

            $obce = self::OBCA_NUMERACJA[$skrot] ?? [];

            foreach ($trafienia[0] as $trafienie) {
                [$numer, $pozycja] = $trafienie;

                if (in_array($numer, $obce, true)) {
                    continue;
                }

                $wystapienia++;
                $odnosniki[$numer][] = $skrot.':'.$this->numerLinii($tresc, (int) $pozycja);
            }
        }

        ksort($odnosniki);

        return [$odnosniki, $przeskanowane, $wystapienia];
    }

    /**
     * Numery wpisów w dzienniku, jako zbiór („D-051" => true).
     *
     * @return array<string, true>
     */
    private function numeryWpisowDziennika(): array
    {
        preg_match_all(self::WZORZEC_NAGLOWKA, $this->trescDziennika(), $trafienia);

        $wpisy = [];

        foreach ($trafienia[0] as $naglowek) {
            $wpisy[trim(str_replace('## ', '', $naglowek))] = true;
        }

        return $wpisy;
    }

    private function trescDziennika(): string
    {
        $dziennik = new DziennikDecyzji(base_path());

        $this->assertNotSame(
            [],
            $dziennik->pliki(),
            'Nie ma wpisów w '.DziennikDecyzji::KATALOG.'/ — a to są pliki, wobec których ten test '
            .'sprawdza wszystkie odnośniki z kodu.',
        );

        return $dziennik->tresc();
    }

    /**
     * Wszystkie pliki z katalogów `KATALOGI`, bez filtra po rozszerzeniu.
     *
     * Filtr po rozszerzeniu byłby tu czwartą rzeczą, którą trzeba pamiętać
     * zaktualizować: odnośniki siedzą dziś w `.php`, `.blade.php`, `.css`,
     * `.js` i w jednym `.md`, a nic nie mówi, że następny nie trafi do `.mjs`
     * albo `.json`. Plik binarny w tych katalogach nie zaszkodzi — `D-NNN`
     * w bajtach obrazka to zbieg okoliczności, którego dziś nie ma i który
     * i tak zgłosiłby się jako czytelna sierota z nazwą pliku.
     *
     * @return list<string>
     */
    private function plikiKodu(): array
    {
        $znalezione = [];

        foreach (self::KATALOGI as $katalog) {
            $sciezka = base_path($katalog);

            if (! is_dir($sciezka)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sciezka, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $plik) {
                if ($plik instanceof \SplFileInfo && $plik->isFile()) {
                    $znalezione[] = $plik->getPathname();
                }
            }
        }

        sort($znalezione);

        return $znalezione;
    }

    private function numerLinii(string $tresc, int $pozycja): int
    {
        return substr_count(substr($tresc, 0, $pozycja), "\n") + 1;
    }

    private function skrot(string $sciezka): string
    {
        return str_replace(base_path().'/', '', $sciezka);
    }

    /**
     * Komunikat wymienia NUMERY I MIEJSCA, nie samo „są sieroty".
     *
     * Sierota jest usterką dokumentacji, więc naprawia ją człowiek czytający
     * dziennik, nie ten, kto uruchomił testy. Bez listy plików i linii
     * pierwszym krokiem po czerwonym wyniku jest ten sam `grep`, który ten
     * test właśnie wykonał.
     *
     * @param  array<string, list<string>>  $sieroty
     */
    private function wyjasnienieSierot(array $sieroty): string
    {
        if ($sieroty === []) {
            return '';
        }

        $linie = [
            'Kod cytuje numery decyzji, których nie ma w dzienniku (docs/decyzje/, indeks docs/DECISIONS.md):',
            '',
        ];

        foreach ($sieroty as $numer => $miejsca) {
            $linie[] = $numer.' — cytowane w '.count($miejsca).' miejscach:';

            foreach ($miejsca as $miejsce) {
                $linie[] = '    '.$miejsce;
            }

            $linie[] = '';
        }

        $linie[] = 'Trzy wyjścia, w tej kolejności do sprawdzenia:';
        $linie[] = ' 1. decyzja jest w dzienniku pod INNYM numerem — przepnij odnośniki;';
        $linie[] = ' 2. decyzji nikt nie podjął, a treść wynika z dokumentu wiążącego';
        $linie[] = '    (np. docs/brand/COPY_STYLE.md) — usuń numer, zostaw odnośnik do issue;';
        $linie[] = ' 3. decyzja jest realna, a wpisu brakuje — dodaj plik docs/decyzje/D-NNN-slug.md (docs/DECISIONS.md, „Jak dodać decyzję").';
        $linie[] = '';
        $linie[] = 'Czego NIE robić: wymyślać decyzji, żeby zapełnić lukę. Numer decyzji jest';
        $linie[] = 'odnośnikiem — martwy odnośnik wygląda na uzasadnienie i zatrzymuje szukanie.';

        return implode("\n", $linie);
    }
}
