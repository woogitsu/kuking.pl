<?php

declare(strict_types=1);

/**
 * Bezpiecznik: PHPUnit odmawia startu na bazie spoza rodziny testowej (D-334).
 *
 * PO CO. `RefreshDatabase` na początku przebiegu zrzuca cały schemat bazy, na
 * którą wskazuje połączenie. Wystarczy `export DB_DATABASE=kuking` w powłoce
 * (albo `.env` skopiowany z bazą deweloperską i ominięta nazwa wyliczana przez
 * `tests/bootstrap.php`), żeby pierwszy `php artisan test` skasował dane, na
 * których ktoś pracuje — a przy `DB_URL` z produkcji, żeby zrobił to samo
 * gorzej. Nic w kodzie testów tego nie wyłapywało: nazwę bazy testowej
 * WYLICZAMY (issue #66), ale wyliczoną nazwę wystarczy jedną zmienną
 * przesłonić, a wtedy nikt jej już nie sprawdza.
 *
 * KIERUNEK POMYŁKI. To lista ZGÓD, nie zakazów: nazwa nierozpoznana jest
 * odmową. Odmowa kosztuje jedno polecenie z inną nazwą bazy, zgoda na cudzą
 * bazę kosztuje cudzą pracę i jest nieodwracalna. Ta sama zasada co w
 * `scripts/cleanup-test-dbs.sh` („nie wiem, czyja to baza, więc jej nie ruszam").
 *
 * RODZINA = bazy, na których PHPUnit naprawdę chodzi w tym repozytorium
 * (sprawdzone grepem po `.github/workflows`, `scripts/`, `tests/skrypty/`):
 *
 *   kuking_test, kuking_test_<worktree>, kuking_test_kat_<katalog>_<skrót>
 *       zwykły przebieg (`tests/Support/kuking_nazwa_testowej_bazy.php`, CI ustawia
 *       `kuking_test` wprost w jobach `test` i `dostepnosc`)
 *   kuking_race, kuking_race_<sufiks>
 *       grupa `dwa-polaczenia` (D-105, `scripts/testy-dwa-polaczenia.sh`)
 *   kuking_flota_<stanowisko>
 *       lokalne kontrole ujemne i skrypty stanowisk floty
 *       (`kontrole-negatywne-alfa08.py` z KUKING_KONTROLE_LOKALNIE, `kontakt-*`,
 *       `monitoring/`, `onboarding-kontrole.sh`, `ai-pilots/`, `kreator-*`).
 *       Nazwy stanowisk zawierają myślnik, np. `kuking_flota_gpt-onboarding`.
 *
 * CELOWO POZA RODZINĄ, choć w repozytorium występują: `kuking` (deweloperska),
 * `railway*` i każda baza zewnętrzna, ORAZ bazy przyrządów `kuking_581_*`,
 * `kuking_port_*`, `kuking_a11y`, `kuking_wydajnosc`, `*_pomiar`, `kuking_qa_*`.
 * Te ostatnie obsługują skrypty Node/`artisan` (`migrate:fresh --seed`, fixtury
 * przeglądarkowe), nigdzie nie uruchamiają PHPUnita — a `RefreshDatabase` na
 * nich wyczyściłby dane demonstracyjne, które przyrząd przygotował. Gdyby
 * kiedyś któryś przebieg PHPUnita miał tam chodzić, dopisz rodzinę TUTAJ, razem
 * z testem `BezpiecznikBazyTestowejTest`, a nie obchodź odmowy.
 *
 * Porównanie jest CASE-SENSITIVE celowo: libpq przekazuje nazwę bazy dokładnie
 * tak, jak ją podano, więc `KUKING` to inna baza niż `kuking`, a rodzina zna
 * tylko małe litery prefiksu.
 *
 * Ten plik, jak `kuking_nazwa_testowej_bazy.php`, nie ma skutków ubocznych przy
 * wczytaniu (żadnego `putenv`, `exit`, `vendor/autoload.php`) — wywołanie stoi
 * dopiero w `tests/bootstrap.php`.
 */

/**
 * Rodziny baz, na których wolno uruchomić PHPUnita: wzorzec => opis do komunikatu.
 *
 * @return array<string, string>
 */
function kuking_rodziny_baz_testowych(): array
{
    return [
        '/^kuking_test(_[A-Za-z0-9_]+)?$/' => 'zwykły przebieg testów (kuking_test, kuking_test_<worktree>, kuking_test_kat_…)',
        '/^kuking_race(_[A-Za-z0-9_]+)?$/' => 'grupa dwa-polaczenia (kuking_race, kuking_race_<sufiks>)',
        '/^kuking_flota_[A-Za-z0-9_-]+$/' => 'stanowisko floty (kuking_flota_<stanowisko>)',
    ];
}

/**
 * Wyciąga nazwę bazy z `DB_URL` (postgres://użytkownik:hasło@host:port/baza).
 * Zwraca null, gdy adresu nie da się rozebrać albo nie ma w nim nazwy bazy —
 * wtedy wołający MUSI odmówić, bo nie wiadomo, gdzie połączenie trafi.
 */
function kuking_baza_z_adresu_polaczenia(string $adres): ?string
{
    $czesci = parse_url($adres);

    if (! is_array($czesci) || ! isset($czesci['scheme'], $czesci['path'])) {
        return null;
    }

    $nazwa = rawurldecode(ltrim($czesci['path'], '/'));

    return $nazwa === '' ? null : $nazwa;
}

/**
 * Ocenia, czy PHPUnit może pracować na takim połączeniu.
 *
 * Zwraca NULL, gdy wolno, albo gotowy komunikat po polsku, gdy trzeba odmówić.
 * Czysta funkcja: nie czyta środowiska i nie kończy procesu, dzięki czemu da
 * się ją sprawdzić testem bez łączenia z jakąkolwiek bazą.
 *
 * `DB_URL` przebija `DB_DATABASE` w `config/database.php`, więc jeśli jest
 * niepusty, to ON decyduje o tym, dokąd trafi połączenie — i jego nazwa jest
 * tym, co oceniamy. Hasło z adresu nigdy nie trafia do komunikatu.
 */
function kuking_ocen_baze_testowa(string $dbDatabase, string $dbUrl = ''): ?string
{
    $dbUrl = trim($dbUrl);

    if ($dbUrl !== '') {
        $nazwa = kuking_baza_z_adresu_polaczenia($dbUrl);
        $zrodlo = 'DB_URL';

        if ($nazwa === null) {
            return kuking_komunikat_odmowy(
                'DB_URL jest ustawiony, ale nie da się z niego odczytać nazwy bazy',
                'DB_URL przebija DB_DATABASE, więc nie wiadomo, dokąd trafi połączenie',
            );
        }
    } else {
        $nazwa = trim($dbDatabase);
        $zrodlo = 'DB_DATABASE';
    }

    if ($nazwa === '') {
        return kuking_komunikat_odmowy(
            'nazwa bazy jest pusta',
            'bootstrap zwykle sam wylicza nazwę z katalogu — pusta wartość oznacza, że coś ją nadpisało',
        );
    }

    foreach (array_keys(kuking_rodziny_baz_testowych()) as $wzor) {
        if (preg_match($wzor, $nazwa) === 1) {
            return null;
        }
    }

    return kuking_komunikat_odmowy(
        $zrodlo.' wskazuje bazę „'.$nazwa.'”, która nie należy do rodziny testowej',
        'testy zaczynają od zrzucenia całego schematu tej bazy',
    );
}

/** Składa komunikat odmowy: co się stało, dlaczego to groźne i co zrobić. */
function kuking_komunikat_odmowy(string $co, string $dlaczego): string
{
    $rodziny = '';

    foreach (kuking_rodziny_baz_testowych() as $opis) {
        $rodziny .= '    - '.$opis."\n";
    }

    return "\nTESTY NIE RUSZĄ — ODMAWIAM STARTU: {$co}.\n"
        ."Dlaczego to zatrzymuje testy: {$dlaczego}. `RefreshDatabase` skasowałoby dane tej bazy,\n"
        ."a jeśli to baza deweloperska (`kuking`), produkcyjna albo cudza, nie da się tego cofnąć.\n\n"
        ."Co zrobić:\n"
        ."  1. Najprostsze: usuń przesłonięcie i pozwól testom wyliczyć własną bazę\n"
        ."       unset DB_DATABASE DB_URL\n"
        ."       php artisan test\n"
        ."     (nazwa powstaje z katalogu: kuking_test w głównym checkoucie, kuking_test_<worktree> w git worktree).\n"
        ."  2. Albo podaj jawnie bazę z rodziny testowej, np. DB_DATABASE=kuking_test.\n"
        ."  3. Sprawdź, skąd wzięły się DB_DATABASE i DB_URL: `env | grep ^DB_` (profil powłoki, direnv, `export`).\n\n"
        ."Rodziny baz, na których wolno uruchamiać testy:\n"
        .$rodziny
        ."Zasady i uzasadnienie: docs/DECISIONS.md D-334, docs/PULAPKI_TESTOW.md.\n\n";
}

/**
 * Wołane z `tests/bootstrap.php`, PO wyliczeniu `DB_DATABASE`, a PRZED
 * `vendor/autoload.php` — czyli zanim Laravel w ogóle otworzy jakiekolwiek
 * połączenie. Odmowa kończy proces kodem 2 i nie rzuca wyjątku: ślad stosu
 * z bootstrapu PHPUnita przykrywałby komunikat, który ma powiedzieć, co zrobić.
 */
function kuking_wymus_baze_testowa(): void
{
    $baza = getenv('DB_DATABASE');
    $adres = getenv('DB_URL');

    $komunikat = kuking_ocen_baze_testowa(
        is_string($baza) ? $baza : '',
        is_string($adres) ? $adres : '',
    );

    if ($komunikat === null) {
        return;
    }

    fwrite(STDERR, $komunikat);

    exit(2);
}
