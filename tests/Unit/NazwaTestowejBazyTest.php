<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test regresyjny do issue #66, #736 i #920 (równoległe przebiegi testów psują
 * sobie bazę): sprawdza samą LOGIKĘ wyboru nazwy bazy z `tests/nazwa-bazy.php`,
 * bez odpalania Laravela — funkcje są zadeklarowane globalnie, bo ten plik
 * wciąga `tests/bootstrap.php`, czyli bootstrap PHPUnit (patrz `bootstrap`
 * w phpunit.xml).
 *
 * Świadomie `PHPUnit\Framework\TestCase`, nie `Tests\TestCase`: ten test nie
 * dotyka bazy danych w ogóle.
 */
class NazwaTestowejBazyTest extends TestCase
{
    /** Limit długości identyfikatora w PostgreSQL-u (NAMEDATALEN - 1). */
    private const LIMIT_POSTGRESA = 63;

    public function test_glowny_checkout_dostaje_nazwe_bez_sufiksu(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();

        // Główny checkout: `.git` to KATALOG (jak w prawdziwym repo), nie plik
        // wskazujący na worktree.
        mkdir($katalog.'/.git');

        $this->assertSame('kuking_test', kuking_nazwa_testowej_bazy($katalog));
    }

    public function test_worktree_dostaje_wlasny_stabilny_sufiks(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();
        file_put_contents(
            $katalog.'/.git',
            "gitdir: /home/user/kuking.pl/.git/worktrees/agent-abc123\n",
        );

        $this->assertSame(
            'kuking_test_agent_abc123',
            kuking_nazwa_testowej_bazy($katalog),
        );
    }

    /**
     * Sedno naprawy: DWA RÓŻNE worktree muszą dostać DWIE RÓŻNE bazy — inaczej
     * dwa równoległe przebiegi `php artisan test` znowu zaczną sobie zrzucać
     * schemat, dokładnie tak jak w incydencie z 2026-09-06.
     */
    public function test_dwa_rozne_worktree_dostaja_dwie_rozne_nazwy(): void
    {
        $katalogA = $this->tymczasowyKatalogRepo();
        file_put_contents(
            $katalogA.'/.git',
            "gitdir: /home/user/kuking.pl/.git/worktrees/agent-a7b3b8ff6474e3a9a\n",
        );

        $katalogB = $this->tymczasowyKatalogRepo();
        file_put_contents(
            $katalogB.'/.git',
            "gitdir: /home/user/kuking.pl/.git/worktrees/agent-a0463e5ee460dbe66\n",
        );

        $nazwaA = kuking_nazwa_testowej_bazy($katalogA);
        $nazwaB = kuking_nazwa_testowej_bazy($katalogB);

        $this->assertNotSame($nazwaA, $nazwaB);
        $this->assertSame('kuking_test_agent_a7b3b8ff6474e3a9a', $nazwaA);
        $this->assertSame('kuking_test_agent_a0463e5ee460dbe66', $nazwaB);
    }

    public function test_ten_sam_worktree_daje_ta_sama_nazwe_za_kazdym_razem(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();
        file_put_contents(
            $katalog.'/.git',
            "gitdir: /home/user/kuking.pl/.git/worktrees/agent-powtorka\n",
        );

        $this->assertSame(
            kuking_nazwa_testowej_bazy($katalog),
            kuking_nazwa_testowej_bazy($katalog),
        );
    }

    public function test_nazwa_worktree_z_niebezpiecznymi_znakami_jest_oczyszczana(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();
        // Git nie generuje takich nazw sam z siebie, ale funkcja nie może
        // ufać ślepo zawartości pliku .git — a wynik musi być bezpiecznym
        // identyfikatorem Postgresa bez cudzysłowu.
        file_put_contents(
            $katalog.'/.git',
            "gitdir: /repo/.git/worktrees/agent's; DROP TABLE x;--\n",
        );

        $this->assertMatchesRegularExpression(
            '/^kuking_test_[a-zA-Z0-9_]+$/',
            kuking_nazwa_testowej_bazy($katalog),
        );
    }

    /**
     * ────────────────────────────────────────────────────────────────────────
     *  SEDNO: KOPIA REPOZYTORIUM BEZ `.git` TO NIE JEST GŁÓWNY CHECKOUT
     * ────────────────────────────────────────────────────────────────────────
     *
     * Runtime floty (`_wspolne/przygotuj-runtime.sh`) przegrywa worktree
     * rsynkiem z `--exclude '.git'`, więc w katalogu, w którym NAPRAWDĘ chodzą
     * testy, pliku `.git` NIE MA. Do 2026-09-20 funkcja traktowała ten
     * przypadek jak główny checkout i zwracała gołe `kuking_test` — czyli
     * KAŻDE stanowisko floty dostawało tę samą bazę, choć komentarz nad
     * funkcją obiecywał unikalność. Zmierzone: trzy różne katalogi runtime
     * dostawały `kuking_test`, `kuking_race` i `proba_wycofania` co do znaku.
     *
     * Skutkiem nie był wcale jeden zepsuty test, tylko fałszywa czerwień
     * z kontencji: cudze `RefreshDatabase` zrzucało schemat w trakcie
     * czyjegoś przebiegu i każdy, kto to zobaczył, musiał najpierw udowodnić,
     * że to nie jego wina.
     *
     * Ten test oblewa dokładnie wtedy, gdy dwa stanowiska dostaną tę samą
     * nazwę bazy — i o to w nim chodzi. Bez niego naprawa jest jednorazowa.
     */
    public function test_dwa_stanowiska_bez_pliku_git_dostaja_dwie_rozne_bazy(): void
    {
        // Dokładnie to, co robi runtime floty: katalog repozytorium jest,
        // pliku `.git` nie ma, bo rsync go wykluczył.
        $stanowiskoA = $this->tymczasowyKatalogRepo('r49-trasy-run');
        $stanowiskoB = $this->tymczasowyKatalogRepo('zaleglosci-zgloszen-run');

        $this->assertNotSame(
            kuking_nazwa_testowej_bazy($stanowiskoA),
            kuking_nazwa_testowej_bazy($stanowiskoB),
            'Dwa stanowiska floty dostały tę samą bazę testową — wrócił issue #66.',
        );

        // Bazy wyścigów i wycofania liczą sufiks TĄ SAMĄ funkcją, więc
        // zderzają się dokładnie tak samo. Sprawdzamy je wprost, żeby
        // następny refaktor nie naprawił jednej reguły i zostawił dwie.
        $this->assertNotSame(
            kuking_nazwa_bazy_wyscigow($stanowiskoA),
            kuking_nazwa_bazy_wyscigow($stanowiskoB),
            'Dwa stanowiska floty dostały tę samą bazę wyścigów.',
        );

        $this->assertNotSame(
            kuking_nazwa_bazy_wycofania($stanowiskoA),
            kuking_nazwa_bazy_wycofania($stanowiskoB),
            'Dwa stanowiska floty dostały tę samą bazę próby wycofania.',
        );
    }

    /**
     * Ta sama rodzina co wyżej, tylko od strony `tests/skrypty/proba-odtworzenia.sh`:
     * skrypt liczy SUFIKS jako `substr(nazwa, strlen('kuking_test'))` i podstawia
     * awaryjne `_glowny`, gdy wyjdzie pusto. Pusty sufiks w runtime znaczy więc
     * `kuking_zrodlo_proby_glowny` u WSZYSTKICH naraz.
     */
    public function test_kopia_bez_pliku_git_daje_niepusty_sufiks_dla_skryptow(): void
    {
        $stanowisko = $this->tymczasowyKatalogRepo('bazy-stanowisk-run');

        $sufiks = substr(kuking_nazwa_testowej_bazy($stanowisko), strlen('kuking_test'));

        $this->assertNotSame(
            '',
            $sufiks,
            'Pusty sufiks wpycha wszystkie stanowiska w jedną bazę `kuking_zrodlo_proby_glowny`.',
        );
    }

    public function test_kopia_bez_pliku_git_daje_ta_sama_nazwe_za_kazdym_razem(): void
    {
        $stanowisko = $this->tymczasowyKatalogRepo('powtarzalne-run');

        $this->assertSame(
            kuking_nazwa_testowej_bazy($stanowisko),
            kuking_nazwa_testowej_bazy($stanowisko),
            'Nazwa bazy musi być stabilna, inaczej każdy przebieg zostawia nową bazę do sprzątania.',
        );
    }

    public function test_kopia_bez_pliku_git_daje_bezpieczny_identyfikator(): void
    {
        $stanowisko = $this->tymczasowyKatalogRepo('stanowisko-z-kropka.i-mysln1kiem');

        $this->assertMatchesRegularExpression(
            '/^kuking_test_[a-zA-Z0-9_]+$/',
            kuking_nazwa_testowej_bazy($stanowisko),
        );
    }

    /**
     * WARTOŚĆ Z `naprawa/baza-proby-per-runtime` PONAD `#920`: dwa stanowiska
     * o TEJ SAMEJ nazwie katalogu, ale w różnych katalogach nadrzędnych —
     * realny układ floty, gdy dwa runtime'y albo dwie kopie repo nazywają się
     * tak samo (`…/kuking.pl`, `…/agent-run`). Sama nazwa katalogu by tu nie
     * wystarczyła — dopiero skrót PEŁNEJ ścieżki rozróżnia te dwa przypadki.
     * Bez tego testu naprawa #920 mogłaby się cofnąć do wersji „nazwa
     * katalogu bez skrótu" i nikt by tego nie zauważył na dwóch pozostałych
     * testach (które używają różnych nazw katalogów).
     */
    public function test_ta_sama_nazwa_katalogu_w_dwoch_miejscach_daje_dwie_rozne_bazy(): void
    {
        $stanowiskoA = $this->tymczasowyKatalogRepo('kuking-flota-run');
        $stanowiskoB = $this->tymczasowyKatalogRepo('kuking-flota-run');

        $this->assertNotSame(
            $stanowiskoA,
            $stanowiskoB,
            'Fixture testu musi dać dwie różne ścieżki — inaczej test niczego nie sprawdza.',
        );

        $this->assertNotSame(
            kuking_nazwa_testowej_bazy($stanowiskoA),
            kuking_nazwa_testowej_bazy($stanowiskoB),
            'Dwa katalogi o tej samej nazwie w różnych miejscach dostały tę samą bazę.',
        );
    }

    /**
     * WARTOŚĆ Z `naprawa/baza-proby-per-runtime` PONAD `#920`: ten sam
     * katalog osiągnięty dwoma różnymi zapisami ścieżki (kończący ukośnik,
     * `/podkatalog/..`) musi dać TĘ SAMĄ bazę. `tests/bootstrap.php` woła
     * funkcję z `__DIR__` (bez ukośnika), a skrypty powłoki bywają mniej
     * dyscyplinowane — gdyby te dwa zapisy dawały dwie bazy, jeden runtime
     * rozjechałby się sam ze sobą między kolejnymi przebiegami. Zachowanie
     * dziś zapewnia `realpath()` wewnątrz `kuking_nazwa_testowej_bazy()` —
     * ten test czyni ten kontrakt jawnym, żeby przyszły refaktor (np. zamiana
     * `realpath()` na coś tańszego) nie zepsuł go po cichu.
     */
    public function test_rozne_zapisy_tej_samej_sciezki_daja_ta_sama_nazwe(): void
    {
        $stanowisko = $this->tymczasowyKatalogRepo('normalizacja-run');
        mkdir($stanowisko.'/podkatalog');

        $nazwaKanoniczna = kuking_nazwa_testowej_bazy($stanowisko);

        $this->assertSame(
            $nazwaKanoniczna,
            kuking_nazwa_testowej_bazy($stanowisko.'/'),
            'Kończący ukośnik nie może zakładać drugiej bazy dla tego samego katalogu.',
        );

        $this->assertSame(
            $nazwaKanoniczna,
            kuking_nazwa_testowej_bazy($stanowisko.'/podkatalog/..'),
            '"/podkatalog/.." musi się znormalizować do tego samego katalogu, nie do nowej bazy.',
        );

        rmdir($stanowisko.'/podkatalog');
    }

    /**
     * PostgreSQL obcina identyfikatory do 63 bajtów BEZ OSTRZEŻENIA — a dwie
     * nazwy obcięte do tej samej wartości to znowu jedna baza dla dwóch
     * stanowisk, czyli ten sam błąd tylnymi drzwiami. Najdłuższy przedrostek
     * w repozytorium to `kuking_zrodlo_proby` z `tests/skrypty/proba-odtworzenia.sh`.
     */
    public function test_nazwa_miesci_sie_w_limicie_identyfikatora_postgresa(): void
    {
        $dlugaNazwa = str_repeat('bardzo-dlugie-stanowisko-floty-', 5).'run';

        $sufiks = substr(
            kuking_nazwa_testowej_bazy($this->tymczasowyKatalogRepo($dlugaNazwa)),
            strlen('kuking_test'),
        );

        $this->assertLessThanOrEqual(
            self::LIMIT_POSTGRESA,
            strlen('kuking_zrodlo_proby'.$sufiks),
            'Nazwa przekracza 63 bajty — Postgres obetnie ją po cichu i dwa stanowiska mogą trafić w jedną bazę.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function katalogiOWieluKsztaltach(): iterable
    {
        yield 'krótka nazwa' => ['kuking'];
        yield 'zwykła nazwa robocza' => ['kuking-736-izolacja-bazy'];
        yield 'nazwa dłuższa niż limit Postgresa' => [str_repeat('bardzo-dluga-nazwa-katalogu-', 5)];
        yield 'znaki wymagające cudzysłowu w SQL' => ["agent's; DROP TABLE x;--"];
        yield 'same znaki niebezpieczne' => ['.-.-.-'];
        yield 'znaki spoza ASCII' => ['kopia-żółć-ćma'];
        yield 'wielkie litery' => ['Kuking-681'];
    }

    /**
     * Każda z trzech nazw (testowa, wyścigowa, próby wycofania) musi być
     * poprawnym identyfikatorem PostgreSQL-a BEZ cudzysłowu i musi się zmieścić
     * w 63 znakach — dla dowolnie paskudnej nazwy katalogu.
     *
     * MAŁE LITERY nie są kosmetyką: PostgreSQL składa identyfikator bez
     * cudzysłowu do małych liter, więc `createdb Kuking_Test_X` zakłada bazę
     * `kuking_test_x`, a aplikacja pyta przez PDO o `Kuking_Test_X` dosłownie
     * i dostaje „database does not exist". Katalog `Kuking-681` wystarczy,
     * żeby to wywołać.
     */
    #[DataProvider('katalogiOWieluKsztaltach')]
    public function test_nazwa_jest_bezpiecznym_identyfikatorem_w_limicie(string $nazwaKatalogu): void
    {
        $katalog = @$this->tymczasowyKatalogRepo($nazwaKatalogu);

        if (! is_dir($katalog)) {
            $this->markTestSkipped('System plików nie przyjął nazwy: '.$nazwaKatalogu);
        }

        foreach ([
            'kuking_nazwa_testowej_bazy',
            'kuking_nazwa_bazy_wyscigow',
            'kuking_nazwa_bazy_wycofania',
        ] as $funkcja) {
            $nazwa = $funkcja($katalog);

            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $nazwa,
                $funkcja.' dała nazwę wymagającą cudzysłowu w SQL',
            );
            $this->assertLessThanOrEqual(
                self::LIMIT_POSTGRESA,
                strlen($nazwa),
                $funkcja.' przekroczyła limit identyfikatora PostgreSQL-a: '.$nazwa,
            );
        }
    }

    /**
     * Bazy wyścigów i próby wycofania MUSZĄ mieć własne przedrostki, bo ich
     * skrypty kasują swoje bazy i zrzucają w nich schemat. `proba-wycofania.sh`
     * ma bezpiecznik przepuszczający wyłącznie `proba_wycofania*`.
     */
    public function test_kazda_rodzina_baz_ma_swoj_przedrostek(): void
    {
        $stanowisko = $this->tymczasowyKatalogRepo('rodziny-run');

        $this->assertStringStartsWith('kuking_test_kat_', kuking_nazwa_testowej_bazy($stanowisko));
        $this->assertStringStartsWith('kuking_race_kat_', kuking_nazwa_bazy_wyscigow($stanowisko));
        $this->assertStringStartsWith('proba_wycofania_kat_', kuking_nazwa_bazy_wycofania($stanowisko));
    }

    /**
     * Wpis w rejestrze jest JEDYNYM dowodem, na podstawie którego
     * `scripts/cleanup-test-dbs.sh` wolno skasować bazę. Musi zawierać
     * ścieżkę kopii roboczej, bo sprzątacz sprawdza, czy ta ścieżka jeszcze
     * istnieje.
     */
    public function test_rejestr_zapisuje_sciezke_kopii_roboczej(): void
    {
        $stanowisko = $this->tymczasowyKatalogRepo('rejestr-run');
        $rejestr = $this->tymczasowyKatalogRepo();

        putenv('KUKING_REJESTR_BAZ='.$rejestr);

        try {
            $nazwa = kuking_nazwa_testowej_bazy($stanowisko);
            kuking_zapisz_rejestr_bazy($nazwa, $stanowisko);

            $this->assertFileExists($rejestr.'/'.$nazwa);
            $this->plikiDoUsuniecia[] = $rejestr.'/'.$nazwa;
            $this->assertSame(
                rtrim(str_replace('\\', '/', (string) realpath($stanowisko)), '/'),
                trim((string) file_get_contents($rejestr.'/'.$nazwa)),
            );
        } finally {
            putenv('KUKING_REJESTR_BAZ');
        }
    }

    /**
     * Zakłada tymczasowy katalog repozytorium. Gdy podasz `$nazwa`, katalog
     * dostaje ją jako ostatni segment ścieżki — bo od 2026-09-20 to właśnie
     * nazwa katalogu współtworzy nazwę bazy i test musi móc nią sterować.
     */
    private function tymczasowyKatalogRepo(?string $nazwa = null): string
    {
        $koperta = sys_get_temp_dir().'/kuking-nazwa-bazy-'.bin2hex(random_bytes(8));
        $katalog = $nazwa === null ? $koperta : $koperta.'/'.$nazwa;

        @mkdir($katalog, 0o777, true);

        $this->tmpDoUsuniecia[] = $katalog;

        if ($katalog !== $koperta) {
            // Kopertę usuwamy PO katalogu w środku, stąd doklejenie na koniec
            // listy — a nigdy nie jest nią `/tmp`, bo ma własny, losowy człon.
            $this->tmpDoUsuniecia[] = $koperta;
        }

        return $katalog;
    }

    /** @var list<string> */
    private array $tmpDoUsuniecia = [];

    /** @var list<string> */
    private array $plikiDoUsuniecia = [];

    protected function tearDown(): void
    {
        foreach ($this->plikiDoUsuniecia as $plik) {
            @unlink($plik);
        }

        foreach ($this->tmpDoUsuniecia as $katalog) {
            // Katalog ma co najwyżej jeden plik/podkatalog `.git` w środku —
            // proste rekurencyjne czyszczenie wystarcza, bez zależności.
            if (is_dir($katalog.'/.git')) {
                @rmdir($katalog.'/.git');
            } elseif (is_file($katalog.'/.git')) {
                @unlink($katalog.'/.git');
            }
            @rmdir($katalog);
        }

        $this->plikiDoUsuniecia = [];
        $this->tmpDoUsuniecia = [];

        parent::tearDown();
    }
}
