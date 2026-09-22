<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R47 — „`.env` w repozytorium, hardcoded hasło administratora, wyłączanie
 * CSRF" (AGENTS.md §7, lista „Nigdy"). Do 20.09.2026 reguła stała
 * w sekcji C mapy `docs/MAPA_REGUL_DOWODY.md`: żaden test się do niej
 * nie odnosił.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  R47 TO NIE JEST JEDEN ZAKAZ, TYLKO SIEDEM — O RÓŻNYCH KOSZTACH
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Wiersz mapy dziedziczy koszt najdroższego składnika, więc cała R47
 * wyglądała na niemierzalną („skan repozytorium" brzmi jak osobny projekt).
 * Po rozdzieleniu pięć z siedmiu składników jest mierzalne dziś, a dwa
 * ZOSTAJĄ W SEKCJI C — świadomie, z powodem wypisanym niżej.
 *
 * ── MIERZALNE TU ──────────────────────────────────────────────────────
 *
 *  Z1. Żaden plik poświadczeń nie leży w repozytorium poza zasięgiem
 *      `.gitignore`. Mierzone bez gita (runtime WSL nie dostaje `.git`,
 *      patrz `_wspolne/przygotuj-runtime.sh`): chodzimy po drzewie,
 *      bierzemy każdy plik o kształcie poświadczenia (`.env*`, `auth.json`,
 *      `*.pem`, `*.key`, `*.p12`, `*.pfx`) i żądamy, żeby ALBO stał
 *      w rejestrze `PLIKI_JAWNE`, ALBO był zakryty regułą `.gitignore`.
 *      Plik `.env` skopiowany do repozytorium jest widoczny dla gita
 *      dokładnie wtedy, gdy nie zakrywa go żadna reguła — i to ten skan mierzy.
 *
 *  Z2. Reguły `.gitignore`, które dziś zakrywają poświadczenia, dalej tam
 *      są (zapadka). Bez Z2 skasowanie linii `.env` z `.gitignore`
 *      przechodziłoby bez czerwieni dopóty, dopóki nikt nie ma lokalnego
 *      `.env` — czyli na CI zawsze.
 *
 *  Z3. Nigdzie w kodzie serwera nie stoi hasło wpisane tekstem: żadne
 *      `Hash::make('…')`, `bcrypt('…')` ani `assignPassword('…')`
 *      z literałem. To jest kształt WYWOŁANIA, nie słowo — nowa funkcja
 *      nadająca hasło wchodzi pod ochronę sama, o ile używa nazwanej drogi z §7.
 *
 *  Z4. Żaden seeder, fabryka ani migracja nie wstawia hasła tablicą
 *      (`'password' => '…'`). Tam, gdzie R47 najczęściej pęka: „konto
 *      administratora do testów".
 *
 *  Z5. Żadna zmienna środowiskowa o nazwie poświadczenia nie ma wpisanej
 *      wartości domyślnej — ani w `config/*` (`env('X_SECRET', 'coś')`),
 *      ani w `.env.example`. Wartość domyślna poświadczenia JEST
 *      poświadczeniem zaszytym w repozytorium, tylko wygląda niewinnie.
 *
 *  Z6. Ochrona CSRF nie jest wyłączana: lista wyjątków w `bootstrap/app.php`
 *      równa się CO DO WPISU rejestrowi `CSRF_JAWNE` (każdy wpis z powodem),
 *      przełączniki `allowSameSite` i `originOnly` stoją na `false`,
 *      a w `app/`, `routes/`, `config/` i `database/` nie ma drugiego miejsca,
 *      które by tę ochronę zdejmowało (`withoutMiddleware`, `::except()`).
 *
 *  Z7. Rejestry są dokładne w DRUGĄ stronę (`test_rejestry_nie_maja_martwych_wpisow`).
 *      Bez tego rejestr jest workiem bez dna: dopisanie do niego wszystkiego
 *      uciszyłoby skan na zawsze.
 *
 * ── NIEMIERZALNE, ZOSTAJĄ W SEKCJI C MAPY ─────────────────────────────
 *
 *  C1. Czy POWÓD przy wpisie w rejestrze jest prawdziwy. „Ten adres woła
 *      serwer Facebooka, nie przeglądarka" da się sprawdzić wyłącznie
 *      czytając kod kontrolera i dokumentację cudzego API. Automat umie
 *      sprawdzić, że powód JEST i że wpis coś opisuje — nie umie sprawdzić,
 *      czy powód jest uczciwy. Gdyby tu postawić asercję po samej obecności
 *      tekstu, wiersz mapy przeszedłby z C do A bez zmiany czegokolwiek
 *      w rzeczywistości, a to jest gorsze niż brak strażnika.
 *
 *  C2. Poświadczenie zaszyte pod nazwą, która nie wygląda na poświadczenie
 *      (`$klucz = 'Zaq12wsx';`, stała `DOSTEP`, wartość w JSON-ie z danymi).
 *      Każdy skan tej kategorii jest gripem po słowach — a kto zaszywa hasło
 *      omyłkowo, nazywa je jakkolwiek. Mierzalne tylko przeglądem człowieka.
 *
 *  Poza R47 (pilnują tego inne wiersze mapy): tokeny i PII w logach,
 *  zaufanie do MIME, renderowanie surowego HTML-a, `status`/`role`
 *  w `$fillable` (R42/R43, `WrazliweKolumnyPozaMasowymPrzypisaniemTest`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  IDIOM: SKAN ODMAWIA DOMYŚLNIE, REJESTR OTWIERA — Z POWODEM
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Wzór jest w `WrazliweKolumnyPozaMasowymPrzypisaniemTest` i nie ma powodu
 * wymyślać drugiego. Skan nie pyta, czy coś wygląda groźnie: KAŻDE trafienie
 * oblewa, chyba że stoi w rejestrze razem ze zdaniem mówiącym dlaczego.
 * Gwarancja brzmi więc nie „dziś nie ma zaszytego hasła", tylko
 * **„dopisanie go wymaga wpisu do rejestru", czyli wejścia drzwiami**.
 *
 * Jeden wpis rejestru poszedł dalej i jest ZMIERZONY, nie obiecany:
 * `DemoSeeder` zaszywa hasło konta moderatora demo, a powodem jest
 * „ten seeder odmawia na produkcji". To zdanie sprawdza
 * `test_seeder_z_zaszytym_haslem_odmawia_na_produkcji`, odpalając seeder
 * ze środowiskiem przestawionym na `production`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  PUŁAPKA 2 Z `docs/PULAPKI_TESTOW.md`: SKAN BEZ TRAFIEŃ PRZECHODZI
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Przeniesienie katalogu, zmiana iteratora albo literówka w wyrażeniu
 * wyłączyłyby ten plik bez jednego czerwonego przebiegu. Stąd
 * `test_skan_naprawde_czyta_kod_i_drzewo`: progi minimalne, kotwice
 * po nazwach plików, dowód, że skan WIDZI znane trafienie w `UserFactory`,
 * i kontrola z drugiej strony — `Hash::make(Str::random(64))`
 * w `TrescZalazkowaSeeder` trafieniem NIE jest, bo wyrażenie liczy literał,
 * a nie słowo „Hash".
 */
class PoswiadczeniaPozaRepozytoriumTest extends TestCase
{
    use RefreshDatabase;

    /** Katalogi z kodem serwera — to jest cały zasięg Z3, Z5 i Z6. */
    private const KATALOGI_KODU = ['app', 'bootstrap', 'config', 'database', 'routes'];

    /** Katalogi, w których R47 pęka najczęściej — dochodzi do nich Z4. */
    private const KATALOGI_DANYCH_POCZATKOWYCH = [
        'database/seeders',
        'database/factories',
        'database/migrations',
    ];

    /**
     * Z3 — nazwane drogi nadania hasła z AGENTS.md §7 wywołane z literałem.
     * Kształt wywołania, nie słowo: `assignPassword($haslo)` przechodzi,
     * `assignPassword('tajne')` oblewa.
     */
    private const WZORZEC_ZASZYTEGO_HASLA = '/(?:Hash::make|bcrypt|assignPassword)\(\s*([\'"])((?:(?!\1).)*)\1/';

    /** Z4 — hasło wstawiane tablicą w danych początkowych. */
    private const WZORZEC_HASLA_W_TABLICY = '/([\'"])(?:password|haslo)\1\s*=>\s*([\'"])((?:(?!\2).)*)\2/';

    /** Z5 — `env('COŚ_SECRET', 'wartość')` w konfiguracji. */
    private const WZORZEC_DOMYSLNEGO_POSWIADCZENIA =
        '/env\(\s*([\'"])([A-Z0-9_]*(?:PASSWORD|PASSWD|SECRET|TOKEN|KEY|DSN)[A-Z0-9_]*)\1\s*,\s*([\'"])((?:(?!\3).)*)\3/';

    /** Z5 — wiersz `.env.example` z niepustą wartością poświadczenia. */
    private const WZORZEC_WIERSZA_PRZYKLADU =
        '/^([A-Z0-9_]*(?:PASSWORD|PASSWD|SECRET|TOKEN|KEY|DSN)[A-Z0-9_]*)=(.+)$/m';

    /** Z1 — kształt pliku, który bywa poświadczeniem. */
    private const WZORZEC_PLIKU_POSWIADCZEN = '/^\.env(\..+)?$|^auth\.json$|\.(pem|key|p12|pfx)$/';

    /** Katalogi pomijane przy chodzeniu po drzewie — nie są repozytorium. */
    private const POMIJANE_KATALOGI = [
        '.git', 'node_modules', 'vendor',
        'storage', 'public/build', 'public/storage', 'public/hot',
        '.claude', '.phpunit.cache', '.phpstan-cache',
    ];

    /**
     * Z1/Z7 — pliki o kształcie poświadczenia, które stoją w repozytorium
     * JAWNIE. Każdy wpis mówi, dlaczego nie jest poświadczeniem.
     *
     * @var array<string, string>
     */
    private const PLIKI_JAWNE = [
        '.env.example' => 'Szablon konfiguracji bez wartości produkcyjnych — `APP_KEY` jest tu pusty, '
            .'a pozostałe wpisy pilnuje rejestr PRZYKLAD_JAWNY niżej.',
    ];

    /**
     * Z2 — reguły `.gitignore`, które MUSZĄ dalej zakrywać te nazwy.
     *
     * Lista jest dzisiejszym stanem, nie listą życzeń: `.env.local`
     * i `.env.testing` NIE są dziś ignorowane i świadomie ich tu nie ma —
     * to jest wniosek dla właściciela, nie asercja.
     *
     * @var array<string, string>
     */
    private const OBOWIAZKOWO_IGNOROWANE = [
        '.env' => 'Plik konfiguracji z prawdziwymi poświadczeniami — powód istnienia całej reguły R47.',
        '.env.backup' => 'Kopia `.env` robiona odruchowo przed zmianą; niesie dokładnie te same sekrety.',
        '.env.production' => 'Konfiguracja produkcji — najgorszy możliwy plik do wpuszczenia do repozytorium.',
        'auth.json' => 'Poświadczenia Composera do prywatnych paczek.',
        'storage/klucz.key' => 'Wzorzec `/storage/*.key` — klucze wystawiane przez narzędzia do katalogu storage.',
    ];

    /**
     * Z3/Z4/Z7 — hasła wpisane tekstem, które stoją w kodzie ŚWIADOMIE.
     *
     * Klucz zewnętrzny to ścieżka względem korzenia, wewnętrzny — sam
     * literał. Powód mówi, dlaczego ta wartość nie otwiera niczyjego konta
     * na produkcji.
     *
     * @var array<string, array<string, string>>
     */
    private const HASLA_JAWNE = [
        'database/factories/UserFactory.php' => [
            'haslo-testowe-123' => 'Fabryka modelu — kod wyłącznie testowy, nie zakłada żadnego konta '
                .'poza bazą testową.',
        ],
        'database/seeders/DemoSeeder.php' => [
            'haslo-testowe-123' => 'Konta demo (w tym moderator demo) zakładane poza produkcją. Powód JEST ZMIERZONY: '
                .'`test_seeder_z_zaszytym_haslem_odmawia_na_produkcji` odpala ten seeder ze środowiskiem '
                .'`production` i sprawdza, że nie powstaje ani jedno konto.',
        ],
    ];

    /**
     * Z5/Z7 — wartości domyślne pod nazwą wyglądającą na poświadczenie,
     * które poświadczeniem nie są.
     *
     * @var array<string, array<string, string>>
     */
    private const DOMYSLNE_JAWNE = [
        'config/auth.php' => [
            'AUTH_PASSWORD_BROKER' => 'Nazwa brokera resetu hasła (`users`), nie hasło.',
            'AUTH_PASSWORD_RESET_TOKEN_TABLE' => 'Nazwa tabeli z tokenami resetu, nie token.',
        ],
    ];

    /**
     * Z5/Z7 — wiersze `.env.example` z niepustą wartością.
     *
     * @var array<string, string>
     */
    private const PRZYKLAD_JAWNY = [
        'DB_PASSWORD' => 'Hasło lokalnej bazy z instrukcji uruchomienia (`kuking`) — na produkcji Railway '
            .'wstrzykuje `DATABASE_URL`, więc ta wartość nigdzie nie obowiązuje.',
        'MAIL_PASSWORD' => 'Dosłowne `null` — wartość pusta zapisana słowem, bo Laravel czyta ją jako `null`.',
    ];

    /**
     * Z6/Z7 — adresy wyjęte spod ochrony CSRF, każdy z powodem.
     *
     * Rejestr jest KOPIĄ listy z `bootstrap/app.php` i ma być z nią równy
     * co do wpisu. Uzasadnienia pełne stoją tam, w komentarzu — tu jest
     * jedno zdanie, żeby czytający ten test wiedział, na co patrzy.
     *
     * @var array<string, string>
     */
    private const CSRF_JAWNE = [
        '_csp' => 'Zgłoszenia naruszeń CSP wysyła sama przeglądarka: bez sesji, bez tokenu. '
            .'Endpoint niczego nie zapisuje i zawsze zwraca 204.',
        'podsumowanie/wypisz/*' => 'Wypisanie z podsumowania metodą POST wołają Gmail i Outlook prosto z listu '
            .'(RFC 8058). Ochroną jest PODPIS w adresie (`middleware(\'signed\')`), nie brak ochrony.',
        'wejdz/facebook/odebranie-dostepu' => 'Woła to serwer Facebooka, nie przeglądarka. Autentyczność '
            .'potwierdza `signed_request` sprawdzany przez `hash_equals` na sekrecie aplikacji.',
    ];

    /** Kształty zdejmujące ochronę CSRF poza jednym miejscem w `bootstrap/app.php`. */
    private const WZORZEC_ZDJECIA_CSRF =
        '/withoutMiddleware\s*\(|(?:PreventRequestForgery|ValidateCsrfToken|VerifyCsrfToken)::except\s*\(|allowSameSite\s*\(|useOriginOnly\s*\(/';

    // ──────────────────────────────────────────────────────────────────
    //  NARZĘDZIA SKANU
    // ──────────────────────────────────────────────────────────────────

    /**
     * Wszystkie pliki repozytorium poza katalogami, które repozytorium
     * nie są. Ścieżki względne, ukośnikiem w przód — także na Windowsie.
     *
     * @return list<string>
     */
    private function plikiDrzewa(): array
    {
        $korzen = rtrim(str_replace('\\', '/', base_path()), '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(base_path(), \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $plik) use ($korzen): bool {
                    if (! $plik->isDir()) {
                        return true;
                    }

                    $wzgledna = trim(substr(str_replace('\\', '/', $plik->getPathname()), strlen($korzen)), '/');

                    return ! in_array($wzgledna, self::POMIJANE_KATALOGI, true);
                },
            ),
        );

        $pliki = [];

        foreach ($iterator as $plik) {
            if ($plik->isFile()) {
                $pliki[] = trim(substr(str_replace('\\', '/', $plik->getPathname()), strlen($korzen)), '/');
            }
        }

        sort($pliki);

        return $pliki;
    }

    /**
     * Pliki PHP kodu serwera.
     *
     * @return list<string>
     */
    private function plikiKodu(): array
    {
        return array_values(array_filter(
            $this->plikiDrzewa(),
            static function (string $sciezka): bool {
                if (! str_ends_with($sciezka, '.php')) {
                    return false;
                }

                foreach (self::KATALOGI_KODU as $katalog) {
                    if (str_starts_with($sciezka, $katalog.'/')) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * Reguły `.gitignore` — pary [wzorzec, czy to zaprzeczenie].
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function regulyIgnorowania(): array
    {
        $tresc = (string) file_get_contents(base_path('.gitignore'));
        $reguly = [];

        foreach (preg_split('/\R/', $tresc) ?: [] as $wiersz) {
            $wiersz = trim($wiersz);

            if ($wiersz === '' || str_starts_with($wiersz, '#')) {
                continue;
            }

            $zaprzeczenie = str_starts_with($wiersz, '!');
            $reguly[] = [ltrim($wiersz, '!'), $zaprzeczenie];
        }

        return $reguly;
    }

    /**
     * Czy `.gitignore` zakrywa tę ścieżkę.
     *
     * Świadomie uproszczony matcher: obsługuje kotwicę `/`, ukośnik
     * katalogowy na końcu i `*` w obrębie jednego segmentu — czyli
     * dokładnie to, czego używa `.gitignore` tego repozytorium. Ostatnia
     * pasująca reguła wygrywa, tak jak w gicie.
     */
    private function zakrytyPrzezGitignore(string $sciezka): bool
    {
        $zakryty = false;

        foreach ($this->regulyIgnorowania() as [$wzorzec, $zaprzeczenie]) {
            $zakotwiczony = str_contains(trim($wzorzec, '/'), '/') || str_starts_with($wzorzec, '/');
            $goly = trim($wzorzec, '/');

            $regex = '/^'.str_replace(['\*\*', '\*'], ['.*', '[^\/]*'], preg_quote($goly, '/')).'(\/.*)?$/';

            $celuje = preg_match($regex, $sciezka) === 1
                || (! $zakotwiczony && preg_match($regex, basename($sciezka)) === 1);

            if ($celuje) {
                $zakryty = ! $zaprzeczenie;
            }
        }

        return $zakryty;
    }

    /**
     * Trafienia jednego wyrażenia w podanych plikach.
     *
     * @param  list<string>  $pliki
     * @param  int  $grupa  numer grupy niosącej znalezioną wartość
     * @return array<string, list<string>> ścieżka => znalezione wartości
     */
    private function trafienia(array $pliki, string $wzorzec, int $grupa): array
    {
        $wynik = [];

        foreach ($pliki as $sciezka) {
            $tresc = (string) file_get_contents(base_path($sciezka));

            if (preg_match_all($wzorzec, $tresc, $dopasowania) === 0) {
                continue;
            }

            $wartosci = array_values(array_unique(array_filter(
                $dopasowania[$grupa],
                static fn (string $wartosc): bool => trim($wartosc) !== '',
            )));

            if ($wartosci !== []) {
                $wynik[$sciezka] = $wartosci;
            }
        }

        return $wynik;
    }

    /**
     * Z5 — zmienne poświadczeń, którym `env()` podaje NIEPUSTĄ wartość
     * domyślną.
     *
     * Osobna metoda zamiast `trafienia()`, bo tu liczy się para: nazwa
     * zmiennej jest tym, co raportujemy, a o trafieniu decyduje WARTOŚĆ.
     * `env('DB_PASSWORD', '')` trafieniem NIE jest — pusty ciąg niczego
     * nie otwiera, a wciągnięcie go do rejestru zamieniłoby rejestr
     * w spis wszystkich `env()` w projekcie.
     *
     * @param  list<string>  $pliki
     * @return array<string, list<string>> ścieżka => nazwy zmiennych
     */
    private function domyslnePoswiadczenia(array $pliki): array
    {
        $wynik = [];

        foreach ($pliki as $sciezka) {
            $tresc = (string) file_get_contents(base_path($sciezka));

            if (preg_match_all(self::WZORZEC_DOMYSLNEGO_POSWIADCZENIA, $tresc, $zestawy, PREG_SET_ORDER) === 0) {
                continue;
            }

            $zmienne = [];

            foreach ($zestawy as $zestaw) {
                if (trim($zestaw[4]) === '') {
                    continue;
                }

                $zmienne[] = $zestaw[2];
            }

            if ($zmienne !== []) {
                $wynik[$sciezka] = array_values(array_unique($zmienne));
            }
        }

        return $wynik;
    }

    /**
     * Adresy wyjęte spod ochrony CSRF — odczytane z ŻYWEGO frameworka,
     * nie z kopii w tym pliku.
     *
     * @return list<string>
     */
    private function zywaListaWyjatkowCsrf(): array
    {
        $wlasciwosc = new \ReflectionProperty(PreventRequestForgery::class, 'neverVerify');
        $wlasciwosc->setAccessible(true);

        /** @var list<string> $lista */
        $lista = $wlasciwosc->getValue();

        return array_values($lista);
    }

    private function przelacznikCsrf(string $nazwa): bool
    {
        $wlasciwosc = new \ReflectionProperty(PreventRequestForgery::class, $nazwa);
        $wlasciwosc->setAccessible(true);

        return (bool) $wlasciwosc->getValue();
    }

    // ──────────────────────────────────────────────────────────────────
    //  Z1 + Z2 — PLIKI POŚWIADCZEŃ
    // ──────────────────────────────────────────────────────────────────

    /**
     * Z1: GŁÓWNY POMIAR. Żaden plik o kształcie poświadczenia nie leży
     * w repozytorium poza zasięgiem `.gitignore`.
     */
    public function test_zaden_plik_poswiadczen_nie_stoi_w_repozytorium(): void
    {
        $sprawdzonych = 0;
        $bledy = [];

        foreach ($this->plikiDrzewa() as $sciezka) {
            if (preg_match(self::WZORZEC_PLIKU_POSWIADCZEN, basename($sciezka)) !== 1) {
                continue;
            }

            $sprawdzonych++;

            if (array_key_exists($sciezka, self::PLIKI_JAWNE)) {
                continue;
            }

            if ($this->zakrytyPrzezGitignore($sciezka)) {
                continue;
            }

            $bledy[] = "{$sciezka} — plik o kształcie poświadczenia, którego NIE zakrywa żadna reguła "
                .'`.gitignore` i którego nie ma w rejestrze PLIKI_JAWNE tego testu. Git go widzi, '
                .'czyli jedno `git add .` wpuszcza go do repozytorium. Albo dopisz regułę do `.gitignore`, '
                .'albo — jeśli to naprawdę nie jest poświadczenie — dopisz wpis do rejestru z powodem.';
        }

        $this->assertSame([], $bledy, "Pliki poświadczeń widoczne dla gita:\n- ".implode("\n- ", $bledy));

        // Zmierzone 20.09.2026: w drzewie stoi dokładnie jeden taki plik
        // (`.env.example`). Zero znaczyłoby, że iterator przestał chodzić.
        $this->assertGreaterThanOrEqual(
            1,
            $sprawdzonych,
            'Skan nie zobaczył ANI JEDNEGO pliku o kształcie poświadczenia — nawet `.env.example`. '
            .'To nie jest czysty wynik, to zepsuty iterator.',
        );
    }

    /**
     * Z2: ZAPADKA NA `.gitignore`. Reguły, które dziś zakrywają
     * poświadczenia, dalej tam są.
     */
    public function test_gitignore_wciaz_zakrywa_pliki_poswiadczen(): void
    {
        foreach (self::OBOWIAZKOWO_IGNOROWANE as $sciezka => $powod) {
            $this->assertNotSame('', trim($powod), "Wpis {$sciezka} nie ma powodu.");

            $this->assertTrue(
                $this->zakrytyPrzezGitignore($sciezka),
                "`.gitignore` przestał zakrywać `{$sciezka}`. {$powod} "
                .'Bez tej reguły plik staje się niewidoczny dla strażnika i widoczny dla gita — '
                .'a na CI, gdzie takiego pliku nigdy nie ma, nic by tego nie zauważyło.',
            );
        }

        // KONTROLA Z DRUGIEJ STRONY: szablon MA być widoczny dla gita.
        // Reguła zakrywająca `.env.example` zabrałaby ludziom jedyny opis
        // konfiguracji — a matcher, który zwraca `true` na wszystko,
        // udawałby tu spełnioną regułę.
        $this->assertFalse(
            $this->zakrytyPrzezGitignore('.env.example'),
            '`.gitignore` zaczął zakrywać `.env.example` — szablon konfiguracji wypadł z repozytorium.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  Z3 + Z4 + Z5 — POŚWIADCZENIA WPISANE TEKSTEM
    // ──────────────────────────────────────────────────────────────────

    /**
     * Z3 + Z4: GŁÓWNY POMIAR. Żadne hasło nie jest wpisane tekstem
     * w kodzie serwera poza rejestrem.
     */
    public function test_zadne_haslo_nie_jest_zaszyte_w_kodzie(): void
    {
        $bledy = [];
        $sprawdzonych = 0;

        $nazwane = $this->trafienia($this->plikiKodu(), self::WZORZEC_ZASZYTEGO_HASLA, 2);

        $daneStartowe = array_values(array_filter(
            $this->plikiKodu(),
            static function (string $sciezka): bool {
                foreach (self::KATALOGI_DANYCH_POCZATKOWYCH as $katalog) {
                    if (str_starts_with($sciezka, $katalog.'/')) {
                        return true;
                    }
                }

                return false;
            },
        ));

        $tablicowe = $this->trafienia($daneStartowe, self::WZORZEC_HASLA_W_TABLICY, 3);

        foreach ([$nazwane, $tablicowe] as $zbior) {
            foreach ($zbior as $sciezka => $literaly) {
                foreach ($literaly as $literal) {
                    $sprawdzonych++;

                    if (isset(self::HASLA_JAWNE[$sciezka][$literal])) {
                        continue;
                    }

                    $bledy[] = "{$sciezka} — hasło wpisane tekstem: `{$literal}`. AGENTS.md §7 zakazuje "
                        .'hasła zaszytego w kodzie wprost. Weź je ze zmiennej środowiskowej albo z `Str::random()`; '
                        .'jeśli to naprawdę kod wyłącznie testowy, dopisz wpis do rejestru HASLA_JAWNE '
                        .'z powodem mówiącym, dlaczego ta wartość nie otwiera konta na produkcji.';
                }
            }
        }

        $this->assertSame([], $bledy, "Hasła zaszyte w kodzie:\n- ".implode("\n- ", $bledy));

        // Zmierzone 20.09.2026: dwa trafienia, oba w rejestrze. Zero znaczy,
        // że wyrażenie przestało cokolwiek łapać — patrz kontrola dodatnia.
        $this->assertGreaterThanOrEqual(
            2,
            $sprawdzonych,
            'Skan nie znalazł ani jednego znanego literału hasła — wyrażenie albo lista plików przestały działać.',
        );
    }

    /**
     * Z5: GŁÓWNY POMIAR. Żadna zmienna o nazwie poświadczenia nie ma
     * wpisanej wartości domyślnej — ani w `config/`, ani w `.env.example`.
     */
    public function test_zadne_poswiadczenie_nie_ma_wartosci_domyslnej(): void
    {
        $bledy = [];
        $sprawdzonych = 0;

        $wKonfiguracji = $this->domyslnePoswiadczenia($this->plikiKodu());

        foreach ($wKonfiguracji as $sciezka => $zmienne) {
            foreach ($zmienne as $zmienna) {
                $sprawdzonych++;

                if (isset(self::DOMYSLNE_JAWNE[$sciezka][$zmienna])) {
                    continue;
                }

                $bledy[] = "{$sciezka} — `env('{$zmienna}', …)` ma wpisaną wartość domyślną. "
                    .'Wartość domyślna poświadczenia jest poświadczeniem zaszytym w repozytorium, '
                    .'tylko wygląda niewinnie: gdy zmiennej zabraknie, serwis wstaje z tą wartością. '
                    .'Zostaw `env(\'…\')` bez drugiego argumentu albo dopisz wpis do rejestru DOMYSLNE_JAWNE.';
            }
        }

        preg_match_all(
            self::WZORZEC_WIERSZA_PRZYKLADU,
            (string) file_get_contents(base_path('.env.example')),
            $wiersze,
        );

        foreach ($wiersze[1] as $indeks => $zmienna) {
            $sprawdzonych++;

            if (array_key_exists($zmienna, self::PRZYKLAD_JAWNY)) {
                continue;
            }

            $wartosc = trim($wiersze[2][$indeks]);

            $bledy[] = ".env.example — `{$zmienna}={$wartosc}` niesie wartość. Szablon konfiguracji "
                .'jest w repozytorium, więc każda wpisana tu wartość poświadczenia jest wyciekiem. '
                .'Zostaw wpis pusty albo dopisz go do rejestru PRZYKLAD_JAWNY z powodem.';
        }

        $this->assertSame([], $bledy, "Poświadczenia z wartością domyślną:\n- ".implode("\n- ", $bledy));

        // Zmierzone 20.09.2026: dwa w `config/auth.php` i dwa w `.env.example`.
        $this->assertGreaterThanOrEqual(
            4,
            $sprawdzonych,
            'Skan wartości domyślnych nic nie znalazł — wyrażenia przestały łapać.',
        );
    }

    /**
     * POWÓD WPISU REJESTRU ZMIERZONY, NIE OBIECANY.
     *
     * `DemoSeeder` wolno zaszywać hasło konta moderatora demo wyłącznie
     * dlatego, że odmawia na produkcji. Gdyby ten warunek kiedyś wypadł,
     * wpis w rejestrze dalej by się zgadzał — i strażnik przepuściłby
     * konto moderatora z hasłem `haslo-testowe-123` na żywym serwisie.
     */
    public function test_seeder_z_zaszytym_haslem_odmawia_na_produkcji(): void
    {
        $przed = User::query()->count();

        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->assertTrue($this->app->environment('production'));

        (new DemoSeeder)->run();

        $this->assertSame(
            $przed,
            User::query()->count(),
            'DemoSeeder założył konta ze środowiskiem `production`. To jest dokładnie ten wyciek, '
            .'przed którym chroni R47: konto moderatora z hasłem wpisanym w kodzie, na żywym serwisie. '
            .'Albo przywróć warunek `app()->environment(\'production\')`, albo wyjmij hasło z kodu.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  Z6 — OCHRONA CSRF
    // ──────────────────────────────────────────────────────────────────

    /**
     * Z6: GŁÓWNY POMIAR. Ochrona CSRF nie jest wyłączana — ani listą
     * wyjątków, ani przełącznikiem, ani drugim miejscem w kodzie.
     */
    public function test_ochrona_csrf_nie_jest_wylaczana(): void
    {
        $zywa = $this->zywaListaWyjatkowCsrf();
        sort($zywa);

        $rejestr = array_keys(self::CSRF_JAWNE);
        sort($rejestr);

        $this->assertSame(
            $rejestr,
            $zywa,
            "Lista adresów wyjętych spod ochrony CSRF rozjechała się z rejestrem tego testu.\n"
            .'Każdy nowy wyjątek wymaga wpisu do CSRF_JAWNE ze zdaniem mówiącym, dlaczego token jest tam '
            ."FIZYCZNIE NIEMOŻLIWY do podania (a nie: niewygodny) i co chroni tę trasę zamiast niego.\n"
            .'Żywa lista: '.implode(', ', $zywa),
        );

        // Dwa przełączniki frameworka, które zdejmują ochronę bez dotykania
        // listy wyjątków — czyli niewidocznie dla asercji wyżej.
        $this->assertFalse(
            $this->przelacznikCsrf('allowSameSite'),
            'Włączono `allowSameSite` — żądanie z tej samej witryny przechodzi bez tokenu. '
            .'To jest wyłączenie ochrony CSRF na CAŁYM serwisie naraz.',
        );
        $this->assertFalse(
            $this->przelacznikCsrf('originOnly'),
            'Włączono `originOnly` — token przestaje być sprawdzany, zostaje sam nagłówek `Origin`.',
        );

        // Trzecia droga: drugie miejsce w kodzie, które zdejmuje ochronę.
        $zdjecia = [];

        foreach ($this->plikiKodu() as $sciezka) {
            if ($sciezka === 'bootstrap/app.php') {
                continue;
            }

            $tresc = (string) file_get_contents(base_path($sciezka));

            if (preg_match(self::WZORZEC_ZDJECIA_CSRF, $tresc) === 1) {
                $zdjecia[] = $sciezka;
            }
        }

        $this->assertSame(
            [],
            $zdjecia,
            "Ochronę CSRF zdejmuje kod spoza `bootstrap/app.php`:\n- ".implode("\n- ", $zdjecia)."\n"
            .'Wyjątki mają stać w JEDNYM miejscu, razem z powodem — inaczej nikt nie odpowie na pytanie, '
            .'ile tras chodzi dziś bez tokenu.',
        );
    }

    /**
     * Z6: kontrola dodatnia rejestru CSRF — każdy wpis opisuje trasę,
     * która NAPRAWDĘ istnieje.
     *
     * Bez tego rejestr przeżyłby skasowanie trasy i udawał, że coś opisuje;
     * następna osoba przeczytałaby go jako opis stanu, którego nie ma.
     */
    public function test_rejestr_csrf_opisuje_istniejace_trasy(): void
    {
        $trasy = array_map(
            static fn ($trasa): string => $trasa->uri(),
            Route::getRoutes()->getRoutes(),
        );

        $this->assertGreaterThanOrEqual(
            50,
            count($trasy),
            'Tablica tras jest prawie pusta — `routes/web.php` nie wczytał się w tym przebiegu.',
        );

        $martwe = [];

        foreach (self::CSRF_JAWNE as $wzorzec => $powod) {
            $this->assertNotSame('', trim($powod), "Wpis CSRF `{$wzorzec}` nie ma powodu.");

            $regex = '/^'.str_replace('\*', '.*', preg_quote($wzorzec, '/')).'$/';

            $pasuje = array_filter($trasy, static fn (string $uri): bool => preg_match($regex, $uri) === 1);

            if ($pasuje === []) {
                $martwe[] = "`{$wzorzec}` — żadna zarejestrowana trasa nie pasuje do tego wzorca. "
                    .'Skasuj wpis z `bootstrap/app.php` i z rejestru: wyjątek bez trasy to otwarte drzwi '
                    .'czekające na adres, który ktoś kiedyś tu założy.';
            }
        }

        $this->assertSame([], $martwe, "Martwe wpisy w rejestrze CSRF:\n- ".implode("\n- ", $martwe));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Z7 — REJESTRY DOKŁADNE W DRUGĄ STRONĘ
    // ──────────────────────────────────────────────────────────────────

    /**
     * Z7: rejestry nie mają martwych wpisów.
     *
     * Rejestr, do którego wolno dopisywać, a z którego nic nie wypada,
     * jest workiem bez dna: po roku opisuje stan sprzed roku i nikt tego
     * nie zauważy, bo test świeci na zielono.
     */
    public function test_rejestry_nie_maja_martwych_wpisow(): void
    {
        $martwe = [];
        $drzewo = $this->plikiDrzewa();

        foreach (self::PLIKI_JAWNE as $sciezka => $powod) {
            $this->assertNotSame('', trim($powod), "Wpis PLIKI_JAWNE {$sciezka} nie ma powodu.");

            if (! in_array($sciezka, $drzewo, true)) {
                $martwe[] = "PLIKI_JAWNE: `{$sciezka}` — takiego pliku już nie ma w repozytorium.";
            }
        }

        $zaszyte = $this->trafienia($this->plikiKodu(), self::WZORZEC_ZASZYTEGO_HASLA, 2);
        $tablicowe = $this->trafienia($this->plikiKodu(), self::WZORZEC_HASLA_W_TABLICY, 3);

        foreach (self::HASLA_JAWNE as $sciezka => $literaly) {
            foreach ($literaly as $literal => $powod) {
                $this->assertNotSame('', trim($powod), "Wpis HASLA_JAWNE {$sciezka}.{$literal} nie ma powodu.");

                $znalezione = array_merge($zaszyte[$sciezka] ?? [], $tablicowe[$sciezka] ?? []);

                if (! in_array($literal, $znalezione, true)) {
                    $martwe[] = "HASLA_JAWNE: `{$sciezka}` nie zawiera już literału `{$literal}` — "
                        .'wpis niczego nie opisuje. Skasuj go.';
                }
            }
        }

        $domyslne = $this->domyslnePoswiadczenia($this->plikiKodu());

        foreach (self::DOMYSLNE_JAWNE as $sciezka => $zmienne) {
            foreach ($zmienne as $zmienna => $powod) {
                $this->assertNotSame('', trim($powod), "Wpis DOMYSLNE_JAWNE {$sciezka}.{$zmienna} nie ma powodu.");

                if (! in_array($zmienna, $domyslne[$sciezka] ?? [], true)) {
                    $martwe[] = "DOMYSLNE_JAWNE: `{$sciezka}` nie ma już `env('{$zmienna}', …)` z wartością — "
                        .'wpis niczego nie opisuje. Skasuj go.';
                }
            }
        }

        preg_match_all(
            self::WZORZEC_WIERSZA_PRZYKLADU,
            (string) file_get_contents(base_path('.env.example')),
            $wiersze,
        );

        foreach (self::PRZYKLAD_JAWNY as $zmienna => $powod) {
            $this->assertNotSame('', trim($powod), "Wpis PRZYKLAD_JAWNY {$zmienna} nie ma powodu.");

            if (! in_array($zmienna, $wiersze[1], true)) {
                $martwe[] = "PRZYKLAD_JAWNY: `.env.example` nie ma już niepustego `{$zmienna}` — skasuj wpis.";
            }
        }

        foreach (self::OBOWIAZKOWO_IGNOROWANE as $sciezka => $powod) {
            $this->assertNotSame('', trim($powod), "Wpis OBOWIAZKOWO_IGNOROWANE {$sciezka} nie ma powodu.");
        }

        $this->assertSame([], $martwe, "Martwe wpisy w rejestrach:\n- ".implode("\n- ", $martwe));
    }

    // ──────────────────────────────────────────────────────────────────
    //  KONTROLA DODATNIA (PUŁAPKA 2)
    // ──────────────────────────────────────────────────────────────────

    /**
     * SKAN NAPRAWDĘ CZYTA TO, CO MA CZYTAĆ.
     *
     * Wszystkie pomiary wyżej są skanami, a skan bez trafień przechodzi.
     * Ten test dowodzi, że iterator chodzi po drzewie, że wyrażenia łapią
     * ZNANE trafienia i że nie łapią rzeczy, które trafieniami nie są.
     */
    public function test_skan_naprawde_czyta_kod_i_drzewo(): void
    {
        $drzewo = $this->plikiDrzewa();

        // Zmierzone 20.09.2026: plików PHP w katalogach kodu serwera jest 482.
        $this->assertGreaterThanOrEqual(
            400,
            count($this->plikiKodu()),
            'W katalogach kodu widać mniej plików PHP niż powinno — iterator albo ścieżki przestały działać.',
        );

        // Kotwice: pliki, o których wiemy, że istnieją i że mają znaczenie
        // dla każdego z trzech składników R47.
        foreach ([
            '.env.example',
            '.gitignore',
            'bootstrap/app.php',
            'config/auth.php',
            'database/factories/UserFactory.php',
            'database/seeders/DemoSeeder.php',
        ] as $kotwica) {
            $this->assertContains($kotwica, $drzewo, "Skan nie widzi `{$kotwica}` — nie widzi więc niczego.");
        }

        // Z3: znane trafienie MUSI być widziane. Gdyby wyrażenie przestało
        // łapać literał, `test_zadne_haslo_nie_jest_zaszyte_w_kodzie`
        // świeciłby na zielono, nie sprawdzając niczego.
        $zaszyte = $this->trafienia($this->plikiKodu(), self::WZORZEC_ZASZYTEGO_HASLA, 2);

        $this->assertArrayHasKey('database/factories/UserFactory.php', $zaszyte);
        $this->assertContains('haslo-testowe-123', $zaszyte['database/factories/UserFactory.php']);
        $this->assertArrayHasKey('database/seeders/DemoSeeder.php', $zaszyte);
        $this->assertContains('haslo-testowe-123', $zaszyte['database/seeders/DemoSeeder.php']);

        // KONTROLA Z DRUGIEJ STRONY — wyrażenie liczy LITERAŁ, nie słowo:
        // `TrescZalazkowaSeeder` woła `Hash::make(Str::random(64))` i to
        // trafieniem NIE jest. Inaczej „hasło zaszyte w kodzie" znaczyłoby
        // tyle, co „w pliku pada słowo Hash".
        $this->assertArrayNotHasKey(
            'database/seeders/TrescZalazkowaSeeder.php',
            $zaszyte,
            'Hasło losowane przez `Str::random()` zostało policzone jako zaszyte — wyrażenie liczy słowo, '
            .'a nie literał, i rejestr zaraz spuchnie o wpisy, które niczego nie chronią.',
        );

        // Z5: znane trafienia w `config/auth.php` i w `.env.example`.
        $domyslne = $this->domyslnePoswiadczenia($this->plikiKodu());
        $this->assertArrayHasKey('config/auth.php', $domyslne);

        // KONTROLA Z DRUGIEJ STRONY: `config/database.php` woła
        // `env('DB_PASSWORD', '')` — wartość domyślna JEST, ale jest pusta,
        // więc trafieniem nie jest. Bez tej asercji rejestr DOMYSLNE_JAWNE
        // musiałby nieść siedem wpisów po pustych ciągach i przestałby
        // cokolwiek znaczyć.
        $this->assertArrayNotHasKey(
            'config/database.php',
            $domyslne,
            'Pusta wartość domyślna została policzona jako poświadczenie — rejestr zaraz spuchnie '
            .'o wpisy, które niczego nie chronią.',
        );

        preg_match_all(
            self::WZORZEC_WIERSZA_PRZYKLADU,
            (string) file_get_contents(base_path('.env.example')),
            $wiersze,
        );
        $this->assertContains('DB_PASSWORD', $wiersze[1]);
        // Kontrola z drugiej strony: `APP_KEY=` jest PUSTY i wyrażenie ma
        // to wiedzieć — inaczej rejestr musiałby nieść wpis dla pustej wartości.
        $this->assertNotContains('APP_KEY', $wiersze[1], 'APP_KEY w `.env.example` przestał być pusty.');

        // Z1: matcher `.gitignore` naprawdę rozróżnia. Bez tego
        // `zakrytyPrzezGitignore()` mogłoby zwracać `true` na wszystko.
        $this->assertTrue($this->zakrytyPrzezGitignore('.env'));
        $this->assertTrue($this->zakrytyPrzezGitignore('storage/cokolwiek.key'));
        $this->assertFalse($this->zakrytyPrzezGitignore('.env.example'));
        $this->assertFalse($this->zakrytyPrzezGitignore('app/Models/User.php'));

        // Z6: czytamy ŻYWĄ listę frameworka, nie własną kopię.
        $this->assertContains('_csp', $this->zywaListaWyjatkowCsrf());
        // Droga powrotna z podsumowania NIE jest wyjęta spod ochrony
        // i ma nie być (komentarz w `bootstrap/app.php`): bez tokenu byłaby
        // drogą do ZAPISANIA kogoś z powrotem.
        $this->assertNotContains('podsumowanie/wracam/*', $this->zywaListaWyjatkowCsrf());
    }
}
