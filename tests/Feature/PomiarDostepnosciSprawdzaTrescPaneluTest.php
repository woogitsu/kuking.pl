<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Każdy ekran panelu moderacji dopisany do pomiaru dostępności ma tam
 * SPRAWDZENIE, CO NA NIM STOI — a nie tylko adres na liście.
 *
 * SKĄD TEN TEST. Panel wszedł do `scripts/dostepnosc.mjs` 11 września
 * (issue #294, D-089): trzy adresy, prawdziwe logowanie kontem moderatora
 * z prawdziwym 2FA, sprawdzenie kodu HTTP i sprawdzenie ścieżki po wejściu.
 * Wyglądało to na komplet i raport pokazywał trzy ✓. Pomiar zrobiony dzień
 * później pokazał, że DWA z tych trzech ✓ dotyczyły PUSTEGO STANU:
 *
 *     ekran                 co stało na ekranie              węzłów w <main>
 *     /admin/uzytkownicy    tabela czterech kont                        126
 *     /admin/zgloszenia     „Nic tu nie ma"                              19
 *     /admin/sygnaly        „Nic tu nie ma"                              18
 *
 * `DemoSeeder` nie tworzy ani jednego zgłoszenia, a kolejka moderatora
 * pokazuje domyślnie sprawy OTWARTE i wyłącznie te od ludzi — więc karta
 * sprawy z formularzem decyzji i karta grupy automatu nie były na ekranie ani
 * razu. Ani kod HTTP, ani ścieżka tego nie łapią: pusta kolejka odpowiada 200
 * pod własnym adresem i w raporcie wygląda identycznie jak kolejka pełna.
 *
 * To jest pułapka 5 z `docs/PULAPKI_TESTOW.md` („narzędzie melduje sukces,
 * nie robiąc nic") w tym samym przebraniu, w którym złapała już
 * `/nie-pamietam-hasla` (D-106): ekran ma dwa prawdziwe stany, mierzony jest
 * ten pusty, a wynik nie odróżnia się od wyniku poprawnego.
 *
 * CZEGO TEN TEST PILNUJE, A CZEGO NIE. Nie uruchamia automatu — PHPUnit nie
 * postawi Chromium ani serwera, a przebieg trwa kilkanaście minut. Pilnuje
 * jednej rzeczy, której zniknięcie nie zostawia śladu w raporcie: że dla
 * KAŻDEGO ekranu panelu na liście `EKRANY` istnieje w `TRESC_PANELU` wpis
 * mówiący, co na tym ekranie musi stać. Dopisanie czwartego ekranu panelu
 * (`/admin/odwolania`, `/admin/wiadomosci`, `/admin/kuking-na-dzis`…) bez
 * takiego wpisu oblewa ten test — i to jest cały jego sens: następna osoba
 * dowie się o tym z czerwonego CI, a nie z raportu, który świeci na zielono
 * nad pustym ekranem.
 *
 * @see PomiarDostepnosciObejmujeStronyPubliczneTest — czy ekran jest na liście
 * @see PomiarDostepnosciKonczyKodemJedenTest — czy naruszenie zatrzymuje CI
 */
class PomiarDostepnosciSprawdzaTrescPaneluTest extends TestCase
{
    /**
     * Najmniejszy dopuszczalny próg węzłów w `<main>`.
     *
     * NIE JEST TO LICZBA Z SUFITU: pusty stan obu kolejek panelu zmierzono na
     * 19 i 18 węzłów (tabela w docblocku wyżej). Próg poniżej tych liczb
     * przepuszczałby dokładnie to, przed czym ma bronić — czyli byłby
     * sprawdzeniem, które przechodzi także wtedy, gdy mierzony jest pusty
     * ekran. Dwukrotność najgorszego zmierzonego pustego stanu jest tu
     * granicą dolną, a nie zaleceniem: prawdziwe wartości to 126, 186 i 73.
     */
    private const PROG_MINIMALNY = 40;

    private function zrodlo(): string
    {
        $sciezka = base_path('scripts/dostepnosc.mjs');

        $this->assertFileExists($sciezka, 'Nie ma automatu dostępności — ten test pilnowałby pustki.');

        return (string) file_get_contents($sciezka);
    }

    /**
     * Wycinek pliku między nazwą stałej a zamykającym `];` — ten sam sposób
     * wycinania, co w `PomiarDostepnosciObejmujeStronyPubliczneTest`.
     */
    private function blok(string $nazwa): string
    {
        $zrodlo = $this->zrodlo();

        $poczatek = strpos($zrodlo, "const {$nazwa} = [");
        $this->assertNotFalse($poczatek, "W automacie nie ma już listy `{$nazwa}`.");

        $koniec = strpos($zrodlo, "\n];", $poczatek);
        $this->assertNotFalse($koniec, "Lista `{$nazwa}` nie ma końca — zmienił się kształt pliku.");

        return substr($zrodlo, $poczatek, $koniec - $poczatek);
    }

    /**
     * Adresy ekranów oznaczonych w `EKRANY` jako wymagające moderatora.
     *
     * @return list<string>
     */
    private function ekranyPanelu(): array
    {
        $adresy = [];

        foreach (explode("\n", $this->blok('EKRANY')) as $linia) {
            if (! str_contains($linia, 'moderator: true')) {
                continue;
            }

            if (preg_match("~adres:\s*'([^']+)'~", $linia, $trafienie) === 1) {
                $adresy[] = $trafienie[1];
            }
        }

        /*
         * PRÓG LICZBY TRAFIEŃ (pułapka 2 z `docs/PULAPKI_TESTOW.md`).
         *
         * Skan, który nie znajduje NICZEGO, przechodzi — zero pominiętych
         * ekranów jest dla niego sukcesem. Bez tej asercji zmiana nazwy
         * stałej, kształtu wpisu albo znacznika `moderator: true` wyłącza ten
         * test bez jednego czerwonego przebiegu.
         */
        $this->assertGreaterThanOrEqual(
            3,
            count($adresy),
            'Z listy `EKRANY` wyszło mniej niż trzy ekrany panelu, a tyle weszło tam przy '
            .'issue #294 (/admin/uzytkownicy, /admin/zgloszenia, /admin/sygnaly). Albo któryś '
            .'wypadł z pomiaru, albo zmienił się kształt pliku i ten test przestał cokolwiek mierzyć.',
        );

        return $adresy;
    }

    /**
     * Wpisy z `TRESC_PANELU`: ścieżka, selektor treści i próg węzłów.
     *
     * @return list<array{sciezka: string, wybor: string, prog: int}>
     */
    private function kontroleTresci(): array
    {
        $blok = $this->blok('TRESC_PANELU');

        $wpisy = [];
        $biezacy = null;

        foreach (explode("\n", $blok) as $linia) {
            if (preg_match("~sciezka:\s*'([^']+)'~", $linia, $trafienie) === 1) {
                $biezacy = ['sciezka' => $trafienie[1], 'wybor' => '', 'prog' => 0];

                continue;
            }

            if ($biezacy === null) {
                continue;
            }

            if (preg_match("~wybor:\s*'([^']+)'~", $linia, $trafienie) === 1) {
                $biezacy['wybor'] = $trafienie[1];
            }

            if (preg_match('~progWezlow:\s*(\d+)~', $linia, $trafienie) === 1) {
                $biezacy['prog'] = (int) $trafienie[1];
                $wpisy[] = $biezacy;
                $biezacy = null;
            }
        }

        // Ta sama kontrola dodatnia co wyżej, dla drugiego skanu. Wpis bez
        // progu nie domyka się i nie wchodzi na listę, więc zbyt mała liczba
        // wpisów znaczy „zmienił się kształt pliku", a nie „nie ma co mierzyć".
        $this->assertGreaterThanOrEqual(
            3,
            count($wpisy),
            'Z listy `TRESC_PANELU` wyszło mniej niż trzy kompletne wpisy (ścieżka, selektor, '
            .'próg). Zmienił się kształt pliku i ten test przestał cokolwiek mierzyć.',
        );

        return $wpisy;
    }

    public function test_kazdy_ekran_panelu_z_pomiaru_ma_sprawdzenie_tresci(): void
    {
        $sprawdzane = array_column($this->kontroleTresci(), 'sciezka');

        $bezSprawdzenia = array_values(array_diff($this->ekranyPanelu(), $sprawdzane));

        $this->assertSame(
            [],
            $bezSprawdzenia,
            "Te ekrany panelu są mierzone, ale nikt nie sprawdza, CO na nich stoi: \n  "
            .implode("\n  ", $bezSprawdzenia)."\n"
            .'Dopisz je do `TRESC_PANELU` w `scripts/dostepnosc.mjs`, razem z selektorem '
            .'treści, która na ekranie musi być. Bez tego pusta kolejka odpowiada 200 pod '
            .'własnym adresem i raport zapisuje „✓" dla ekranu, na którym nic nie stało — '
            .'zmierzone przy issue #294: 19 i 18 węzłów w <main> na dwóch z trzech ekranów panelu.',
        );
    }

    public function test_kazde_sprawdzenie_tresci_ma_selektor_i_prog_powyzej_pustego_stanu(): void
    {
        $niedomkniete = [];

        foreach ($this->kontroleTresci() as $wpis) {
            if ($wpis['wybor'] === '') {
                $niedomkniete[] = "{$wpis['sciezka']}: brak selektora treści";

                continue;
            }

            if ($wpis['prog'] < self::PROG_MINIMALNY) {
                $niedomkniete[] = "{$wpis['sciezka']}: próg {$wpis['prog']} węzłów, "
                    .'a pusty stan kolejki panelu ma ich 19 — taki próg przepuszcza pusty ekran';
            }
        }

        $this->assertSame(
            [],
            $niedomkniete,
            "Sprawdzenia treści panelu, które nie sprawdzają tego, co miały: \n  "
            .implode("\n  ", $niedomkniete),
        );
    }

    public function test_brak_tresci_na_ekranie_panelu_zatrzymuje_przebieg(): void
    {
        $zrodlo = $this->zrodlo();

        /*
         * Sprawdzenie bez warunku wyjścia jest gorsze niż jego brak: automat
         * chodzi, mierzy, drukuje liczbę węzłów i kończy zerem. Dokładnie ta
         * usterka wydarzyła się już w tym pliku (patrz
         * `PomiarDostepnosciKonczyKodemJedenTest`), dlatego pilnujemy tu nie
         * samego istnienia funkcji, ale tego, że jej wynik ZATRZYMUJE PRZEBIEG.
         */
        $this->assertStringContainsString(
            'przeszkodaWPanelu(przegladarka, adres, stanModeratorem)',
            $zrodlo,
            'Automat nie woła już sprawdzenia treści panelu — trzy ekrany panelu wróciłyby do '
            .'mierzenia pustego stanu.',
        );

        $poczatek = strpos($zrodlo, 'if (przeszkodaPanelu !== null) {');
        $this->assertNotFalse(
            $poczatek,
            'Zniknął warunek reagujący na przeszkodę w panelu. Sprawdzenie bez warunku wyjścia '
            .'drukuje liczby i kończy zerem.',
        );

        // `"\n}"`, nie samo `"}"`: pierwszym nawiasem klamrowym w tym bloku
        // jest ten z `${przeszkodaPanelu}` w komunikacie, więc wycinek kończyłby
        // się przed warunkiem wyjścia i test oblewałby się nad poprawnym kodem.
        $koniec = strpos($zrodlo, "\n}", $poczatek);
        $this->assertNotFalse($koniec);

        $this->assertStringContainsString(
            'process.exit(1)',
            substr($zrodlo, $poczatek, $koniec - $poczatek),
            'Przeszkoda w panelu nie kończy już przebiegu kodem 1 — automat zapisałby „✓" dla '
            .'ekranów, których nie zmierzył w stanie, o który chodzi.',
        );
    }

    public function test_automat_sam_zasila_kolejki_panelu_danymi(): void
    {
        $zrodlo = $this->zrodlo();

        /*
         * Bez tego bloku sprawdzenie treści wyżej oblewałoby się przy każdym
         * przebiegu na świeżej bazie — `DemoSeeder` nie tworzy ani jednego
         * zgłoszenia. Jedno bez drugiego nie ma sensu, więc pilnujemy pary.
         */
        $this->assertStringContainsString(
            'const kolejkiPanelu = (() => {',
            $zrodlo,
            'Automat nie zakłada już spraw w kolejkach panelu — `/admin/zgloszenia` '
            .'i `/admin/sygnaly` wróciłyby do pustego stanu przy każdym przebiegu.',
        );

        $this->assertStringContainsString(
            'OznaczDoPrzegladu',
            $zrodlo,
            'Oznaczenia automatu nie powstają już prawdziwą akcją `OznaczDoPrzegladu` — kolejka '
            .'`/admin/sygnaly` byłaby mierzona na danych o innym kształcie niż w produkcie.',
        );

        $poczatek = strpos($zrodlo, 'if (kolejkiPanelu === null) {');
        $this->assertNotFalse(
            $poczatek,
            'Nieudane przygotowanie kolejek panelu nie jest już błędem — przebieg poszedłby '
            .'dalej i zmierzył pusty stan.',
        );

        $koniec = strpos($zrodlo, "\n}", $poczatek);
        $this->assertNotFalse($koniec);

        $this->assertStringContainsString(
            'process.exit(1)',
            substr($zrodlo, $poczatek, $koniec - $poczatek),
            'Nieudane przygotowanie kolejek panelu nie zatrzymuje przebiegu.',
        );
    }
}
