<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Skrypty testowe i workflowy CI nie wypisują w logu WARTOŚCI zmiennej
 * środowiskowej, której nazwa wygląda na wrażliwą (#1949).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * GitHub CodeQL (`js/clear-text-logging`) trzymał dwa otwarte alerty wysokiej
 * ważności: `scripts/bez-javascriptu.mjs:134` i `scripts/fokus-karty-dania.mjs:97`
 * — oba `console.log` interpolowały `env().DB_DATABASE`. Przy okazji tego
 * zgłoszenia znalazły się jeszcze CZTERY skrypty z dokładnie tym samym
 * wzorcem (`glowka-karty-wpisu.mjs`, `hero-nad-zgieciem.mjs`,
 * `kafel-dodawania.mjs`, `port-projektu.mjs`) — CodeQL ich nie zgłosił
 * (prawdopodobnie próbkowanie/duplikaty), ale klasa błędu jest identyczna.
 * W CI nazwa bazy bywa techniczna, ale lokalnie albo na innym runnerze może
 * nieść nazwę środowiska, identyfikator klienta albo inną informację
 * operacyjną — a jeśli kiedyś zmienna środowiskowa zacznie nosić w nazwie
 * TOKEN/SECRET/KEY/PASSWORD/DSN, ten sam wzorzec ujawni sekret wprost w logu.
 * Sześć zidentyfikowanych plików naprawiono bezpośrednio (patrz commit) —
 * `DB_DATABASE` sama w sobie nie jest nazwą wrażliwą w rozumieniu tego
 * strażnika, więc TEN skan jej nie łapie i nie ma jej łapać; pilnuje
 * KLASY błędu na przyszłość, dla nazw rzeczywiście wrażliwych.
 *
 * CO TEN TEST SPRAWDZA
 * Każdy plik `scripts/*.mjs`, `scripts/*.sh` i `.github/workflows/*.yml` pod
 * kątem wypisania (JS: `console.log`/`console.error`/`console.warn`;
 * powłoka: `echo`/`printf`) WARTOŚCI zmiennej środowiskowej, której nazwa
 * zawiera `TOKEN`, `SECRET`, `KEY`, `PASSWORD` albo `DSN` (bez względu na
 * wielkość liter). Samo wypisanie NAZWY zmiennej albo faktu, że jest
 * ustawiona, jest dozwolone — zakazana jest interpolacja jej WARTOŚCI.
 *
 * KONTROLA UJEMNA (pułapka 2 z `docs/PULAPKI_TESTOW.md`)
 * `test_klasyfikator_nie_lapie_bezpiecznego_logowania` dowodzi, że skan nie
 * łapie wszystkiego jak leci — bezpieczny wzorzec (log samej nazwy zmiennej,
 * bez jej wartości) ma PRZEJŚĆ. Bez tej kontroli klasyfikator złapany na
 * gorącym uczynku (dopasowujący każdą linijkę z `console.log`/`echo`) byłby
 * zawsze czerwony i skończyłby wyłączony.
 * `test_klasyfikator_wykrywa_wzorzec_ktory_wywolal_to_zgloszenie` to
 * kontrola dodatnia z drugiej strony: syntetyczny fragment odtwarzający
 * dokładnie ten wzorzec (JS i powłoka) ma zostać wykryty — inaczej test
 * mierzyłby pustkę.
 * `test_znaleziono_i_naprawiono_wszystkie_szesc_wystapien_z_1949` pilnuje,
 * że lista sześciu naprawionych plików faktycznie nie zawiera już starego
 * wzorca — to nie duplikuje testu głównego (który skanuje CAŁY katalog), tylko
 * przypina dowód dla konkretnego zgłoszenia.
 *
 * @bez-kontroli-dodatniej test ma własną wewnętrzną kontrolę dodatnią i ujemną
 * na syntetycznych fragmentach (patrz metody niżej), a mechanizm alfa08
 * wymaga bazy PostgreSQL dedykowanej stanowisku do mutacji CI/CD — poza
 * zakresem tego zgłoszenia dokumentacyjno-bezpieczeństwowego.
 */
class SkryptyNieLogujaWartosciZmiennychWrazliwychTest extends TestCase
{
    /** Nazwy zmiennych uznawane za wrażliwe — patrz treść zgłoszenia #1949. */
    private const WZORZEC_NAZWY = 'TOKEN|SECRET|KEY|PASSWORD|DSN';

    /**
     * JS: `console.log(...)`/`console.error(...)`/`console.warn(...)` z
     * `process.env.NAZWA` albo `env().NAZWA` w argumentach.
     *
     * Dopasowanie nazwy jest CELOWO wrażliwe na wielkość liter (same wielkie
     * litery) — zmienne środowiskowe w tym repozytorium są zawsze
     * `UPPER_SNAKE_CASE` (`DB_PASSWORD`, `RAILWAY_TOKEN`…). Bez tego
     * ograniczenia zwykłe słowo w treści zdania (np. „klucz”, „monkey”)
     * dawałoby fałszywe trafienia.
     */
    private function wzorzecJs(): string
    {
        return '/console\.(?:log|error|warn)\([^)]*(?:process\.env\.|env\(\)\.)(?:[A-Z_]*(?:'.self::WZORZEC_NAZWY.')[A-Z_]*)/';
    }

    /**
     * Powłoka: `echo`/`printf` z `$NAZWA` albo `${NAZWA}` w tym samym
     * poleceniu. Tak samo jak wyżej, dopasowanie nazwy jest wrażliwe na
     * wielkość liter — łapie prawdziwe zmienne środowiskowe
     * (`$DB_PASSWORD`, `${RAILWAY_TOKEN}`), a nie lokalne zmienne pomocnicze
     * pisane małymi literami (np. `$dsn` w `scripts/proba-odtworzenia.sh`,
     * który zwraca obliczony łańcuch przez `printf` — to jest mechanizm
     * „return" funkcji powłoki, nie logowanie, i sam skrypt sanityzuje hasło
     * przez `bez_hasla()`/`schowaj_haslo_z_dsn()`, zanim cokolwiek trafia do
     * `log`).
     */
    private function wzorzecPowloki(): string
    {
        return '/\b(?:echo|printf)\b[^\n]*\$\{?(?:[A-Z_]*(?:'.self::WZORZEC_NAZWY.')[A-Z_]*)\}?/';
    }

    /** @return list<string> */
    private function naruszenia(string $tresc, string $jezyk): array
    {
        $wzorzec = $jezyk === 'js' ? $this->wzorzecJs() : $this->wzorzecPowloki();

        preg_match_all($wzorzec, $tresc, $trafienia);

        return $trafienia[0] ?? [];
    }

    /** @return list<string> */
    private function plikiDoSkanowania(): array
    {
        $pliki = array_merge(
            glob(base_path('scripts/*.mjs')) ?: [],
            glob(base_path('scripts/*.sh')) ?: [],
            glob(base_path('.github/workflows/*.yml')) ?: [],
        );

        sort($pliki);

        return $pliki;
    }

    public function test_skrypty_i_workflowy_nie_wypisuja_wartosci_zmiennych_wrazliwych(): void
    {
        $pliki = $this->plikiDoSkanowania();

        $this->assertGreaterThan(
            50,
            count($pliki),
            'Znalazłem podejrzanie mało plików — skan czyta złe miejsce i niczego by nie zmierzył.',
        );

        $braki = [];

        foreach ($pliki as $sciezka) {
            $tresc = (string) file_get_contents($sciezka);
            $jezyk = str_ends_with($sciezka, '.yml') ? 'powloka' : (str_ends_with($sciezka, '.sh') ? 'powloka' : 'js');

            foreach ($this->naruszenia($tresc, $jezyk) as $trafienie) {
                $braki[] = $sciezka.': `'.trim($trafienie).'`';
            }
        }

        sort($braki);

        $this->assertSame([], $braki, implode("\n", [
            'Skrypt/workflow wypisuje w logu WARTOŚĆ zmiennej środowiskowej o nazwie',
            'wyglądającej na wrażliwą (TOKEN/SECRET/KEY/PASSWORD/DSN, D-… #1949).',
            'Loguj wyłącznie NAZWĘ zmiennej albo fakt, że jest ustawiona — nigdy wartość.',
            'Znalezione:',
        ]));
    }

    /**
     * KONTROLA DODATNIA. Syntetyczny fragment odtwarzający dokładnie wzorzec,
     * który wywołał alerty CodeQL i to zgłoszenie — ma zostać wykryty.
     */
    public function test_klasyfikator_wykrywa_wzorzec_ktory_wywolal_to_zgloszenie(): void
    {
        // `DB_DATABASE` (nazwa, która wywołała #1949) sama nie pasuje do listy
        // nazw wrażliwych (nie jest TOKEN/SECRET/KEY/PASSWORD/DSN) — naprawę
        // TAMTEGO konkretnego wzorca pilnuje osobno
        // `test_szesc_naprawionych_plikow_nie_ma_juz_starego_wzorca`. Ten skan
        // odtwarza tę samą KLASĘ błędu (interpolacja env() w console.log) dla
        // nazwy, która już jest wrażliwa wprost.
        $js = "  console.log(`Przygotowuję dane demonstracyjne w bazie \${env().DB_TOKEN}...`);\n";
        $this->assertNotSame([], $this->naruszenia($js, 'js'), 'Klasyfikator nie złapał wzorca env().NAZWA w console.log — test niczego nie mierzy.');

        $js2 = "console.log('token: ' + process.env.API_TOKEN);\n";
        $this->assertNotSame([], $this->naruszenia($js2, 'js'), 'Klasyfikator nie złapał process.env.API_TOKEN w console.log.');

        $sh = 'echo "RAILWAY_TOKEN=$RAILWAY_TOKEN"'."\n";
        $this->assertNotSame([], $this->naruszenia($sh, 'powloka'), 'Klasyfikator nie złapał wartości zmiennej w echo.');

        $sh2 = 'printf "%s" "${DB_PASSWORD}"'."\n";
        $this->assertNotSame([], $this->naruszenia($sh2, 'powloka'), 'Klasyfikator nie złapał wartości zmiennej w printf.');
    }

    /**
     * KONTROLA UJEMNA. Bezpieczne logowanie — samej NAZWY zmiennej albo faktu,
     * że jest ustawiona, bez jej wartości — ma PRZEJŚĆ. Bez tej kontroli
     * klasyfikator dopasowujący każdy `console.log`/`echo` z jakimkolwiek
     * słowem TOKEN/SECRET/KEY/PASSWORD/DSN w tekście byłby zawsze czerwony.
     */
    public function test_klasyfikator_nie_lapie_bezpiecznego_logowania(): void
    {
        $js = "console.log('Przygotowuję dane demonstracyjne w bazie z DB_DATABASE (nazwa ustawiona, wartość nie jest logowana)...');\n";
        $this->assertSame([], $this->naruszenia($js, 'js'), 'Bezpieczny log (bez interpolacji wartości) niesłusznie oznaczony jako naruszenie.');

        $js2 = "console.log('DB_HOST i DB_PORT są ustawione, DB_PASSWORD też.');\n";
        $this->assertSame([], $this->naruszenia($js2, 'js'), 'Wzmianka o nazwie zmiennej w zwykłym tekście niesłusznie złapana.');

        $sh = 'echo "RAILWAY_TOKEN jest ustawiony."'."\n";
        $this->assertSame([], $this->naruszenia($sh, 'powloka'), 'Zdanie o fakcie ustawienia zmiennej niesłusznie złapane w powłoce.');

        $sh2 = 'echo "Dodaj sekret RAILWAY_TOKEN_$(echo "$TARGET_ENV" | tr \'[:lower:]\' \'[:upper:]\')."'."\n";
        $this->assertSame([], $this->naruszenia($sh2, 'powloka'), 'Podpowiedź nazwy sekretu (bez wypisania jego wartości) niesłusznie złapana.');
    }

    /**
     * Dowód dla konkretnego zgłoszenia #1949: sześć plików, w których wzorzec
     * `env().DB_DATABASE` w `console.log` naprawdę wystąpił, już go nie mają.
     */
    public function test_szesc_naprawionych_plikow_nie_ma_juz_starego_wzorca(): void
    {
        $naprawione = [
            'scripts/bez-javascriptu.mjs',
            'scripts/fokus-karty-dania.mjs',
            'scripts/glowka-karty-wpisu.mjs',
            'scripts/hero-nad-zgieciem.mjs',
            'scripts/kafel-dodawania.mjs',
            'scripts/port-projektu.mjs',
        ];

        foreach ($naprawione as $sciezka) {
            $pelna = base_path($sciezka);
            $this->assertFileExists($pelna, "Plik z pierwotnego wzorca zniknął: {$sciezka}");

            $tresc = (string) file_get_contents($pelna);

            $this->assertDoesNotMatchRegularExpression(
                '/console\.log\(`[^`]*\$\{env\(\)\.DB_DATABASE\}[^`]*`\)/',
                $tresc,
                "{$sciezka} nadal interpoluje env().DB_DATABASE w szablonie łańcuchowym logowanym do konsoli.",
            );

            $this->assertSame(
                [],
                $this->naruszenia($tresc, 'js'),
                "{$sciezka} nadal ma wzorzec wypisywania wartości zmiennej wrażliwej.",
            );
        }
    }
}
