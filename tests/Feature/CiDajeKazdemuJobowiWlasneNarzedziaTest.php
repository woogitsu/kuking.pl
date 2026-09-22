<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dwa joby, jedna binarka Composera — i CI świeci czerwono przy poprawnym kodzie.
 *
 * CO SIĘ STAŁO 10 WRZEŚNIA 2026 o 11:53:47 UTC (przebieg 34473498102)
 * Job „Testy (PostgreSQL 18)" padł z kodem 126 na kroku `composer install`,
 * nie uruchamiając ani jednego testu:
 *
 *     …/_work/_temp/b904f771….sh: …/_work/_tool/setup-php/tools/composer:
 *       /usr/bin/env: bad interpreter: Text file busy
 *
 * „Text file busy" (ETXTBSY) dostaje ten, kto robi `execve()` na pliku, który
 * w tej samej chwili ktoś inny trzyma otwarty do zapisu. Piszącym był sąsiedni
 * job z TEGO SAMEGO przebiegu („Dostępność" na runnerze `-02`, krok
 * „Konfiguracja PHP" 11:53:46→47), bo `shivammathur/setup-php` nadpisuje
 * binarkę narzędzia w miejscu, z którego się ją wykonuje, a domyślnie jest to
 * ścieżka wspólna dla całej maszyny (`/usr/local/bin`, patrz `read_env`
 * w `src/scripts/unix.sh` akcji). Nasze trzy runnery to trzy rejestracje
 * na jednej maszynie, więc dzielą ten katalog.
 *
 * CZEGO PILNUJE TEN TEST
 * Tego jednego: że każdy job, który stawia PHP przez `setup-php`, dostaje
 * WŁASNY katalog na binarki narzędzi — po numerze przebiegu i nazwie joba —
 * i że dostaje go PRZED krokiem `setup-php`, bo po nim zmienna nie ma już
 * na co wpłynąć. Zmienne czyta sama akcja (`SETUP_PHP_TOOLS_DIR`
 * i `SETUP_PHP_TOOL_CACHE_DIR` w `read_env`).
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Że na runnerach jest zielono — tego nie da się sprawdzić bez runnerów.
 * Nie widzi też konfiguracji maszyny: jeśli ktoś wpisze `SETUP_PHP_TOOLS_DIR`
 * do `.env` runnera, ten test nadal będzie zielony, a joby znów będą dzielić
 * jeden plik. Pilnuje strony, którą trzymamy w repozytorium — i pilnuje jej
 * tak, żeby nie dało się jej cofnąć przez nieuwagę przy dodawaniu kolejnego
 * joba z PHP.
 */
class CiDajeKazdemuJobowiWlasneNarzedziaTest extends TestCase
{
    /**
     * Trzy znaczniki, bez których ścieżka nie jest prywatna dla joba:
     * katalog tymczasowy runnera, numer przebiegu i nazwa joba.
     */
    private const ZNACZNIKI = ['RUNNER_TEMP', 'GITHUB_RUN_ID', 'GITHUB_JOB'];

    /**
     * Zwraca listę zastrzeżeń. Pusta lista = wszystkie joby z `setup-php`
     * mają własny katalog narzędzi założony przed konfiguracją PHP.
     *
     * @return array{0: list<string>, 1: int} zastrzeżenia oraz liczba
     *                                        przeskanowanych jobów z `setup-php`
     */
    private function wadyIzolacjiNarzedzi(string $yaml): array
    {
        // NIE `\R`: bez modyfikatora `u` PCRE traktuje bajt 0x85 jako
        // znak końca wiersza (NEL), a to jest DRUGI BAJT polskich „ą" i „ł".
        // Zmierzone na tym pliku: `preg_split('/\R/')` rozrywało wiersze
        // w środku wyrazu, skaner nie widział ANI JEDNEGO joba i test
        // przechodził, nie sprawdzając niczego.
        $wiersze = preg_split('/\r\n|\n|\r/', $yaml) ?: [];
        $wady = [];
        $zbadane = 0;

        foreach ($this->joby($wiersze) as $nazwa => [$od, $do]) {
            $blok = $this->zWstawionaAkcja(array_slice($wiersze, $od, $do - $od));

            $setupPhp = $this->pierwszyKrokSetupPhp($blok);

            if ($setupPhp === null) {
                continue;
            }

            $zbadane++;

            // Wszystko PO kroku `setup-php` jest bez znaczenia: akcja czyta
            // zmienne w chwili uruchomienia, więc liczy się tylko to, co
            // zostało wyeksportowane wcześniej.
            $przed = array_slice($blok, 0, $setupPhp);
            $podstawienia = $this->podstawieniaPowloki($przed);

            foreach (['SETUP_PHP_TOOLS_DIR', 'SETUP_PHP_TOOL_CACHE_DIR'] as $zmienna) {
                $wartosc = $this->eksportowanaWartosc($przed, $zmienna);

                if ($wartosc === null) {
                    // Zmienna może stać niżej — wtedy jest, ale nie działa,
                    // i komunikat ma to rozróżniać.
                    $gdziekolwiek = $this->eksportowanaWartosc($blok, $zmienna);

                    $wady[] = $gdziekolwiek === null
                        ? sprintf('job „%s": brak `%s` przed krokiem `setup-php`', $nazwa, $zmienna)
                        : sprintf('job „%s": `%s` jest ustawiane PO kroku `setup-php`, czyli za późno', $nazwa, $zmienna);

                    continue;
                }

                $rozwinieta = strtr($wartosc, $podstawienia);

                foreach (self::ZNACZNIKI as $znacznik) {
                    if (! str_contains($rozwinieta, $znacznik)) {
                        $wady[] = sprintf(
                            'job „%s": `%s` = „%s" nie zawiera `%s`, więc ta ścieżka nie jest prywatna dla tego joba',
                            $nazwa,
                            $zmienna,
                            $rozwinieta,
                            $znacznik,
                        );
                    }
                }
            }
        }

        return [$wady, $zbadane];
    }

    /**
     * Granice bloków jobów: klucz na wcięciu dwóch spacji pod `jobs:`.
     *
     * @param  list<string>  $wiersze
     * @return array<string, array{0: int, 1: int}>
     */
    private function joby(array $wiersze): array
    {
        $joby = [];
        $wJobach = false;
        $biezacy = null;

        foreach ($wiersze as $numer => $wiersz) {
            if (preg_match('/^jobs:\s*$/', $wiersz) === 1) {
                $wJobach = true;

                continue;
            }

            if (! $wJobach) {
                continue;
            }

            // Klucz na wcięciu zerowym kończy sekcję `jobs:`.
            if (preg_match('/^[A-Za-z_]/', $wiersz) === 1) {
                if ($biezacy !== null) {
                    $joby[$biezacy][1] = $numer;
                    $biezacy = null;
                }

                $wJobach = false;

                continue;
            }

            if (preg_match('/^ {2}([A-Za-z0-9_-]+):\s*$/', $wiersz, $dopasowanie) === 1) {
                if ($biezacy !== null) {
                    $joby[$biezacy][1] = $numer;
                }

                $biezacy = $dopasowanie[1];
                $joby[$biezacy] = [$numer + 1, count($wiersze)];
            }
        }

        return $joby;
    }

    /**
     * Pierwszy KROK stawiający PHP, a nie pierwsza wzmianka o akcji.
     *
     * Szukanie samej nazwy akcji dawało tu fałszywy alarm: komentarz nad
     * krokiem „Własny katalog narzędzi PHP" wyjaśnia, co robi `setup-php`,
     * więc nazwa akcji pada w pliku WCZEŚNIEJ niż krok, którego dotyczy.
     * Skaner raportował wtedy, że katalog powstaje po konfiguracji PHP.
     *
     * @param  list<string>  $wiersze
     */
    /**
     * Job, który bierze środowisko PHP ze wspólnej akcji, jest skanowany tak,
     * jakby miał jej kroki wpisane u siebie.
     *
     * DLACZEGO PODSTAWIENIE, A NIE OSOBNA ŚCIEŻKA W SKANERZE
     * Bo gwarancja jest DOKŁADNIE TA SAMA: prywatny katalog musi powstać
     * przed `setup-php`. Gdyby skaner sprawdzał akcję osobnym kodem, byłyby
     * dwie implementacje jednej reguły — czyli to, co ten pakiet likwiduje.
     * Po podstawieniu cała logika niżej działa bez zmian, a każdy kolejny
     * job przeniesiony na wspólną akcję jest obejmowany automatycznie.
     *
     * DLACZEGO TO NIE ROZMYWA GWARANCJI
     * `RUNNER_TEMP`, `GITHUB_RUN_ID` i `GITHUB_JOB` w krokach composite action
     * mają wartości JOBA, który ją wywołał — ścieżka zostaje prywatna dla
     * każdego joba osobno. Gdyby akcja przestała eksportować którąkolwiek
     * zmienną albo odwróciła kolejność kroków, ten test oblewa tak samo, jak
     * oblewał przy kodzie wpisanym wprost w job.
     *
     * @param  list<string>  $blok
     * @return list<string>
     */
    private function zWstawionaAkcja(array $blok): array
    {
        $sciezka = base_path('.github/actions/php/action.yml');

        if (! is_file($sciezka)) {
            return $blok;
        }

        $krokiAkcji = preg_split('/\r\n|\n|\r/', (string) file_get_contents($sciezka)) ?: [];

        $wynik = [];

        foreach ($blok as $wiersz) {
            if (preg_match('#^\s*-?\s*uses:\s*\./\.github/actions/php\s*$#', $wiersz) === 1) {
                // Kroki akcji wchodzą W MIEJSCE jej wywołania, więc zachowują
                // kolejność względem pozostałych kroków joba.
                foreach ($krokiAkcji as $wierszAkcji) {
                    $wynik[] = $wierszAkcji;
                }

                continue;
            }

            $wynik[] = $wiersz;
        }

        return $wynik;
    }

    private function pierwszyKrokSetupPhp(array $wiersze): ?int
    {
        foreach ($wiersze as $numer => $wiersz) {
            if (preg_match('#^\s*-?\s*uses:\s*shivammathur/setup-php#', $wiersz) === 1) {
                return $numer;
            }
        }

        return null;
    }

    /**
     * Wartość wyeksportowana do `$GITHUB_ENV`, czyli to, co zobaczy akcja.
     * Świadomie NIE czyta bloków `env:` — `setup-php` czyta zmienne
     * środowiska, a my ustawiamy je krokiem, bo tylko krok widzi
     * `$RUNNER_TEMP`.
     *
     * @param  list<string>  $wiersze
     */
    private function eksportowanaWartosc(array $wiersze, string $zmienna): ?string
    {
        foreach ($wiersze as $wiersz) {
            $wzor = '/'.preg_quote($zmienna, '/').'=([^"\']+)/';

            if (str_contains($wiersz, 'GITHUB_ENV') && preg_match($wzor, $wiersz, $dopasowanie) === 1) {
                return trim($dopasowanie[1]);
            }
        }

        return null;
    }

    /**
     * Proste podstawienia z powłoki (`KATALOG="…"`), żeby dało się sprawdzić
     * ścieżkę złożoną ze zmiennej pomocniczej, a nie tylko wpisaną wprost.
     *
     * @param  list<string>  $wiersze
     * @return array<string, string>
     */
    private function podstawieniaPowloki(array $wiersze): array
    {
        $podstawienia = [];

        foreach ($wiersze as $wiersz) {
            if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)="([^"]*)"\s*$/', $wiersz, $dopasowanie) === 1) {
                $podstawienia['${'.$dopasowanie[1].'}'] = $dopasowanie[2];
                $podstawienia['$'.$dopasowanie[1]] = $dopasowanie[2];
            }
        }

        return $podstawienia;
    }

    #[Test]
    public function kazdy_job_stawiajacy_php_ma_wlasny_katalog_narzedzi(): void
    {
        $pliki = glob(base_path('.github/workflows/*.yml')) ?: [];

        $this->assertGreaterThan(
            3,
            count($pliki),
            'Znalazłem mniej niż cztery pliki workflow — czytam złe miejsce, więc ten test niczego nie mierzy.',
        );

        $razem = 0;

        foreach ($pliki as $sciezka) {
            [$wady, $zbadane] = $this->wadyIzolacjiNarzedzi((string) file_get_contents($sciezka));
            $razem += $zbadane;

            $this->assertSame(
                [],
                $wady,
                basename($sciezka).': '.implode('; ', $wady)."\n"
                .'Joby jednego przebiegu chodzą równolegle na TEJ SAMEJ maszynie, a `setup-php` nadpisuje binarkę '
                ."Composera w miejscu, z którego się ją wykonuje.\n"
                .'Job, który wykona plik w chwili, gdy sąsiad go nadpisuje, pada z kodem 126 na '
                .'„composer: bad interpreter: Text file busy” — bez ani jednego uruchomionego testu '
                ."(issue #262).\n"
                .'Dołóż krok „Własny katalog narzędzi PHP” PRZED „Konfiguracja PHP”, tak jak w jobie `lint`.',
            );
        }

        // Bramka pilnuje, żeby skaner nie stracił po cichu pokrycia.
        //
        // Liczba jest TWARDA, nie „co najmniej sześć": przy przenoszeniu jobów
        // na wspólną akcję (`.github/actions/php`) każdy nieuwzględniony job
        // zmniejszałby pokrycie o jeden, a luźna bramka przepuściłaby to bez
        // słowa aż do zera. Skaner podstawia kroki akcji, więc liczba ma
        // zostać TA SAMA niezależnie od tego, ile jobów już przeniesiono.
        $this->assertSame(
            9,
            $razem,
            "Przeskanowałem {$razem} jobów stawiających PHP, a ma ich być dziewięć. "
            .'Albo doszedł job bez izolacji narzędzi, albo skaner przestał widzieć któryś '
            .'z istniejących — a test, który nie znajduje NICZEGO, przechodzi i nie pilnuje niczego.',
        );
    }

    /**
     * Kontrola ujemna: skaner ma widzieć job bez własnego katalogu.
     * Bez niej cały test mógłby zwracać pustą listę na wszystko.
     */
    #[Test]
    public function skaner_widzi_job_bez_wlasnego_katalogu(): void
    {
        $zepsuty = <<<'YAML'
        jobs:
          test:
            steps:
              - uses: actions/checkout@v7
              - name: Konfiguracja PHP
                uses: shivammathur/setup-php@v2
                with:
                  tools: composer:v2
              - name: Instalacja zależności
                run: composer install
        YAML;

        [$wady, $zbadane] = $this->wadyIzolacjiNarzedzi($zepsuty);

        $this->assertSame(1, $zbadane);
        $this->assertNotSame([], $wady, 'Skaner nie widzi joba, który dzieli binarkę Composera z sąsiadami.');
    }

    /**
     * Druga kontrola ujemna: katalog JEST, ale wspólny dla wszystkich jobów.
     * To dokładnie stan sprzed poprawki — plik jeden, joby trzy.
     */
    #[Test]
    public function skaner_widzi_katalog_wspolny_dla_jobow(): void
    {
        $zepsuty = <<<'YAML'
        jobs:
          test:
            steps:
              - name: Własny katalog narzędzi PHP
                run: |
                  echo "SETUP_PHP_TOOLS_DIR=/usr/local/bin" >> "$GITHUB_ENV"
                  echo "SETUP_PHP_TOOL_CACHE_DIR=/opt/hostedtoolcache/setup-php/tools" >> "$GITHUB_ENV"
              - name: Konfiguracja PHP
                uses: shivammathur/setup-php@v2
        YAML;

        [$wady, $zbadane] = $this->wadyIzolacjiNarzedzi($zepsuty);

        $this->assertSame(1, $zbadane);
        $this->assertNotSame([], $wady, 'Skaner przepuścił ścieżkę wspólną dla całej maszyny.');
    }

    /**
     * Trzecia kontrola ujemna: kolejność. Zmienna ustawiona PO `setup-php`
     * wygląda w pliku identycznie, a nie robi nic.
     */
    #[Test]
    public function skaner_widzi_katalog_zalozony_za_pozno(): void
    {
        $zepsuty = <<<'YAML'
        jobs:
          test:
            steps:
              - name: Konfiguracja PHP
                uses: shivammathur/setup-php@v2
              - name: Własny katalog narzędzi PHP
                run: |
                  KATALOG="${RUNNER_TEMP}/kuking-narzedzia/${GITHUB_RUN_ID}-${GITHUB_JOB}"
                  echo "SETUP_PHP_TOOLS_DIR=$KATALOG/bin" >> "$GITHUB_ENV"
                  echo "SETUP_PHP_TOOL_CACHE_DIR=$KATALOG/cache" >> "$GITHUB_ENV"
        YAML;

        [$wady] = $this->wadyIzolacjiNarzedzi($zepsuty);

        $this->assertNotSame([], $wady, 'Skaner nie widzi, że katalog powstaje już po konfiguracji PHP.');
        $this->assertStringContainsString('za późno', implode('; ', $wady));
    }

    /**
     * Kontrola dodatnia: poprawny job musi przechodzić. Bez niej test mógłby
     * być „zawsze czerwony", a taki wyłącza się po dwóch dniach.
     */
    #[Test]
    public function skaner_przepuszcza_job_z_wlasnym_katalogiem(): void
    {
        $poprawny = <<<'YAML'
        jobs:
          test:
            steps:
              - name: Własny katalog narzędzi PHP
                run: |
                  KATALOG="${RUNNER_TEMP}/kuking-narzedzia/${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}-${GITHUB_JOB}"
                  mkdir -p "$KATALOG/bin" "$KATALOG/cache"
                  echo "SETUP_PHP_TOOLS_DIR=$KATALOG/bin" >> "$GITHUB_ENV"
                  echo "SETUP_PHP_TOOL_CACHE_DIR=$KATALOG/cache" >> "$GITHUB_ENV"
              - name: Konfiguracja PHP
                uses: shivammathur/setup-php@v2
        YAML;

        [$wady, $zbadane] = $this->wadyIzolacjiNarzedzi($poprawny);

        $this->assertSame(1, $zbadane);
        $this->assertSame([], $wady);
    }
}
