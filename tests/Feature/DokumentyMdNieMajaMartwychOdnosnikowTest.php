<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Odnośniki i ścieżki w plikach `.md` prowadzą do celów, które naprawdę
 * istnieją (audyt martwych odnośników, 12 września 2026).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Audyt z 12 września znalazł na żywej stronie `/zasady`, w paragrafie
 * o wysyłaniu zdjęć do zewnętrznego modelu moderacji, odnośnik
 * `[polityka prywatności](/polityka-prywatnosci)`. Taka trasa nie istnieje —
 * prawdziwa to `/prywatnosc`. Adres wzięto z nazwy pliku
 * `polityka-prywatnosci.md`. Dokumenty prawne miały testy pilnujące ich
 * TREŚCI (`DokumentyPrawneNieKlamiaTest`) i ani jednego, który chodziłby po
 * ich ODNOŚNIKACH. Ten jeden błąd naprawiono ręcznie w tym samym audycie —
 * ten test pilnuje, żeby klasa błędu nie wróciła gdzie indziej.
 *
 * CO TEN TEST SPRAWDZA
 *  1. Każdy odnośnik markdown `[tekst](sciezka)` do lokalnego pliku —
 *     w KAŻDYM pliku `.md` repozytorium — prowadzi do pliku/katalogu, który
 *     istnieje, a kotwica `#cos` (jeśli jest) odpowiada nagłówkowi w tamtym
 *     pliku (algorytm slugów jak na GitHubie).
 *  2. Trasy serwisu wspominane w backtickach (`/prywatnosc`, `/ustawienia/profil`)
 *     odpowiadają prawdziwym trasom z `Route::getRoutes()` — nie zgadywaniu
 *     z nazwy pliku, dokładnie tak, jak każe `AGENTS.md` §10.
 *
 * CZEGO TEN TEST ŚWIADOMIE NIE SPRAWDZA
 * Gołych ścieżek do plików w backtickach/tekście (bez `[]()`), np.
 * `app/Models/Post.php` wspomniane w zdaniu. Powód: w tym repozytorium takie
 * wzmianki bywają CYTATEM DOWODU NIEISTNIENIA czegoś („ls config/sentry.php →
 * brak" w `AGENTS.md` §3) — regex nie odróżni „to jest tu" od „tego tu nie
 * ma i o to chodzi", więc taki test miałby setki fałszywych czerwieni bez
 * końca. To sprawdzono ręcznie przy audycie z 12 września (raport w opisie
 * PR-a); prawdziwe odnośniki klikalne (markdown) i trasy tego problemu nie
 * mają i dlatego są tu pilnowane automatycznie.
 *
 * KONTROLA UJEMNA (pułapka 2 z `docs/PULAPKI_TESTOW.md`)
 * Asercje na minimalną liczbę SPRAWDZONYCH odnośników i tras niżej —
 * bez nich zła ścieżka skanowania (zero trafień) wyglądałaby jak sukces.
 */
class DokumentyMdNieMajaMartwychOdnosnikowTest extends TestCase
{
    /**
     * Katalogi/pliki historyczne — audyty datowane, zlecenia zewnętrzne,
     * dziennik decyzji i oryginał paczki designu przysłany jako ZIP.
     * Cytują stan kodu i tras SPRZED zmian albo opisują coś, co nigdy nie
     * miało wejść do repozytorium (dowody audytora, brief zlecenia).
     * Odnośniki-PLIKI z tych dokumentów nadal muszą istnieć (test 1 ich nie
     * wyklucza) — nie prowadzą donikąd tylko dlatego, że dokument jest stary.
     * Wykluczone są tylko z testu TRAS (test 2), bo tam fałszywych trafień
     * jest dziesiątki: audyt cytuje angielski adres jako PRZYKŁAD BŁĘDU, nie
     * jako twierdzenie, że trasa istnieje dziś.
     */
    private const WYKLUCZONE_Z_TRAS_PREFIKSY = [
        'docs/research/',
        'docs/zlecenia/',
        'docs/design/system-v3.1/',
        // Dzienniki i inwentarze floty: zapisują przebieg pracy, a nie obietnice
        // produktu. Backticki niosą tam ścieżki systemu plików i fragmenty
        // cudzych API — `/c/.../Codex`, `/Users/matma/…/.git/worktrees/…`,
        // `/merge`, `/logs`, `/v2.1/email` — których skaner nie odróżni od
        // adresu na kuking.pl. Wykluczenie zawęża ZAKRES strażnika, nie osłabia
        // go: dokumenty produktu w `docs/product/` i `docs/design/` dalej muszą
        // wskazywać trasy, które istnieją. Sprawdzone 21.09.2026 — dwanaście
        // zgłoszeń z tego katalogu, żadne nie było adresem naszego serwisu.
        'docs/flota/',
        // Wpisy dziennika decyzji (od 25.09.2026 jeden plik na decyzję) — ten
        // sam powód co przy docs/DECISIONS.md niżej. ADR-y obok nie są wykluczone.
        'docs/decyzje/D-',
        'docs/decyzje/U-',
    ];

    private const WYKLUCZONE_Z_TRAS_PLIKI = [
        'docs/AUDYT_2026-09.md',
        'docs/AUDYT_GPT_2026-09.md',
        'R1-tagi-kopia.md',
        // Dziennik decyzji: zapisuje też decyzje POŹNIEJ zmienione albo
        // adresy z etapu, zanim je zbudowano — z definicji historyczny.
        'docs/DECISIONS.md',
    ];

    /**
     * Trasy, które wyglądają jak nasz adres, ale nie są — z udokumentowanym
     * powodem każda. Nowe wystąpienie spoza tej listy MA oblać test: albo
     * dopisz tu powód, albo popraw dokument.
     */
    private const DOZWOLONE_NIE_TRASY = [
        '/actions-runner-kuking-03' => 'nazwa katalogu self-hosted runnera (WYMAGANIA_RUNNERA.md), nie trasa serwisu',
        '/add' => 'cytat historyczny „tak było źle" w SEO_TECHNICAL.md, nie dzisiejsza trasa',
        '/search' => 'cytat historyczny „tak było źle" w SEO_TECHNICAL.md, nie dzisiejsza trasa',
        '/admin' => 'nieformalne odwołanie do całego panelu, nie konkretny adres',
        '/admin/' => 'nieformalne odwołanie do całego panelu, nie konkretny adres',
        '/admin/zgloszenia/{report}/podglad' => 'trasa z niezbudowanej specyfikacji P2 (PRZEGLAD_SPEC_9_DECYZJI.md)',
        '/facebook/usun-dane' => 'jawnie oznaczone jako szkic bez kodu (FACEBOOK_LOGIN_URUCHOMIENIE.md §9.4)',
        '/facebook/usun-dane/{kod}' => 'jawnie oznaczone jako szkic bez kodu (FACEBOOK_LOGIN_URUCHOMIENIE.md §9.4)',
        '/legal/' => 'cytat BŁĘDNEGO wzorca w zdaniu, które mówi wprost, że taki prefiks nie istnieje',
        '/polityka-prywatnosci' => 'cytat wewnątrz zdania ostrzegającego „nie /polityka-prywatnosci" (FACEBOOK_LOGIN_URUCHOMIENIE.md)',
        '/przepisy' => 'przykład stylu adresu (AGENTS.md, SKILL.md) — prawdziwa trasa ma parametr',
        '/przepisy/' => 'przykład stylu adresu — prawdziwa trasa ma parametr',
        '/przepisy/{fork}/oryginal' => 'ekran zaproponowany w projekcie #23 (docs/product/MOJA_WERSJA_PROJEKT_23.md), decyzja właściciela nie zapadła — trasy nie ma i nie ma jej być przed tą decyzją',
        '/przepisy/{oryginal}/moja-wersja' => 'tryb tworzenia zaproponowany w projekcie #23 (docs/product/MOJA_WERSJA_PROJEKT_23.md), decyzja właściciela nie zapadła — trasy nie ma i nie ma jej być przed tą decyzją',
        '/pytania' => 'dział jawnie opisany jako jeszcze niezbudowany, issue #372 (BRAND_EXTENDED.md)',
        '/tag' => 'nieformalne odwołanie do prefiksu tras tagów',
        '/tag/przetwory' => 'realny wzorzec tag/{tag} z przykładową wartością (AUDYT_COLD_START_29_2026-09-20.md)',
        '/tag/zupa' => 'realny wzorzec tag/{tag} z przykładową wartością',
        '/tag/zupy' => 'realny wzorzec tag/{tag} z przykładową wartością',
        '/temat/{slug}' => 'propozycja z dokumentu decyzyjnego, nie zbudowana trasa',
        '/woogitsu-run' => 'nazwa katalogu self-hosted runnera, nie trasa serwisu',
        '/wpisy/9f1c' => 'fikcyjne ID w przykładzie logu (MONITORING_BLEDOW.md)',
        '/zglos' => 'nieformalny skrót realnej trasy zglos/{type}/{id}',
        '/zglos/' => 'nieformalny skrót realnej trasy zglos/{type}/{id}',
        '/linux.sh' => 'nazwa skryptu runnera w HANDOVER.md',
        '/woogitsu-run-NN/' => 'szablon nazwy katalogu runnera w HANDOVER.md',
        '/{user-id}/permissions' => 'endpoint zewnętrznego Graph API Facebooka',
        '/zglos/...' => 'jawny skrót wzorca zgłoszenia w MODERATION.md',
        '/przepisy/...' => 'jawny skrót adresu przepisu w polityce prywatności',
        '/wpisy/9f1c.../zdjecia' => 'jawnie skrócone przykładowe ID w logu MONITORING_BLEDOW.md',
        '/zipball/' => 'termin narzędziowy (artefakt API GitHuba), nie trasa',
    ];

    /**
     * Pierwszy segment, po którym coś ODRUCHOWO wygląda jak `/trasa`, a jest
     * ścieżką systemową (`/usr/local/bin`), katalogiem repo (`/scripts/x.sh`
     * urwanym na kropce) albo poleceniem Claude Code (`/loop`) — nigdy
     * adresem tego serwisu. Ciche pominięcie: to nie są nasze trasy i nie
     * mają nic wspólnego z audytem z 12 września, więc nie proszą się
     * o wpis w `DOZWOLONE_NIE_TRASY` z uzasadnieniem.
     */
    private const PIERWSZY_SEGMENT_NIE_JEST_TRASA = [
        'usr', 'etc', 'var', 'opt', 'tmp', 'mnt', 'dev', 'root', 'home', 'svc',
        'node_modules', 'vendor', 'build', 'workspace', 'scratchpad', 'app',
        'public', 'assets', 'fonts', 'icons', 'media', 'database', 'resources',
        'scripts', 'docs', 'decyzje', 'config', 'tests', 'research',
        '_work', '_temp', '.npm', '.cache', 'actions-runner-kuking-03',
        'setup-php', 'linux', 'livewire', 'favicon', 'manifest', 'incoming',
        // katalog domowy Windows w cytowanej ścieżce lokalnej (worktree,
        // klon repozytorium), nigdy adres tego serwisu
        'Users',
        // polecenia/skille Claude Code, cytowane w dokumentacji jak trasy
        'code-review', 'simplify', 'security-review', 'fewer-permission-prompts',
        'loop', 'init', 'run', 'permissions', 'slack',
        // adresy API zewnętrznych usług (OpenAI, OAuth), nie naszego serwisu
        'v1', 'auth',
    ];

    private const NIE_TRASA_TAGI_HTML = ['div', 'span', 'button', 'header', 'h1', 'strong', 'svg', 'title', 'a', 'b'];

    /** @return list<string> */
    private function wszystkiePlikiMd(): array
    {
        $korzen = base_path();
        $wynik = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($korzen, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $plik) {
            if (! $plik->isFile() || $plik->getExtension() !== 'md') {
                continue;
            }

            $wzgledna = ltrim(str_replace($korzen, '', $plik->getPathname()), '/');

            if (str_starts_with($wzgledna, 'vendor/') || str_starts_with($wzgledna, 'node_modules/')) {
                continue;
            }

            $wynik[] = $wzgledna;
        }

        sort($wynik);

        return $wynik;
    }

    /**
     * Slug w stylu GitHuba — z tekstu nagłówka na identyfikator kotwicy.
     */
    private function slugGfm(string $tekst): string
    {
        $tekst = preg_replace('/`([^`]*)`/', '$1', $tekst) ?? $tekst;
        $tekst = preg_replace('/\*\*([^*]*)\*\*/', '$1', $tekst) ?? $tekst;
        $tekst = preg_replace('/\*([^*]*)\*/', '$1', $tekst) ?? $tekst;
        $tekst = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $tekst) ?? $tekst;
        $tekst = mb_strtolower(trim($tekst));
        $tekst = str_replace(' ', '-', $tekst);

        // GFM: zostaw litery unicode, cyfry, myślniki i podkreślenia.
        $wynik = '';
        foreach (mb_str_split($tekst) as $znak) {
            if ($znak === '-' || $znak === '_' || preg_match('/\p{L}|\p{N}/u', $znak)) {
                $wynik .= $znak;
            }
        }

        return $wynik;
    }

    /** @return array<string, true> zbiór dostępnych slugów kotwic w pliku */
    private function kotwicePliku(string $sciezkaWzgledna): array
    {
        $pelna = base_path($sciezkaWzgledna);

        if (! is_file($pelna)) {
            return [];
        }

        $tresc = (string) file_get_contents($pelna);
        $tresc = preg_replace('/```.*?```/s', '', $tresc) ?? $tresc;

        preg_match_all('/^(#{1,6})\s+(.+)$/m', $tresc, $trafienia, PREG_SET_ORDER);

        $liczniki = [];
        $sluggi = [];

        foreach ($trafienia as $t) {
            $bazowy = $this->slugGfm($t[2]);
            $n = $liczniki[$bazowy] ?? 0;
            $liczniki[$bazowy] = $n + 1;
            $sluggi[$n === 0 ? $bazowy : "{$bazowy}-{$n}"] = true;
        }

        return $sluggi;
    }

    #[Test]
    public function test_odnosniki_markdown_prowadza_do_istniejacych_celow(): void
    {
        $wzorzecLinku = '/\[([^\]]*)\]\(([^)\s]+)\)/';
        $realneTrasy = $this->realneTrasyZnormalizowane();
        $sprawdzone = 0;
        $martwe = [];

        foreach ($this->wszystkiePlikiMd() as $plik) {
            $tresc = (string) file_get_contents(base_path($plik));

            preg_match_all($wzorzecLinku, $tresc, $trafienia, PREG_SET_ORDER);

            foreach ($trafienia as $t) {
                $cel = $t[2];

                if (preg_match('#^(https?://|mailto:|ftp://)#', $cel)) {
                    continue; // zewnętrzne — poza zakresem tego testu
                }

                // Standardowy wzorzec odnośnika do issue na GitHubie, liczony
                // względem ścieżki pliku w serwisie www (`../../issues/N`) —
                // nie jest lokalną ścieżką pliku.
                if (preg_match('#^\.\./\.\./issues/\d+$#', $cel)) {
                    continue;
                }

                // Odnośnik zaczynający się od "/" wskazuje trasę serwisu
                // (`[polityka prywatności](/prywatnosc)`), nie plik repo —
                // dokładnie tak wyglądał odnośnik z audytu z 12 września.
                if (str_starts_with($cel, '/')) {
                    $sprawdzone++;

                    if (! isset($realneTrasy[$this->znormalizujTrase($cel)])) {
                        $martwe[] = "{$plik}: [{$t[1]}]({$cel}) -> nie ma takiej trasy";
                    }

                    continue;
                }

                [$sciezkaCzesc, $kotwica] = str_contains($cel, '#')
                    ? explode('#', $cel, 2)
                    : [$cel, null];

                $sciezkaCzesc = strtok($sciezkaCzesc, '?') ?: $sciezkaCzesc; // odetnij ?query

                if ($sciezkaCzesc === '') {
                    // sam #kotwica — cel to ten sam plik
                    $docelowyPlik = $plik;
                } else {
                    $docelowyPlik = Str::of(dirname($plik).'/'.$sciezkaCzesc)
                        ->replace('\\', '/')
                        ->toString();
                    $docelowyPlik = $this->znormalizujSciezke($docelowyPlik);
                }

                $sprawdzone++;

                $pelna = base_path($docelowyPlik);

                if (! is_file($pelna) && ! is_dir($pelna)) {
                    $martwe[] = "{$plik}: [{$t[1]}]({$cel}) -> brak pliku „{$docelowyPlik}”";

                    continue;
                }

                if ($kotwica !== null && is_file($pelna) && str_ends_with($docelowyPlik, '.md')) {
                    $dostepne = $this->kotwicePliku($docelowyPlik);

                    if (! isset($dostepne[$kotwica])) {
                        $martwe[] = "{$plik}: [{$t[1]}]({$cel}) -> w „{$docelowyPlik}” nie ma nagłówka dla kotwicy #{$kotwica}";
                    }
                }
            }
        }

        // Kontrola ujemna (pułapka 2, docs/PULAPKI_TESTOW.md): skan, który
        // niczego nie znalazł, nie ma prawa wyglądać jak sukces.
        $this->assertGreaterThan(
            100,
            $sprawdzone,
            'Sprawdzono podejrzanie mało odnośników markdown — zła ścieżka skanowania?',
        );

        $this->assertSame([], $martwe, "Martwe odnośniki markdown:\n".implode("\n", $martwe));
    }

    #[Test]
    public function test_trasy_wspominane_w_dokumentach_naprawde_istnieja(): void
    {
        $realneTrasy = $this->realneTrasyZnormalizowane();

        $this->assertGreaterThan(
            50,
            count($realneTrasy),
            '`Route::getRoutes()` zwróciło podejrzanie mało tras — routes/web.php nie załadowany?',
        );

        $wzorzecBacktick = '/`([^`\n]+)`/';

        $sprawdzone = 0;
        $martwe = [];

        foreach ($this->wszystkiePlikiMd() as $plik) {
            if ($this->wykluczonyZTestuTras($plik)) {
                continue;
            }

            $tresc = (string) file_get_contents(base_path($plik));
            $tresc = preg_replace('/```.*?```/s', '', $tresc) ?? $tresc;

            preg_match_all($wzorzecBacktick, $tresc, $backtickTrafienia);

            foreach ($backtickTrafienia[1] as $zawartosc) {
                foreach ($this->trasyZFragmentu($zawartosc) as $trasa) {
                    if ($this->wygladaNaCosInnegoNizTrase($trasa)) {
                        continue;
                    }

                    if (isset(self::DOZWOLONE_NIE_TRASY[$trasa])) {
                        continue;
                    }

                    $sprawdzone++;

                    if (! isset($realneTrasy[$this->znormalizujTrase($trasa)])) {
                        $martwe[] = "{$plik}: `{$trasa}`";
                    }
                }
            }
        }

        $this->assertGreaterThan(
            80,
            $sprawdzone,
            'Sprawdzono podejrzanie mało tras w dokumentach — zła ścieżka skanowania?',
        );

        $this->assertSame([], $martwe, "Martwe trasy wspomniane w dokumentach:\n".implode("\n", $martwe));
    }

    /** @return list<string> */
    private function trasyZFragmentu(string $fragment): array
    {
        // Nie zaczynaj od sufiksu po @parametrze ani nie urywaj na rozszerzeniu.
        preg_match_all('#(?<![\w/.@{}\-])(/(?:[\p{L}\p{N}_@.\-]|\{[^}\s/]+\})+(?:/(?:[\p{L}\p{N}_@.\-]|\{[^}\s/]+\})+)*/?\*?)(?![\w/@{}\-])#u', $fragment, $trafienia);

        return $trafienia[1];
    }

    #[Test]
    public function test_parser_zachowuje_parametry_rozszerzenia_i_odrzuca_falszywe_trasy(): void
    {
        $realne = $this->realneTrasyZnormalizowane();
        foreach ([
            '/@{username}', '/@{nazwa}/obserwowani', '/@{username}/obserwujacy',
            '/robots.txt', '/sitemap.xml', '/zgloszenie/{zgłoszenie}/odwolanie',
            '/livewire-01234567/css/{component}.css',
            '/livewire-89abcdef/css/{component}.global.css',
            '/livewire-01234567/js/{component}.js',
            '/livewire-01234567/livewire.csp.min.js.map',
            '/livewire-01234567/livewire.js',
            '/livewire-01234567/livewire.min.js',
        ] as $trasa) {
            $this->assertSame([$trasa], $this->trasyZFragmentu('GET '.$trasa), $trasa);
            $this->assertArrayHasKey($this->znormalizujTrase($trasa), $realne, $trasa);
        }

        foreach ([
            '/@{username}/nieistniejaca-trasa', '/sitemap.nieistnieje', '/robots',
            '/livewire-01234567/css/{component}.nieistnieje',
            '/livewire-01234567/nieistniejaca-trasa',
            '/livewire-niehash/livewire.js', '/livewire-012345678/livewire.js',
            '/inny-01234567/livewire.js',
            '/livewire-01234567/livewire.nieistnieje.js',
            '/livewire-01234567/livewire.min.js/nieistnieje',
        ] as $trasa) {
            $this->assertSame([$trasa], $this->trasyZFragmentu($trasa), $trasa);
            $this->assertArrayNotHasKey($this->znormalizujTrase($trasa), $realne, $trasa);
        }
    }

    private function wygladaNaCosInnegoNizTrase(string $trasa): bool
    {
        // Wzorzec ścieżki z gwiazdką (`/zdjecia/*` jako Cache Rule w
        // Cloudflare, `location` w nginx) opisuje ZBIÓR adresów, a nie
        // pojedynczą trasę Laravela — jego prefiks nie musi sam być trasą.
        // Prawdziwy adres zdjęć to `zdjecia/{id}/{wariant}`, więc `/zdjecia/*`
        // jest w dokumencie poprawne. Do 22 września 2026 parser gubił
        // gwiazdkę i zgłaszał `/zdjecia/` jako martwą trasę.
        if (str_ends_with($trasa, '*')) {
            return true;
        }

        $bezSlashy = trim($trasa, '/');

        if ($bezSlashy === '' || mb_strlen($bezSlashy) <= 2) {
            return true;
        }

        $pierwszySegment = explode('/', $bezSlashy)[0];

        if (in_array($pierwszySegment, self::PIERWSZY_SEGMENT_NIE_JEST_TRASA, true)) {
            return true;
        }

        return in_array($bezSlashy, self::NIE_TRASA_TAGI_HTML, true);
    }

    private function wykluczonyZTestuTras(string $plik): bool
    {
        if (in_array($plik, self::WYKLUCZONE_Z_TRAS_PLIKI, true)) {
            return true;
        }

        foreach (self::WYKLUCZONE_Z_TRAS_PREFIKSY as $prefiks) {
            if (str_starts_with($plik, $prefiks)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function realneTrasyZnormalizowane(): array
    {
        $wynik = ['' => true];

        // Metoda HTTP celowo nie zawęża wyniku: dokumenty odwołują się też do
        // tras POST-only (np. `/logout`, `/wejdz/facebook/odebranie-dostepu`,
        // `/ustawienia/twoje-dane/eksport`) — trasa „nie istnieje" oznacza
        // tu 404 dla KAŻDEJ metody, nie tylko brak GET-a.
        foreach (Route::getRoutes() as $trasa) {
            $wynik[$this->znormalizujTrase('/'.$trasa->uri())] = true;
        }

        // Zasoby publiczne obsługiwane przez serwer, bez wpisu w routerze.
        foreach (['sw.js', 'favicon.ico', 'manifest.webmanifest'] as $plik) {
            if (is_file(public_path($plik))) {
                $wynik[$plik] = true;
            }
        }

        return $wynik;
    }

    private function znormalizujTrase(string $trasa): string
    {
        $trasa = trim($trasa, '/');
        $trasa = preg_replace('#^@[^/{]+(?=/|$)#', '@{username}', $trasa) ?? $trasa;

        if ($trasa === '') {
            return '';
        }

        // EndpointResolver: pierwsze 8 cyfr hex SHA-256(app.key + livewire-endpoint).
        // Prefiks zależy od instalacji, a minifikacja głównego skryptu od app.debug.
        // Nie normalizujemy innych plików ani dodatkowych segmentów ścieżki.
        $trasa = preg_replace('#^(livewire-[a-f0-9]{8}/livewire)\.min\.js$#', '$1.js', $trasa) ?? $trasa;
        $trasa = preg_replace('#^livewire-[a-f0-9]{8}/#', 'livewire-{instalacja}/', $trasa) ?? $trasa;

        return preg_replace('/\{[^}]+\}/u', '{}', $trasa) ?? $trasa;
    }

    private function znormalizujSciezke(string $sciezka): string
    {
        $czesci = [];

        foreach (explode('/', $sciezka) as $czesc) {
            if ($czesc === '' || $czesc === '.') {
                continue;
            }

            if ($czesc === '..') {
                array_pop($czesci);

                continue;
            }

            $czesci[] = $czesc;
        }

        return implode('/', $czesci);
    }
}
