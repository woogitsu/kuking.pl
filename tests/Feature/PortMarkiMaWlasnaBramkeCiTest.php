<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PortMarkiMaWlasnaBramkeCiTest extends TestCase
{
    public function test_pomiary_nie_pobieraja_historii_ktora_czyta_tylko_zakres(): void
    {
        foreach (['port_marki', 'port_funkcje', 'dostepnosc'] as $name) {
            $job = $this->job($name);
            // Kontrola dodatnia: brak całego checkoutu nie jest oszczędnością.
            $this->assertSame(1, preg_match('/uses: actions\/checkout@[^\s]+(?<opcje>.*?)(?=^      - |\z)/ms', $job, $checkout), $name.': brak checkoutu.');
            $this->assertDoesNotMatchRegularExpression('/fetch-depth:\s*0\b/', $checkout['opcje'],
                $name.': pełną historię pobiera już zakres; pomiar potrzebuje drzewa i indeksu.');
            $this->assertStringContainsString('needs: zakres', $job);
            $this->assertStringContainsString('needs.zakres.outputs.widok', $job);
        }
    }

    public function test_zakres_zachowuje_historie_do_porownania_z_baza(): void
    {
        $this->assertStringContainsString('fetch-depth: 0', $this->job('zakres'));
        $this->assertStringContainsString('git diff --name-only "${BAZA}" HEAD', $this->skrypt());
    }

    /**
     * Skrypt bramki `zakres` bez komentarzy. Od #611 (etap 5) to osobny plik,
     * a krok w `ci.yml` tylko go woła — strażnik czyta więc plik, ale najpierw
     * sprawdza, że CI woła właśnie ten plik (inaczej zielony test nic nie znaczy).
     */
    private function skrypt(): string
    {
        $this->assertStringContainsString('run: bash scripts/ci/zakres.sh', $this->job('zakres'),
            'Krok bramki w `ci.yml` nie woła scripts/ci/zakres.sh.');

        $tresc = (string) file_get_contents(base_path('scripts/ci/zakres.sh'));
        $this->assertNotSame('', $tresc, 'Brak albo pusty scripts/ci/zakres.sh.');

        return (string) preg_replace('/^\s*#.*$/m', '', $tresc);
    }

    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    }

    private function job(string $name): string
    {
        $matched = preg_match('/^  '.preg_quote($name, '/').':(?:\r\n|\n|\r)(.*?)(?=^  [a-z_]+:|\z)/ms', $this->workflow(), $matches);
        $this->assertSame(1, $matched, 'Brak sprawdzanego joba CI: '.$name);

        return (string) preg_replace('/^\s*#.*$/m', '', $matches[1]);
    }

    public function test_obie_grupy_sa_obowiazkowe_a_kreator_ma_przygotowana_baze(): void
    {
        $port = 'run: node scripts/port-projektu.mjs';
        $kroki = 'run: node scripts/kroki-kreatora.mjs';
        $this->assertSame(2, substr_count($this->workflow(), $port));
        $this->assertSame(1, substr_count($this->workflow(), $kroki));
        foreach (['port_marki' => 'baza', 'port_funkcje' => 'rozszerzenia-${{ matrix.czesc }}'] as $name => $group) {
            $job = $this->job($name);
            $this->assertStringContainsString($port, $job);
            $this->assertStringContainsString('run: node --test scripts/port-grupy.test.mjs', $job);
            $this->assertStringContainsString('PORT_GRUPA: '.$group, $job);
            $this->assertStringContainsString('timeout-minutes: 25', $job);
            $this->assertStringContainsString("if: needs.zakres.outputs.kod == 'true'", $job);
            $this->assertStringNotContainsString('continue-on-error:', $job);
            $this->assertStringContainsString('job.services.postgres.ports[5432]', $job);
            $this->assertStringContainsString('storage/port-projektu', $job);
            $this->assertStringContainsString('uses: actions/checkout@', $job);
        }
        $job = $this->job('port_funkcje');
        $this->assertStringContainsString($kroki, $job);
        $this->assertLessThan(strpos($job, $kroki), strpos($job, $port), 'Kreator wymaga bazy przygotowanej przez port.');
        $this->assertStringContainsString('storage/kroki-kreatora', $job);
        $this->assertStringNotContainsString($kroki, $this->job('port_marki'));
        $other = $this->job('dostepnosc');
        foreach (['dostepnosc', 'wydajnosc', 'fokus-karty-dania', 'kafel-dodawania', 'service-worker-aktualizacja'] as $script) {
            $this->assertStringContainsString('run: node scripts/'.$script.'.mjs', $other);
        }
    }

    /**
     * #611, etap 9: `port_funkcje` szedł 25 minut w jednym kawałku, więc jest
     * macierzą dwóch części. Wzór „Podział testów gubi plik": podział nie może
     * zgubić ani zdublować pomiaru.
     *
     *  1. części macierzy = części grupy `rozszerzenia-N` z `scripts/port-grupy.mjs`
     *     (dopisanie części w jednym miejscu bez drugiego zostawiłoby pomiar
     *     nigdzie niewykonany);
     *  2. `port-projektu.mjs` idzie w KAŻDEJ części, bez warunku — to on
     *     wybiera grupę przez `PORT_GRUPA`;
     *  3. każdy krok dodatkowy (kreator, autozapis, strona nieaktualna, minutnik)
     *     stoi w dokładnie jednym kroku, z warunkiem na właściwą część;
     *  4. żaden warunek `matrix.czesc == N` nie wskazuje części spoza macierzy.
     */
    public function test_rozszerzenia_dziela_sie_na_czesci_bez_utraty_pomiaru(): void
    {
        $job = $this->job('port_funkcje');

        $this->assertSame(1, preg_match('/^        czesc: \[([\d, ]+)\]$/m', $job, $macierz), 'Brak macierzy `czesc` w port_funkcje.');
        $czesci = array_map('intval', array_map('trim', explode(',', $macierz[1])));
        $this->assertGreaterThanOrEqual(2, count($czesci), 'Macierz nie dzieli niczego.');
        $this->assertSame($czesci, array_values(array_unique($czesci)), 'Macierz powtarza część.');

        $grupy = (string) file_get_contents(base_path('scripts/port-grupy.mjs'));
        $this->assertSame(1, preg_match("/GRUPY = \[([^\]]+)\]/", $grupy, $lista), 'scripts/port-grupy.mjs nie ma listy GRUPY.');
        preg_match_all("/'(rozszerzenia-\d+)'/", $lista[1], $wGrupach);
        $this->assertSame(
            array_map(static fn (int $c): string => 'rozszerzenia-'.$c, $czesci),
            $wGrupach[1],
            'Części macierzy `port_funkcje` rozjechały się z GRUPY w scripts/port-grupy.mjs — jakaś część pomiaru nie idzie nigdzie.',
        );

        $kroki = preg_split('/^      - /m', $job) ?: [];
        $krokZ = static function (string $polecenie) use ($kroki): array {
            return array_values(array_filter($kroki, static fn (string $k): bool => str_contains($k, $polecenie)));
        };

        $port = $krokZ('run: node scripts/port-projektu.mjs');
        $this->assertCount(1, $port);
        $this->assertStringNotContainsString('if:', $port[0], 'Pomiar portu pominięty w którejś części macierzy.');

        foreach ([
            'run: node scripts/kroki-kreatora.mjs' => 1,
            'node scripts/kreator-zachowanie.mjs autosave' => 1,
            'node scripts/kreator-zachowanie.mjs published' => 1,
            'run: node --test scripts/przegladarka/strona-nieaktualna.test.mjs' => 2,
            'node scripts/minutnik-regresja.mjs' => 2,
            'node scripts/minutnik-fokus.mjs' => 2,
        ] as $polecenie => $czesc) {
            $krok = $krokZ($polecenie);
            $this->assertCount(1, $krok, 'Krok `'.$polecenie.'` musi stać w port_funkcje dokładnie raz.');
            $this->assertStringContainsString('if: matrix.czesc == '.$czesc."\n", $krok[0], '`'.$polecenie.'` nie idzie w części '.$czesc.' albo idzie w obu.');
            $this->assertContains($czesc, $czesci, 'Część '.$czesc.' nie istnieje w macierzy — `'.$polecenie.'` nie uruchomi się nigdzie.');
        }

        preg_match_all('/if: matrix\.czesc == (\d+)/', $job, $warunki);
        foreach ($warunki[1] as $numer) {
            $this->assertContains((int) $numer, $czesci, 'Warunek wskazuje część spoza macierzy: '.$numer);
        }
    }

    /**
     * STRAŻNIK MARTWYCH REGUŁ CSS MA KTO URUCHOMIĆ — I JEST NIEBLOKUJĄCY (D-223, #960).
     *
     * `scripts/kaskada-martwe-reguly.mjs` powstał 20.09.2026 i przez dobę nie
     * wołał go NIKT — ani `ci.yml`, ani `scripts/check.sh`. Strażnik, którego
     * nic nie uruchamia, jest dokumentacją zamiaru, a nie bramką. Decyzja
     * właściciela z 29.09.2026: wpiąć, NAJPIERW NIEBLOKUJĄCO, na tydzień.
     *
     * Ten test pilnuje rzeczy, z których każda z osobna daje zieleń bez pomiaru:
     *
     * 1. WYWOŁANIE ISTNIEJE, dokładnie raz, we własnym jobie `kaskada`, który ma
     *    `continue-on-error: true` i jawny komentarz z terminem i numerem
     *    zgłoszenia — nieblokujący bez terminu zostałby taki na zawsze.
     * 2. JOB STOI NA BRAMCE ZAKRESU (`kod` i `widok`); filtr `scripts/ci/zakres.sh`
     *    zna skrypty strażnika sprawdza drugi test tego pliku.
     * 3. STRAŻNIK STOI PO Chromium i PO `migrate:fresh --seed`. Przed nimi padłby
     *    na przyrządzie, nie na CSS-ie, i pierwsza czerwień nauczyłaby czytelnika,
     *    że ta bramka „zawsze się sypie".
     * 4. IDZIE PRZEZ `scripts/kaskada-kontrola-polecenie.sh`, nie przez gołe
     *    `node …mjs` z własnymi flagami: zawężenie `--tylko` ma JEDNO miejsce,
     *    wspólne z kontrolą ujemną. Inaczej bramka i jej dowód mierzyłyby dwa
     *    różne zakresy.
     * 5. NIE MA GO w `npm run build` ani w `Dockerfile`: obraz nie ma ani
     *    przeglądarki, ani bazy.
     * 6. W `scripts/check.sh` krok jest NIEBLOKUJĄCY (ostrzeżenie, nie `zle`),
     *    na bazie z rodziny testowej — tej samej co krok axe.
     *
     * Termin zdjęcia flagi: 06.10.2026. Gdy job stanie się blokujący, ten test
     * trzeba zmienić RAZEM z nim — celowo: zdjęcie flagi bez świadomej zmiany
     * testu ma zapalić czerwień, a nie przejść po cichu.
     */
    public function test_straznik_martwych_regul_css_ma_kto_uruchomic_i_jest_nieblokujacy(): void
    {
        $wywolanie = 'run: bash scripts/kaskada-kontrola-polecenie.sh';

        $this->assertSame(1, substr_count($this->workflow(), $wywolanie),
            'Strażnik martwych reguł CSS nie jest wołany dokładnie raz w `ci.yml`.');

        $job = $this->job('kaskada');
        $this->assertStringContainsString($wywolanie, $job,
            'Strażnik kaskady stoi poza jobem `kaskada`.');
        $this->assertStringContainsString("if: needs.zakres.outputs.kod == 'true' && needs.zakres.outputs.widok == 'true'", $job);
        // Flaga NA POZIOMIE JOBA (cztery spacje), nie na kroku zapisu artefaktu —
        // ten ma własne `continue-on-error` i samo `assertStringContainsString`
        // przechodziło po zdjęciu flagi z joba (zmierzone kontrolą ujemną).
        $this->assertSame(1, preg_match('/^    continue-on-error: true\s*$/m', $job),
            'Job `kaskada` jest blokujący — decyzja właściciela z 29.09.2026 to najpierw tydzień bez blokowania.');
        $this->assertStringContainsString('(nie blokuje do 06.10.2026)', $job);
        $this->assertStringContainsString('job.services.postgres.ports[5432]', $job);
        $this->assertStringContainsString('uses: actions/checkout@', $job);

        // Komentarz z terminem — czytany z surowego workflow, bo `job()` wycina komentarze.
        $this->assertStringContainsString('nieblokujący do 06.10.2026, potem blokujący — #960', $this->workflow(),
            'Brak jawnego komentarza z terminem zdjęcia flagi `continue-on-error`.');

        // Osobny job, żeby flaga nie zjadła axe i Lighthouse'a — te są blokujące.
        $this->assertStringNotContainsString($wywolanie, $this->job('dostepnosc'));
        $this->assertStringNotContainsString('continue-on-error: true', $this->job('dostepnosc'));

        $przegladarka = strpos($job, 'npx playwright install chromium');
        $baza = strpos($job, 'migrate:fresh --seed');
        $straznik = strpos($job, $wywolanie);
        $this->assertIsInt($przegladarka);
        $this->assertIsInt($baza);
        $this->assertLessThan($straznik, $przegladarka,
            'Strażnik kaskady stoi PRZED instalacją Chromium — padłby na przyrządzie, nie na CSS-ie.');
        $this->assertLessThan($straznik, $baza,
            'Strażnik kaskady stoi PRZED zasianiem bazy — `/przepisy/rosol-babci-zofii` nie istniałoby.');

        // Kontrola ujemna dla tego testu: samo `node scripts/kaskada-martwe-reguly.mjs`
        // w `ci.yml` obeszłoby wspólne zawężenie i rozjechało bramkę z dowodem.
        $this->assertStringNotContainsString('node scripts/kaskada-martwe-reguly.mjs', $this->workflow(),
            'Bramka woła strażnika z pominięciem `kaskada-kontrola-polecenie.sh` — zawężenie `--tylko` ma jedno miejsce.');

        foreach (['package.json', 'Dockerfile'] as $plik) {
            $this->assertStringNotContainsString('kaskada-martwe-reguly', (string) file_get_contents(base_path($plik)),
                $plik.': strażnik potrzebuje przeglądarki i bazy, których tam nie ma.');
        }

        // `scripts/check.sh`: krok nieblokujący, baza z rodziny testowej.
        $check = (string) preg_replace('/^\s*#.*$/m', '', (string) file_get_contents(base_path('scripts/check.sh')));
        $this->assertSame(1, substr_count($check, 'bash scripts/kaskada-kontrola-polecenie.sh >'),
            '`scripts/check.sh` nie woła strażnika kaskady dokładnie raz.');
        $this->assertStringContainsString('DB_DATABASE=kuking_test_a11y bash scripts/kaskada-kontrola-polecenie.sh', $check,
            'Krok kaskady w `check.sh` ma czytać bazę z rodziny testowej, tę samą co krok axe.');
        $this->assertSame(1, preg_match('/^krok "Martwe reguły CSS.*?(?=^krok "|\z)/ms', $check, $krok),
            'Nie znaleziono kroku kaskady w `check.sh`.');
        $this->assertStringNotContainsString('zle', $krok[0],
            'Krok kaskady w `check.sh` woła `zle` — to podbija licznik błędów i kończy skrypt kodem 1, a do 06.10.2026 ma tylko ostrzegać.');
    }

    /**
     * Regresja #892 (plakietka autozapisu kreatora) ma test tylko w przeglądarce.
     *
     * Do 24 września 2026 `scripts/kreator-zachowanie.mjs` istniał, ale nic go
     * nie uruchamiało — kontrola ujemna z `docs/design/dowody-kreatora/892.json`
     * była jednorazowym pomiarem, a nie bramką. Skrypt tworzy konto i szkice,
     * więc stoi w `port_funkcje` PO `port-projektu.mjs`, na tej samej bazie
     * pomiarowej co `kroki-kreatora.mjs`. Kontrola dodatnia:
     * `scripts/kontrole-negatywne-alfa08.py`.
     */
    public function test_autozapis_kreatora_892_chodzi_w_ci(): void
    {
        $job = $this->job('port_funkcje');
        $port = 'run: node scripts/port-projektu.mjs';

        foreach (['autosave', 'published'] as $tryb) {
            $krok = 'node scripts/kreator-zachowanie.mjs '.$tryb;
            $this->assertSame(1, substr_count($this->workflow(), $krok), 'Autozapis #892 nie chodzi w CI: '.$tryb);
            $this->assertStringContainsString($krok, $job);
            $this->assertLessThan(strpos($job, $krok), strpos($job, $port), 'Kreator wymaga bazy przygotowanej przez port.');
        }
        $this->assertMatchesRegularExpression('/DB_DATABASE: kuking_port_pomiar\s+run: \|\s+node scripts\/kreator-zachowanie\.mjs autosave/', $job);

        $this->assertSame(1, preg_match("/grep -qE '([^']+)'/", $this->skrypt(), $matches));
        $this->assertSame(1, preg_match('~'.str_replace('~', '\\~', $matches[1]).'~', 'scripts/kreator-zachowanie.mjs'),
            'zakres: zmiana samego przyrządu #892 nie uruchamia pomiaru.');
    }

    /**
     * #492 (decyzja właściciela z 29.09.2026, D-333): cztery pomiary #713
     * chodzą w `port_marki`, każdy dokładnie raz w całym CI.
     *
     * Do tej decyzji `pasek-uklady`, `lead-wstep`, `eksport-bloki-692`
     * i `turnstile-csp` istniały w repozytorium, ale uruchamiał je tylko
     * człowiek — jak `kreator-zachowanie.mjs` przed #892. Skrypty nie sieją
     * własnej bazy, więc stoją PO `port-projektu.mjs` i na tej samej bazie
     * pomiarowej; zmiana samego skryptu albo jego wspólnego szkieletu
     * (`scripts/lib/serwer-lokalny.mjs`) musi uruchomić job.
     */
    public function test_cztery_pomiary_713_chodza_w_porcie_marki(): void
    {
        $job = $this->job('port_marki');
        $port = 'run: node scripts/port-projektu.mjs';
        $zakres = $this->skrypt();
        $this->assertSame(1, preg_match("/grep -qE '([^']+)'/", $zakres, $matches));
        $wzorzec = '~'.str_replace('~', '\\~', $matches[1]).'~';

        foreach (['pasek-uklady', 'lead-wstep', 'eksport-bloki-692', 'turnstile-csp'] as $skrypt) {
            $krok = 'run: node scripts/'.$skrypt.'.mjs';
            $this->assertSame(1, substr_count($this->workflow(), $krok), 'Pomiar #713 nie chodzi w CI albo chodzi dwa razy: '.$skrypt);
            $this->assertStringContainsString($krok, $job, 'Pomiar #713 poza jobem `port_marki`: '.$skrypt);
            $this->assertLessThan(strpos($job, $krok), strpos($job, $port), $skrypt.' wymaga bazy przygotowanej przez port.');
            $this->assertMatchesRegularExpression('/DB_DATABASE: kuking_port_pomiar\s+'.preg_quote($krok, '/').'/', $job,
                $skrypt.': skrypt odmawia bazy `kuking_test`, więc musi dostać bazę pomiarową portu.');
            $this->assertSame(1, preg_match($wzorzec, 'scripts/'.$skrypt.'.mjs'), 'zakres: zmiana samego '.$skrypt.' nie uruchamia pomiaru.');
        }

        $this->assertSame(1, preg_match($wzorzec, 'scripts/lib/serwer-lokalny.mjs'), 'zakres: zmiana szkieletu pomiarów #713 nie uruchamia pomiaru.');
        $this->assertStringContainsString('storage/pasek-uklady', $job);
        $this->assertStringContainsString('storage/eksport-692', $job);
    }

    /**
     * Filtr warstwy widoku stoi w JEDNYM miejscu i obejmuje sam przyrząd.
     *
     * Do 19 września 2026 ten sam filtr był skopiowany trzy razy — osobno
     * w `port_marki`, `port_funkcje` i `dostepnosc`. Trzy kopie 589-znakowego
     * wyrażenia z ręcznie utrzymywaną listą nazw skryptów to trzy miejsca,
     * w których lista mogła się rozjechać, i trzy do zaktualizowania przy
     * każdym nowym skrypcie pomiarowym.
     *
     * Gwarancja się nie zmienia: zmiana SAMEGO PRZYRZĄDU musi uruchomić
     * pomiar. Inaczej dałoby się zepsuć miernik i nie zobaczyć ani jednego
     * czerwonego przebiegu. Zmienia się tylko to, że pilnujemy jej w jednym
     * miejscu zamiast w trzech.
     */
    public function test_zmiana_samego_przyrzadu_nie_pomija_pomiarow(): void
    {
        $zakres = $this->skrypt();

        // NAJPIERW liczba kopii, dopiero potem treść. Przy dwóch filtrach
        // `preg_match` bierze pierwszy z brzegu i test oblewałby na treści
        // wzorca — czyli z komunikatem o brakującym skrypcie zamiast o tym,
        // co się naprawdę stało. Czerwień ma mówić prawdę o przyczynie.
        // Filtr stoi w skrypcie bramki, a w `ci.yml` nie ma go wcale.
        $this->assertSame(1, preg_match_all("/grep -qE '/", (string) file_get_contents(base_path('scripts/ci/zakres.sh'))),
            'Filtr warstwy widoku jest w więcej niż jednym miejscu — znowu są kopie do utrzymania.');
        $this->assertSame(0, preg_match_all("/grep -qE '/", $this->workflow()),
            'Filtr warstwy widoku wrócił do `ci.yml` — druga kopia obok scripts/ci/zakres.sh.');

        $this->assertSame(1, preg_match("/grep -qE '([^']+)'/", $zakres, $matches),
            'W jobie `zakres` nie ma filtra warstwy widoku.');

        $pattern = '~'.str_replace('~', '\\~', $matches[1]).'~';

        foreach (['scripts/referrer-sekret-browser.mjs', 'app/Http/Middleware/ApplySecurityHeaders.php', 'app/Support/AnalitykaCloudflare.php'] as $path) {
            $this->assertSame(1, preg_match($pattern, $path), 'Pomiar referrera pominięty: '.$path);
        }
        $referrerJob = $this->job('dostepnosc');
        $this->assertStringContainsString('node scripts/referrer-sekret-browser.mjs', $referrerJob);
        $this->assertStringContainsString('DB_DATABASE: kuking_port_referrer', $referrerJob);
        $this->assertStringNotContainsString('continue-on-error:', $referrerJob);

        foreach (['scripts/port-grupy.mjs', 'scripts/port-grupy.test.mjs', 'scripts/nawigacja-etykiety.mjs', 'scripts/nawigacja-zoom.mjs', 'scripts/nawigacja-negatywy.mjs', 'scripts/szybki-wyglad.mjs', 'scripts/pasek-przewijany.mjs', 'scripts/zwarte-kolumny.mjs', 'scripts/katalog-tagow.mjs', 'scripts/zainteresowania-powiadomienia-marki.mjs', 'scripts/fixtures/kompozycje-513.php', 'resources/css/marka-onboarding.css', 'scripts/lib/stan-ustalony.mjs', 'scripts/kaskada-martwe-reguly.mjs', 'scripts/kaskada-kontrola-polecenie.sh', 'scripts/kaskada-kontrola-ujemna.sh'] as $path) {
            $this->assertSame(1, preg_match($pattern, $path), 'zakres: pominięto '.$path);
        }

        // Zmiana samego `ci.yml` też uruchamia pomiar — inaczej dałoby się
        // przestawić bramkę bez ani jednego przebiegu, który by to pokazał.
        $this->assertSame(1, preg_match($pattern, '.github/workflows/ci.yml'),
            'zakres: zmiana samej bramki CI nie uruchamia pomiaru.');

        // Kontrola ujemna: filtr ma ROZRÓŻNIAĆ, a nie przepuszczać wszystko.
        $this->assertSame(0, preg_match($pattern, 'docs/PRODUCT.md'));
    }

    public static function screenPaths(): array
    {
        return [
            'kreator' => ['app/Livewire/RecipeWizard.php', true],
            'komponent' => ['app/View/Components/Layout.php', true],
            'kontroler' => ['app/Http/Controllers/RecipeController.php', true],
            'kontroler techniczny bez wyjątków' => ['app/Http/Controllers/HealthController.php', true],
            'middleware' => ['app/Http/Middleware/EnsureAccountIsActive.php', true],
            'model' => ['app/Models/Recipe.php', true],
            'polityka' => ['app/Policies/RecipePolicy.php', true],
            'domena' => ['app/Domain/Search/SearchQuery.php', true],
            'trasy' => ['routes/web.php', true],
            'konfiguracja' => ['config/kuking.php', true],
            'start aplikacji' => ['bootstrap/app.php', true],
            'tłumaczenia' => ['lang/pl/validation.php', true],
            'dane ekranów' => ['database/seeders/DemoSeeder.php', true],
            'zależności' => ['composer.json', true],
            'wersje zależności' => ['composer.lock', true],
            'dokumentacja' => ['docs/PRODUCT.md', false],
            'instrukcja główna' => ['README.md', false],
            'ścieżka podobna do zależności' => ['composer.json.md', false],
            'dokumentacja i PHP' => ["docs/PRODUCT.md\napp/Livewire/RecipeWizard.php", true],
            'duży diff bez SIGPIPE' => ["app/Livewire/RecipeWizard.php\n".str_repeat("docs/dlugi-niezmieniajacy-interfejsu-opis.md\n", 10000), true],
        ];
    }

    /** Uruchamia rzeczywisty warunek w Bashu, nie tłumaczenie regexu na PCRE. */
    #[DataProvider('screenPaths')]
    public function test_php_sterujace_ekranem_uruchamia_pomiar(string $paths, bool $expected): void
    {
        // Filtr zawęża WYŁĄCZNIE na PR-ach — tu sprawdzamy właśnie tę gałąź.
        $this->assertSame('widok='.($expected ? 'true' : 'false')."\n", $this->filtrWidoku($paths, 'pull_request'),
            'zakres: błędna decyzja dla '.strtok($paths, "\n"));
    }

    /**
     * POZA PR-EM FILTR WIDOKU NIE ZAWĘŻA (decyzja właściciela 24.09.2026).
     *
     * Push na `main`/`staging` i uruchomienie ręczne mierzą pełny zestaw.
     * Brak `ZDARZENIE` (np. ktoś usunie zmienną z kroku) też ma dać pełny
     * zestaw — pomyłka w konfiguracji ma kosztować minuty, nie pomiar.
     * Kontrola ujemna to `docs/PRODUCT.md` na PR-ze w teście wyżej (`false`).
     */
    public function test_poza_pull_requestem_filtr_widoku_nie_zaweza(): void
    {
        foreach (['push', 'workflow_dispatch', null] as $zdarzenie) {
            $this->assertSame("widok=true\n", $this->filtrWidoku('docs/PRODUCT.md', $zdarzenie),
                'zakres: filtr widoku zawęża poza PR-em (zdarzenie: '.($zdarzenie ?? 'brak').').');
        }
    }

    /** Uruchamia rzeczywisty blok filtra widoku z `ci.yml` w Bashu. */
    private function filtrWidoku(string $paths, ?string $zdarzenie): string
    {
        $this->skrypt();
        $output = tempnam(sys_get_temp_dir(), 'kuking-zakres-');
        $lista = tempnam(sys_get_temp_dir(), 'kuking-zakres-lista-');
        $this->assertNotFalse($output);
        $this->assertNotFalse($lista);

        try {
            // Lista z pliku (tryb testowy skryptu): duży diff nie mieści się
            // w jednej zmiennej środowiska.
            file_put_contents($lista, $paths."\n");
            $process = new Process(['bash', 'scripts/ci/zakres.sh'], base_path(), [
                'GITHUB_OUTPUT' => $output,
                'ZAKRES_LISTA_PLIK' => $lista,
                // `false` usuwa zmienną odziedziczoną ze środowiska.
                'ZDARZENIE' => $zdarzenie ?? false,
            ]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            // Cały skrypt wystawia sześć wyjść; ten test pyta o `widok`.
            preg_match('/^widok=.*$/m', (string) file_get_contents($output), $widok);
            $this->assertNotSame([], $widok, 'Skrypt bramki nie wystawił `widok`.');

            return $widok[0]."\n";
        } finally {
            unlink($output);
            unlink($lista);
        }
    }

    /**
     * NIE DA SIĘ PRZEJŚĆ JOBA PRZEGLĄDARKOWEGO, NIE URUCHAMIAJĄC POMIARU.
     *
     * To jest najgroźniejsza cecha, jaką ten plik miał. Warunek pomijania
     * stał na KROKACH (`if: steps.zmiany.outputs.warto == '1'`), a nie na
     * jobie. Przy zmianie kodu poza warstwą widoku job się URUCHAMIAŁ,
     * wszystkie kroki pomiarowe były pomijane, a job kończył się na ZIELONO.
     * Na liście kontrolnej PR-a nie dało się tego odróżnić od joba, który
     * naprawdę wszystko zmierzył.
     *
     * Zmierzone na 25 przebiegach CI przed zmianą:
     *   „Port marki (kompozycje…)"      15 sukcesów, 4 trwały 19–22 s
     *                                   (przy pomiarze: 462–506 s)
     *   „Port marki — rodziny ekranów"  13 sukcesów, 4 trwały 19–21 s
     *                                   (przy pomiarze: 1456–1638 s)
     *   „Dostępność (axe-core)…"        13 sukcesów, 4 trwały 18–22 s
     *                                   (przy pomiarze: 745–955 s)
     * Razem dwanaście zielonych wyników bez ani jednego pomiaru.
     *
     * Teraz warunek stoi na JOBIE: przy nietkniętej warstwie widoku cały job
     * jest `skipped`, czyli widać, że nic nie zmierzono. Ten test pilnuje,
     * żeby warunek nie wrócił na kroki.
     */
    public function test_job_przegladarkowy_nie_moze_byc_zielony_bez_pomiaru(): void
    {
        foreach (['port_marki', 'port_funkcje', 'dostepnosc'] as $name) {
            $job = $this->job($name);

            // 1. Warunek warstwy widoku stoi na JOBIE…
            $this->assertMatchesRegularExpression(
                "/^    if: .*needs\.zakres\.outputs\.widok == 'true'/m",
                $job,
                $name.': warunek warstwy widoku nie stoi na jobie.',
            );

            // 2. …i nadal obowiązuje filtr „to nie jest sama dokumentacja".
            $this->assertMatchesRegularExpression(
                "/^    if: .*needs\.zakres\.outputs\.kod == 'true'/m",
                $job,
                $name.': zniknął warunek o zmianie kodu.',
            );

            // 3. Żaden KROK nie może już decydować o pominięciu pomiaru.
            //    Jedyny dopuszczony warunek na kroku stoi przy wysyłce
            //    dowodów: `always()` albo `failure()` — oba wykonują krok po
            //    czerwieni. `failure()` od 23.09: przy wyczerpanym limicie
            //    miejsca na artefakty wysyłka po zielonym jobie czerwieniła
            //    CI, a dowody zielonego przebiegu nikomu nie są potrzebne.
            preg_match_all('/^        if: (.+)$/m', $job, $warunki);
            // #2299: warunek runnera wolno postawić wyłącznie na kroku cache
            // przeglądarki (tyle warunków, ile takich kroków) — pomiar nie
            // może od niego zależeć. Reguły cache: `CacheMiedzyJobamiNieDajeStarychWynikowTest`.
            $this->assertSame(
                substr_count($job, 'path: ~/.cache/ms-playwright'),
                substr_count($job, "        if: runner.environment == 'github-hosted'"),
                $name.': warunek runnera stoi na kroku innym niż cache przeglądarki — pomiar mógłby nie ruszyć na własnym runnerze.',
            );
            foreach ($warunki[1] as $warunek) {
                if (trim($warunek) === "runner.environment == 'github-hosted'") {
                    continue;
                }
                // Jedyny dodatkowy warunek: część macierzy `port_funkcje` (#611,
                // etap 9). Że każda część ma swoje kroki, a numer istnieje
                // w macierzy, pilnuje `test_rozszerzenia_dziela_sie_na_czesci_bez_utraty_pomiaru`.
                if ($name === 'port_funkcje' && preg_match('/^matrix\.czesc == \d+$/', trim($warunek)) === 1) {
                    continue;
                }

                $this->assertStringNotContainsString('warto', $warunek,
                    $name.': warunek pomijania wrócił na krok — job znowu może być zielony bez pomiaru.');
                $this->assertStringNotContainsString('steps.zmiany', $warunek,
                    $name.': krok znowu czyta własny filtr zamiast wyjścia joba `zakres`.');
                $this->assertMatchesRegularExpression('/^(always|failure)\(\)$/', trim($warunek),
                    $name.': krok ma warunek inny niż `always()` albo `failure()` — pomiar może zostać pominięty przy zielonym jobie.');
            }
        }
    }

    /**
     * `zakres` wystawia `widok` NA KAŻDEJ ścieżce wyjścia.
     *
     * To jest zabezpieczenie przed usterką GROŹNIEJSZĄ niż ta, którą
     * naprawiamy. Trzy joby przeglądarkowe ruszają dziś pod warunkiem
     * `needs.zakres.outputs.widok == 'true'`. Gdyby którakolwiek ścieżka
     * skryptu skończyła się bez zapisania tego wyjścia, porównanie z pustym
     * napisem byłoby FAŁSZEM — i wszystkie trzy joby pomijałyby się
     * ZAWSZE, po cichu, bez ani jednego czerwonego przebiegu.
     *
     * Skrypt ma dwie wczesne ścieżki (`exit 0`) na wypadek braku punktu
     * odniesienia i nieosiągalnej bazy. Obie muszą zapisać oba wyjścia.
     */
    public function test_zakres_wystawia_oba_wyjscia_na_kazdej_sciezce(): void
    {
        $zakres = $this->skrypt();

        // Wyjścia zadeklarowane na jobie — bez tego `needs…` jest puste.
        $this->assertMatchesRegularExpression(
            '/outputs:\s*\n\s+kod:.*\n\s+widok:/',
            $this->job('zakres'),
            'Job `zakres` nie wystawia obu wyjść.',
        );

        $linie = preg_split('/\r\n|\n|\r/', $zakres) ?: [];

        $wczesne = 0;
        foreach ($linie as $i => $linia) {
            if (preg_match('/^\s*exit 0\s*$/', $linia) !== 1) {
                continue;
            }

            $wczesne++;
            $okno = implode("\n", array_slice($linie, max(0, $i - 4), 5));

            $this->assertStringContainsString('kod=', $okno,
                'Ścieżka `exit 0` w linii '.($i + 1).' nie zapisuje `kod`.');
            $this->assertStringContainsString('widok=', $okno,
                'Ścieżka `exit 0` w linii '.($i + 1).' nie zapisuje `widok` — trzy joby '
                .'przeglądarkowe pomijałyby się wtedy ZAWSZE i po cichu.');
        }

        // Kontrola dodatnia: gdyby ktoś usunął wczesne wyjścia, pętla wyżej
        // nie sprawdziłaby niczego, a test byłby zielony (pułapka 2).
        $this->assertSame(2, $wczesne,
            'Zmieniła się liczba wczesnych wyjść ze skryptu `zakres` — przeczytaj je na nowo.');

        // Ścieżka końcowa (bez `exit`) też musi zapisać oba wyjścia.
        $this->assertSame(4, substr_count($zakres, 'echo "kod='));
        $this->assertSame(4, substr_count($zakres, 'echo "widok='));
    }

    /**
     * Kontrola dodatnia do testu wyżej (pułapka 4).
     *
     * Asercje „warunku nie ma" przeszłyby także wtedy, gdyby z jobów zniknęły
     * WSZYSTKIE kroki. Tu mierzymy, że pomiar w nich nadal stoi.
     */
    public function test_joby_przegladarkowe_nadal_uruchamiaja_pomiar(): void
    {
        foreach ([
            'port_marki' => 'run: node scripts/port-projektu.mjs',
            'port_funkcje' => 'run: node scripts/kroki-kreatora.mjs',
            'dostepnosc' => 'run: node scripts/dostepnosc.mjs',
        ] as $name => $pomiar) {
            $this->assertStringContainsString($pomiar, $this->job($name),
                $name.': job nie uruchamia już swojego pomiaru.');
        }
    }
}
