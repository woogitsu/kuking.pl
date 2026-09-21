<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit (issue #66): wylicza nazwę testowej bazy danych, zanim
 * Laravel w ogóle zacznie czytać zmienne środowiskowe.
 *
 * Co się działo bez tego: `phpunit.xml` ustawiał DB_DATABASE na sztywno
 * "kuking_test". Kiedy dwa przebiegi `php artisan test` chodziły naraz na
 * tej samej bazie (np. główny katalog + worktree agenta), `RefreshDatabase`
 * jednego z nich kasowało schemat w trakcie działania drugiego — testy
 * padały z "relation ... does not exist", mimo że w kodzie nie było
 * żadnego błędu. Dokładnie to zdarzyło się przy pracy nad tym repozytorium.
 *
 * Rozwiązanie: nazwa bazy zależy od KATALOGU ROBOCZEGO — a konkretnie od
 * tego, czy repozytorium jest głównym checkoutem, czy osobnym
 * `git worktree`. Każdy worktree ma własny, stały katalog na dysku (i Git
 * sam nadaje mu unikalną nazwę w `.git/worktrees/<nazwa>`), więc dwa
 * równoległe przebiegi w różnych katalogach naturalnie trafiają w różne
 * bazy — bez ustawiania czegokolwiek ręcznie. Domyślne `php artisan test`
 * w głównym katalogu dalej idzie na "kuking_test", tak jak dziś.
 *
 * Ręczne ustawienie DB_DATABASE (shell, CI) ma ZAWSZE pierwszeństwo — ten
 * plik nie nadpisuje zmiennej, która już jest ustawiona (patrz job `test`
 * w .github/workflows/ci.yml, który ustawia DB_DATABASE=kuking_test wprost
 * na poziomie joba — tam przebieg jest jeden, więc nic się tu nie zmienia).
 *
 * Czego świadomie NIE wybrano i dlaczego:
 *
 *  - PID procesu. Dawałby nową, inną bazę przy KAŻDYM uruchomieniu — także
 *    kolejnych w tym samym katalogu, jedno po drugim. To usuwa kolizję
 *    kosztem gwarantowanego zaśmiecania dysku: baza nigdy nie jest ta sama,
 *    więc nigdy nie ma jednej, powtarzalnej rzeczy do posprzątania ani do
 *    ponownego użycia (cache migracji, dane testowe do inspekcji).
 *
 *  - Zmienna z CI (np. GITHUB_RUN_ID). Działa wyłącznie w CI, a w CI
 *    przebieg i tak jest jeden (workflow ma `concurrency`, patrz ci.yml) —
 *    czyli rozwiązywałaby problem tam, gdzie go nie ma, i nie rozwiązywałaby
 *    go lokalnie, gdzie jest to issue.
 *
 *  - Losowy UUID przy starcie. Maksymalna izolacja, ale baza nie ma żadnej
 *    stałej, rozpoznawalnej nazwy — nie da się jej odróżnić od śmiecia bez
 *    dodatkowego rejestru. Nazwa worktree jest czytelna w `psql -l` za darmo
 *    i widać po niej, do czego baza należy.
 *
 *  - Hash pełnej ścieżki repozytorium JAKO REGUŁA GŁÓWNA. Działałby równie
 *    dobrze jak nazwa worktree, ale jest nieczytelny przy sprzątaniu
 *    (`psql -l` pokazuje ciąg hexów, nie to, o który worktree chodzi). Git
 *    już nadaje worktree'om czytelne, unikalne nazwy — nie ma sensu liczyć
 *    własnego hashu obok.
 *
 *    Uwaga: skrót ścieżki wchodzi mimo to jako REGUŁA OSTATNIA, dla drzew
 *    skopiowanych poza Gitem (runtime'y testowe floty: `rsync` bez `.git`),
 *    bo tam Gita nie ma i nie ma kogo zapytać o nazwę. Nazwa niesie wtedy
 *    obie części — czytelną nazwę katalogu i skrót — więc zarzut
 *    nieczytelności jej nie dotyczy. Szczegóły przy
 *    `kuking_nazwa_testowej_bazy()` niżej.
 *
 * Sprzątanie: `scripts/cleanup-test-dbs.sh` usuwa bazy `kuking_test_*`,
 * których worktree już nie istnieje na dysku (czyli został usunięty przez
 * `git worktree remove`, a baza po nim została).
 */
if (getenv('DB_DATABASE') === false || getenv('DB_DATABASE') === '') {
    putenv('DB_DATABASE='.kuking_nazwa_testowej_bazy(__DIR__.'/..'));
}

require __DIR__.'/../vendor/autoload.php';

/*
 * KLASY TEŻ MUSZĄ POCHODZIĆ Z TEGO KATALOGU, NIE TYLKO TRASY.
 *
 * `AGENTS.md` §10 mówi, żeby w worktree uruchamiać testy z `APP_BASE_PATH`,
 * „inaczej Laravel załaduje trasy i klasy z głównego katalogu, a testy będą
 * fałszywie zielone". Pierwsza połowa tego zdania działa. DRUGA NIE DZIAŁAŁA
 * i to jest dokładnie ten rodzaj obietnicy bez pokrycia w kodzie, przed
 * którym ostrzega cała reszta tego repozytorium.
 *
 * Powód: `vendor/` w worktree jest DOWIĄZANIEM do głównego katalogu, a PHP
 * rozwija dowiązania w `__DIR__`. Autoloader Composera liczy więc swój
 * `$baseDir` od PRAWDZIWEGO położenia pliku, czyli od głównego checkoutu —
 * i mapuje `App\` na `app/` głównego katalogu, niezależnie od tego, skąd
 * uruchomiono testy i co stoi w `APP_BASE_PATH` (ten zmienia ścieżkę bazową
 * Laravela, nie autoloadera). `composer.json` ma przy tym
 * `optimize-autoloader`, więc jest to zamrożona mapa ścieżek bezwzględnych.
 *
 * Skutek był najgorszy z możliwych: PHPUnit ładował PLIKI TESTÓW z worktree
 * (bo bierze je ze ścieżki), ale KLASY APLIKACJI z głównego katalogu. Testy
 * przechodziły, sprawdzając cudzy kod. Agent widział 891 zielonych testów
 * i nie miał żadnego sygnału, że jego zmiany w `app/` nie zostały nawet raz
 * wykonane.
 *
 * Rejestrujemy więc własny autoloader PRZED composerowym, z mapami PSR-4
 * przeczytanymi z `composer.json` tego katalogu. W zwykłym checkoucie ta
 * funkcja nie robi nic (warunek niżej), więc CI i główny katalog zachowują
 * się bez zmian. Zero zmian w `vendor/`, czyli zero ryzyka dla innych
 * agentów pracujących równolegle.
 */
kuking_klasy_z_tego_katalogu(__DIR__.'/..');

/**
 * Rejestruje autoloader PSR-4 wskazujący na TEN katalog repozytorium.
 *
 * No-op wszędzie tam, gdzie `vendor/` leży naprawdę w tym katalogu — czyli
 * w głównym checkoucie i w CI.
 */
function kuking_klasy_z_tego_katalogu(string $katalogRepo): void
{
    $repo = realpath($katalogRepo);
    $autoloadComposera = realpath($katalogRepo.'/vendor/autoload.php');

    if ($repo === false || $autoloadComposera === false) {
        return;
    }

    // Katalog, od którego autoloader Composera liczy swoje ścieżki.
    $repoComposera = dirname($autoloadComposera, 2);

    if ($repoComposera === $repo) {
        return;
    }

    $composerJson = @file_get_contents($repo.'/composer.json');

    if ($composerJson === false) {
        return;
    }

    /** @var array{autoload?: array{'psr-4'?: array<string, string>}, 'autoload-dev'?: array{'psr-4'?: array<string, string>}} $manifest */
    $manifest = json_decode($composerJson, true) ?: [];

    $mapy = array_merge(
        $manifest['autoload']['psr-4'] ?? [],
        $manifest['autoload-dev']['psr-4'] ?? [],
    );

    if ($mapy === []) {
        return;
    }

    spl_autoload_register(function (string $klasa) use ($repo, $mapy): void {
        foreach ($mapy as $prefiks => $katalog) {
            if (! str_starts_with($klasa, $prefiks)) {
                continue;
            }

            $sciezka = $repo.'/'.trim($katalog, '/').'/'
                .str_replace('\\', '/', substr($klasa, strlen($prefiks))).'.php';

            if (is_file($sciezka)) {
                require_once $sciezka;

                return;
            }
        }
    }, prepend: true);
}

/**
 * Zwraca nazwę bazy dla grupy testów `dwa-polaczenia` (D-105): "kuking_race"
 * dla głównego checkoutu, "kuking_race_<worktree>" dla `git worktree`.
 *
 * Ta grupa NIE MOŻE chodzić na `kuking_test*` i nie jest to ostrożność na
 * wyrost. Testy na dwóch połączeniach zatwierdzają dane naprawdę (bez
 * `RefreshDatabase`), więc żyją obok zwykłego przebiegu — a zwykły przebieg
 * na tej samej bazie zrzuciłby im schemat w trakcie działania. To dokładnie
 * issue #66, tylko z drugiej strony: tam kolidowały dwa zwykłe przebiegi,
 * tutaj kolidowałby zwykły z wyścigowym.
 *
 * Sufiks liczy `kuking_nazwa_testowej_bazy()` — świadomie TA SAMA metoda,
 * żeby nie było w repozytorium dwóch reguł nazywania baz, które mogą się
 * rozjechać. Zmiana tamtej funkcji przenosi się tutaj sama.
 *
 * Ta funkcja NICZEGO nie ustawia w środowisku: `DB_DATABASE` dla zwykłego
 * przebiegu liczy się wyżej i pozostaje nietknięte. Nazwę bazy wyścigów
 * bierze `Tests\Dwa\TestDwochPolaczen::setUp()` oraz
 * `scripts/testy-dwa-polaczenia.sh`.
 */
function kuking_nazwa_bazy_wyscigow(string $katalogRepo): string
{
    return 'kuking_race'.substr(kuking_nazwa_testowej_bazy($katalogRepo), strlen('kuking_test'));
}

/**
 * Zwraca nazwę bazy POMIAROWEJ dla próby wycofania migracji
 * (`scripts/proba-wycofania.sh`): "proba_wycofania" w głównym checkoucie,
 * "proba_wycofania_<worktree>" w `git worktree`.
 *
 * Prefiks jest inny niż `kuking_*` i to jest jego jedyne zadanie. Skrypt
 * próby wycofania KASUJE swoją bazę i zrzuca w niej schemat do zera — czyli
 * robi dokładnie to, czego nie wolno zrobić nigdzie indziej. Jego bezpiecznik
 * przepuszcza wyłącznie nazwy pasujące do `proba_wycofania*`, więc `kuking`,
 * `kuking_test*`, `kuking_race*` ani `railway` nie wpadną do niego nawet
 * przy literówce: to nie jest różnica jednego znaku.
 *
 * Sufiks liczy `kuking_nazwa_testowej_bazy()` — świadomie TA SAMA metoda, co
 * przy bazie testowej i wyścigowej, żeby nie było w repozytorium trzech reguł
 * nazywania baz, które rozjadą się przy pierwszej zmianie.
 */
function kuking_nazwa_bazy_wycofania(string $katalogRepo): string
{
    return 'proba_wycofania'.substr(kuking_nazwa_testowej_bazy($katalogRepo), strlen('kuking_test'));
}

/**
 * Zwraca nazwę testowej bazy dla danego katalogu repozytorium:
 *
 *  - "kuking_test" dla GŁÓWNEGO CHECKOUTU (`.git` jest katalogiem),
 *  - "kuking_test_<worktree>" dla `git worktree` (`.git` jest plikiem-wskaźnikiem),
 *  - "kuking_test_kopia_<katalog>_<skrót ścieżki>" dla KOPII DRZEWA BEZ `.git`.
 *
 * TRZECI PRZYPADEK JEST NOWY I TO ON BYŁ USTERKĄ. Runtime'y testowe floty
 * powstają przez `rsync --exclude '.git'` (`_wspolne/przygotuj-runtime.sh`),
 * więc w skopiowanym drzewie nie ma ani katalogu `.git`, ani wskaźnika
 * worktree. Do dziś funkcja zwracała wtedy gołe "kuking_test" dla KAŻDEGO
 * stanowiska naraz: dziesięć równoległych runtime'ów mieliło jedną bazę,
 * `RefreshDatabase` jednego zrzucał schemat drugiemu i sypało to losową
 * czerwienią wyglądającą jak regresja gałęzi. Czyli dokładnie issue #66,
 * tylko że przeniesione o jeden poziom dalej — z worktree'ów na ich kopie.
 *
 * PO CZYM ODRÓŻNIAMY KOPIĘ OD GŁÓWNEGO CHECKOUTU — I DLACZEGO TAK
 *
 * Po `.git`, nie po ścieżce. Trzy stany są rozłączne i wyczerpujące:
 * katalog `.git` = główny checkout, plik `.git` = worktree, brak `.git` =
 * drzewo, które ktoś SKOPIOWAŁ. Tylko kopie mogą istnieć w wielu
 * egzemplarzach naraz bez wiedzy Gita, więc tylko one potrzebują sufiksu,
 * którego Git nie umie im nadać.
 *
 * Świadomie NIE po ścieżce (np. "czy katalog kończy się na -run", „czy leży
 * w /home/mateusz/flota"). Taki warunek wpisałby układ katalogów jednej
 * maszyny floty do repozytorium produktu: przetrwałby dokładnie do pierwszej
 * zmiany nazewnictwa runtime'ów i milczałby przy niej, bo nierozpoznana
 * ścieżka to znowu gołe "kuking_test" — czyli powrót usterki bez żadnego
 * objawu poza losową czerwienią. Pytanie „czy to kopia" ma odpowiedź w samym
 * drzewie i tam po nią sięgamy.
 *
 * CO SIĘ STANIE, GDY KTOŚ SKOPIUJE RUNTIME W INNE MIEJSCE
 *
 * Dostanie INNĄ nazwę bazy niż oryginał, bo sufiks liczy się ze ścieżki.
 * To jest zachowanie zamierzone, nie skutek uboczny: dwie kopie w dwóch
 * katalogach to dwa drzewa, które mogą chodzić równolegle, więc muszą mieć
 * dwie bazy. Cena jest jedna i trzeba ją znać: PRZENIESIENIE (a nie
 * skopiowanie) runtime'u zostawia po starej ścieżce bazę-sierotę, a nowa
 * ścieżka zaczyna od pustej bazy — pierwszy przebieg po przeprowadzce
 * odtwarza migracje od zera i trwa dłużej. Sierotę widać w `psql -l` po
 * przedrostku "kuking_test_kopia_" i po czytelnej nazwie katalogu w środku;
 * `scripts/cleanup-test-dbs.sh` świadomie ICH NIE KASUJE (patrz komentarz
 * tam), bo z maszyny sprzątającej nie da się stwierdzić, czy cudza kopia
 * jeszcze żyje — a skasowanie bazy pracującego runtime'u byłoby gorsze niż
 * zostawienie śmiecia.
 *
 * Skrót ścieżki, a nie sama nazwa katalogu: dwa runtime'y mogą nazywać się
 * tak samo w różnych miejscach (`/a/kuking-run` i `/b/kuking-run`).
 * Nagłówek tego pliku odrzucał kiedyś „hash pełnej ścieżki" jako nieczytelny
 * w `psql -l` — i miał rację, dlatego nazwa niesie OBIE części: czytelną
 * nazwę katalogu do rozpoznania oraz skrót do rozróżnienia.
 */
function kuking_nazwa_testowej_bazy(string $katalogRepo): string
{
    $domyslna = 'kuking_test';

    $wskaznikGit = $katalogRepo.'/.git';

    // Główny checkout: `.git` to katalog ze schematem repo — nazwa bez
    // sufiksu i tak ma zostać, bo główny checkout jest jeden.
    if (is_dir($wskaznikGit)) {
        return $domyslna;
    }

    // Ani katalog, ani plik `.git` — drzewo skopiowane poza Gitem.
    if (! is_file($wskaznikGit)) {
        return $domyslna.'_'.kuking_sufiks_kopii_drzewa($katalogRepo);
    }

    $tresc = file_get_contents($wskaznikGit);
    if ($tresc === false || ! preg_match('/gitdir:\s*(\S+)/', $tresc, $dopasowanie)) {
        return $domyslna;
    }

    // Format wskaźnika worktree: "gitdir: <repo>/.git/worktrees/<nazwa>".
    if (! preg_match('#/\.git/worktrees/([^/]+)/?$#', trim($dopasowanie[1]), $nazwaWorktree)) {
        return $domyslna;
    }

    // Nazwa katalogu worktree bywa dłuższa niż limit identyfikatora
    // Postgresa (63 znaki) po doliczeniu prefiksu "kuking_test_" — a znaki
    // spoza [a-zA-Z0-9_] wymagałyby cudzysłowu w SQL. Obcinamy i czyścimy,
    // zamiast zakładać, że Git zawsze nada bezpieczną nazwę.
    $sufiks = preg_replace('/[^a-zA-Z0-9_]/', '_', $nazwaWorktree[1]);
    $sufiks = substr((string) $sufiks, 0, 50);

    return $domyslna.'_'.$sufiks;
}

/**
 * Sufiks dla drzewa skopiowanego poza Gitem (runtime testowy): czytelna
 * nazwa katalogu plus ośmioznakowy skrót jego pełnej, rozwiniętej ścieżki.
 *
 * Wymagania, które ten sufiks ma spełniać, i skąd się biorą:
 *
 *  1. RÓŻNE dla różnych katalogów — bo o to w całej poprawce chodzi.
 *  2. TEN SAM przy każdym przebiegu z tego samego katalogu. Sufiks liczony
 *     z PID-u albo znacznika czasu też usunąłby kolizję, ale każdy przebieg
 *     zakładałby nową bazę i klaster zarastałby śmieciem w tempie jednego
 *     przebiegu; tu wejściem jest wyłącznie ścieżka, która się nie zmienia.
 *  3. BEZPIECZNY jako identyfikator Postgresa bez cudzysłowu. Postgres tnie
 *     identyfikatory po 63 bajtach po cichu, a znak spoza [a-zA-Z0-9_]
 *     wymagałby cudzysłowów w każdym miejscu, które sklepuje SQL ze
 *     stringów (`proba-odtworzenia.sh`, `cleanup-test-dbs.sh`). Stąd i
 *     czyszczenie znaków, i twardy limit długości.
 *
 * Budżet długości liczymy od NAJDŁUŻSZEGO przedrostka, jaki ten sufiks
 * dostaje w repozytorium — "proba_wycofania_" (16 znaków,
 * `kuking_nazwa_bazy_wycofania()`), nie od "kuking_test_" (12). 16 + 39 = 55,
 * czyli z zapasem pod limit 63.
 *
 * `realpath()` normalizuje ścieżkę (dowiązania, "..", końcowy ukośnik), żeby
 * to samo drzewo osiągnięte dwiema zapisami ścieżki dostało jedną bazę —
 * `tests/bootstrap.php` woła tę funkcję z `__DIR__.'/..'`, a skrypty
 * powłoki z gołej ścieżki katalogu. Gdy `realpath()` zawiedzie (katalog
 * zniknął w trakcie), zostaje ścieżka podana — gorzej znormalizowana, ale
 * wciąż stabilna, bo pochodzi od wołającego.
 */
function kuking_sufiks_kopii_drzewa(string $katalogRepo): string
{
    $sciezka = realpath($katalogRepo);
    if ($sciezka === false) {
        $sciezka = rtrim(str_replace('\\', '/', $katalogRepo), '/');
    } else {
        $sciezka = rtrim(str_replace('\\', '/', $sciezka), '/');
    }

    $skrot = substr(sha1($sciezka), 0, 8);

    $nazwaKatalogu = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', basename($sciezka));
    $nazwaKatalogu = trim(substr($nazwaKatalogu, 0, 24), '_');

    // Katalog o nazwie złożonej wyłącznie ze znaków niebezpiecznych zostawia
    // pusty człon czytelny — wtedy zostaje sam skrót, który nadal rozróżnia.
    if ($nazwaKatalogu === '') {
        return 'kopia_'.$skrot;
    }

    return 'kopia_'.$nazwaKatalogu.'_'.$skrot;
}
