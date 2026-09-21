<?php

declare(strict_types=1);

/**
 * JEDNA reguła nazywania baz pomocniczych Kukinga (issue #66, #736).
 *
 * Ten plik jest CELOWO wolny od Composera i Laravela: wciąga go
 * `tests/bootstrap.php`, ale wciągają go też skrypty powłoki przez
 * `php -r 'require "tests/nazwa-bazy.php"; …'` — w tym
 * `.claude/hooks/session-start.sh`, który zakłada bazę ZANIM istnieje
 * `vendor/`. Gdyby reguła siedziała w `bootstrap.php` (a ten wymaga
 * `vendor/autoload.php`), hook musiałby ją przepisać po raz drugi w bashu —
 * i dokładnie to robił do dziś. Dwie kopie tej samej reguły rozjechały się
 * przy pierwszej zmianie.
 *
 * ── CO ROZRÓŻNIA NAZWĘ I DLACZEGO AKURAT TO ────────────────────────────────
 *
 * Nazwę różnicuje PEŁNA ŚCIEŻKA KATALOGU KOPII ROBOCZEJ — jej skrót (8 znaków
 * SHA-1) poprzedzony czytelną nazwą katalogu.
 *
 *   /home/mateusz/kuking-681-tagi  →  kuking_test_kuking_681_tagi_3f2a9c14
 *
 * Poprzednia reguła rozróżniała WYŁĄCZNIE `git worktree` (czytała `.git` jako
 * plik ze wskaźnikiem `gitdir:`). Kopie robocze w WSL to ZWYKŁE KLONY — `.git`
 * jest w nich katalogiem — więc wszystkie dostawały to samo domyślne
 * `kuking_test` i wszystkie równoległe przebiegi zrzucały sobie nawzajem
 * schemat. To jest ta sama awaria, którą issue #66 miało zamknąć, tylko
 * wejściem, którego tamta naprawa nie przewidziała: 963, 3737 i kilkaset
 * porażek `QueryException` / `relation … does not exist` w trzech sesjach
 * jednego dnia.
 *
 * Ścieżka katalogu jest jedyną rzeczą, która:
 *   — jest RÓŻNA dla dwóch kopii roboczych (to jest definicja „osobnej kopii"),
 *   — jest TA SAMA przy każdym uruchomieniu w tym samym katalogu (bez tego
 *     bazy mnożyłyby się w nieskończoność, a każdy przebieg zaczynałby od
 *     pełnej migracji),
 *   — nie wymaga Gita, więc działa i w worktree, i w klonie, i w kodzie
 *     rozpakowanym z archiwum.
 *
 * Czego świadomie NIE wybrano:
 *
 *  — SAMEJ nazwy katalogu (bez skrótu). Dwie kopie o tej samej nazwie
 *    w różnych katalogach (`/home/a/kuking-681` i `/home/b/kuking-681`)
 *    dostałyby jedną bazę, czyli dokładnie tę awarię, tylko rzadziej.
 *  — SAMEGO skrótu ścieżki. Poprawny, ale w `psql -l` widać wtedy ciąg
 *    hexów i nie da się powiedzieć, czyja to baza. Nazwa katalogu z przodu
 *    kosztuje 31 znaków i oszczędza tę zagadkę.
 *  — Nazwy worktree z Gita. To była reguła poprzednia — nie obejmuje klonów.
 *  — PID-u / losowego UUID. Nowa baza przy KAŻDYM uruchomieniu: kolizji nie
 *    ma, ale bazy przyrastają bez końca i nie ma czego sprzątać po nazwie.
 *  — Zmiennej z CI. W CI przebieg jest jeden (workflow ma `concurrency`),
 *    a `DB_DATABASE` i tak stoi jawnie w jobie — problemu tam nie ma.
 *
 * ── NIE MA JUŻ „GŁÓWNEGO KATALOGU BEZ SUFIKSU" ─────────────────────────────
 *
 * Do dziś kanoniczny checkout dostawał gołe `kuking_test`. To było możliwe
 * tylko dlatego, że reguła umiała rozpoznać worktree — a klonu nie umie
 * rozpoznać NIC: klon wygląda dokładnie tak samo jak kanoniczny checkout.
 * Każdy katalog dostaje więc sufiks, łącznie z kanonicznym. Gołe
 * `kuking_test` zostaje zarezerwowane dla CI, które ustawia `DB_DATABASE`
 * jawnie na poziomie joba (`.github/workflows/ci.yml`) — a jawna zmienna
 * środowiskowa ma tu zawsze pierwszeństwo (patrz `tests/bootstrap.php`).
 *
 * ── LIMIT 63 ZNAKÓW ────────────────────────────────────────────────────────
 *
 * Identyfikator PostgreSQL-a ma 63 znaki. Najdłuższy prefiks w repozytorium to
 * `proba_wycofania` (15) — z sufiksem `_<31 znaków>_<8 znaków>` daje 55.
 * Pilnuje tego `kuking_sufiks_kopii()` i test jednostkowy.
 */

/** Ile znaków czytelnej nazwy katalogu wchodzi do nazwy bazy. */
const KUKING_DLUGOSC_CZYTELNEJ_NAZWY = 31;

/**
 * Zwraca nazwę testowej bazy dla danego katalogu kopii roboczej.
 */
function kuking_nazwa_testowej_bazy(string $katalogRepo): string
{
    return 'kuking_test'.kuking_sufiks_kopii($katalogRepo);
}

/**
 * Zwraca nazwę bazy dla grupy testów `dwa-polaczenia` (D-105).
 *
 * Ta grupa NIE MOŻE chodzić na `kuking_test*`: testy na dwóch połączeniach
 * zatwierdzają dane naprawdę (bez `RefreshDatabase`), więc żyją obok zwykłego
 * przebiegu — a zwykły przebieg na tej samej bazie zrzuciłby im schemat
 * w trakcie działania.
 */
function kuking_nazwa_bazy_wyscigow(string $katalogRepo): string
{
    return 'kuking_race'.kuking_sufiks_kopii($katalogRepo);
}

/**
 * Zwraca nazwę bazy POMIAROWEJ dla próby wycofania migracji
 * (`scripts/proba-wycofania.sh`).
 *
 * Prefiks jest inny niż `kuking_*` i to jest jego jedyne zadanie: skrypt
 * próby wycofania KASUJE swoją bazę, a jego bezpiecznik przepuszcza wyłącznie
 * nazwy pasujące do `proba_wycofania*`. `kuking`, `kuking_test*`,
 * `kuking_race*` ani `railway` nie wpadną do niego nawet przy literówce.
 */
function kuking_nazwa_bazy_wycofania(string $katalogRepo): string
{
    return 'proba_wycofania'.kuking_sufiks_kopii($katalogRepo);
}

/**
 * Sufiks odróżniający kopię roboczą: `_<czytelna-nazwa-katalogu>_<skrót>`.
 *
 * Zawsze niepusty i zawsze zaczyna się od `_`, więc doklejenie go do
 * dowolnego prefiksu daje poprawny identyfikator Postgresa bez cudzysłowu.
 */
function kuking_sufiks_kopii(string $katalogRepo): string
{
    $sciezka = realpath($katalogRepo);

    if ($sciezka === false) {
        $sciezka = $katalogRepo;
    }

    // Ta sama kopia widziana spod Windows i spod WSL ma inną ścieżkę i dostanie
    // inną bazę — i tak ma być: to są dwa różne środowiska wykonawcze.
    // Normalizujemy tylko separator i końcowy ukośnik, żeby „/repo" i „/repo/"
    // nie dawały dwóch różnych baz dla jednego katalogu.
    $sciezka = rtrim(str_replace('\\', '/', $sciezka), '/');

    $skrot = substr(sha1($sciezka), 0, 8);

    // Nazwa katalogu bywa dłuższa niż limit identyfikatora i bywa pisana
    // znakami, które w SQL wymagałyby cudzysłowu. Obcinamy i czyścimy,
    // zamiast zakładać, że człowiek zawsze nazwie katalog bezpiecznie.
    //
    // MAŁE LITERY nie są kosmetyką. PostgreSQL składa identyfikator bez
    // cudzysłowu do małych liter, więc `createdb Kuking_Test_X` zakłada bazę
    // `kuking_test_x` — a aplikacja pyta przez PDO o `Kuking_Test_X` dosłownie
    // i dostaje „database does not exist". Katalog `Kuking-681` na macOS albo
    // na dysku Windows wystarczy, żeby to wywołać.
    $czytelna = strtolower(basename($sciezka));
    $czytelna = (string) preg_replace('/[^a-z0-9_]/', '_', $czytelna);
    $czytelna = trim($czytelna, '_');
    $czytelna = substr($czytelna, 0, KUKING_DLUGOSC_CZYTELNEJ_NAZWY);
    $czytelna = rtrim($czytelna, '_');

    return ($czytelna === '' ? '' : '_'.$czytelna).'_'.$skrot;
}

/**
 * Katalog rejestru żywych kopii roboczych.
 *
 * PO CO REJESTR: `scripts/cleanup-test-dbs.sh` musi umieć odróżnić bazę po
 * skasowanej kopii roboczej od bazy kopii, w której ktoś właśnie pracuje.
 * Ze samej nazwy `kuking_test_<nazwa>_<skrót>` nie da się odtworzyć ścieżki —
 * skrót jest jednokierunkowy. Dlatego każdy przebieg testów zostawia tu plik
 * o nazwie bazy, a w nim ścieżkę swojej kopii roboczej. Sprzątacz kasuje
 * WYŁĄCZNIE bazy, dla których taki wpis istnieje, a zapisana w nim ścieżka
 * już nie istnieje na dysku. Bazy bez wpisu zostawia — „nie wiem" znaczy
 * „zostawiam", bo pomyłka w drugą stronę jest nieodwracalna dla kogoś, kto
 * akurat pracuje.
 */
function kuking_katalog_rejestru_baz(): string
{
    $jawny = getenv('KUKING_REJESTR_BAZ');

    if (is_string($jawny) && $jawny !== '') {
        return rtrim($jawny, '/');
    }

    $dom = getenv('HOME');

    if (! is_string($dom) || $dom === '') {
        $dom = sys_get_temp_dir();
    }

    return rtrim($dom, '/').'/.kuking-bazy-testowe';
}

/**
 * Odnotowuje, że baza `$nazwaBazy` należy do kopii roboczej `$katalogRepo`.
 *
 * Celowo bez rzucania wyjątków: rejestr jest udogodnieniem dla sprzątacza,
 * a nie warunkiem uruchomienia testów. Brak prawa zapisu do katalogu
 * domowego ma oznaczać „sprzątacz nie ruszy tej bazy", a nie „testy nie
 * chodzą". `@` jest tu świadome — to jedyne miejsce w tym pliku, gdzie
 * błąd wejścia/wyjścia jest bez znaczenia.
 */
function kuking_zapisz_rejestr_bazy(string $nazwaBazy, string $katalogRepo): void
{
    $katalog = kuking_katalog_rejestru_baz();

    if (! is_dir($katalog) && ! @mkdir($katalog, 0o700, true) && ! is_dir($katalog)) {
        return;
    }

    $sciezka = realpath($katalogRepo);

    if ($sciezka === false) {
        $sciezka = $katalogRepo;
    }

    @file_put_contents(
        $katalog.'/'.$nazwaBazy,
        rtrim(str_replace('\\', '/', $sciezka), '/')."\n",
    );
}
