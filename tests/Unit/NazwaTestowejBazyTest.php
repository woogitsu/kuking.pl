<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test regresyjny do issue #66 i #736 (równoległe przebiegi testów psują sobie
 * bazę): sprawdza samą LOGIKĘ wyboru nazwy bazy z `tests/nazwa-bazy.php`,
 * bez odpalania Laravela — funkcje są zadeklarowane globalnie, bo ten plik
 * wciąga `tests/bootstrap.php`, czyli bootstrap PHPUnit.
 *
 * Test jest o ZACHOWANIU, nie o kształcie nazwy: dwa różne katalogi dostają
 * dwie różne bazy, ten sam katalog dostaje zawsze tę samą, a wynik mieści się
 * w limicie identyfikatora PostgreSQL-a. Asercja na dosłowną nazwę
 * (`kuking_test_agent_abc123`) byłaby asercją na regułę, a reguła ma prawo się
 * zmienić — zmienić się nie mają te trzy własności.
 *
 * Świadomie `PHPUnit\Framework\TestCase`, nie `Tests\TestCase`: ten test nie
 * dotyka bazy danych w ogóle.
 */
class NazwaTestowejBazyTest extends TestCase
{
    /** Limit długości identyfikatora w PostgreSQL-u (NAMEDATALEN - 1). */
    private const LIMIT_POSTGRESA = 63;

    /**
     * SEDNO NAPRAWY #736. Do 19 września nazwę różnicował wyłącznie `git
     * worktree`, a kopie robocze w WSL to ZWYKŁE KLONY (`.git` jest w nich
     * katalogiem, nie plikiem) — więc wszystkie dostawały jedno wspólne
     * `kuking_test` i wszystkie równoległe przebiegi zrzucały sobie schemat.
     * Ten test przechodzi tylko wtedy, gdy różnicuje KATALOG, a nie Git.
     */
    public function test_dwa_rozne_katalogi_dostaja_dwie_rozne_nazwy(): void
    {
        $a = $this->tymczasowyKatalogRepo();
        $b = $this->tymczasowyKatalogRepo();

        $this->assertNotSame(
            kuking_nazwa_testowej_bazy($a),
            kuking_nazwa_testowej_bazy($b),
        );
    }

    /**
     * Ten sam katalog jako ZWYKŁY KLON (`.git` to katalog) i jako WORKTREE
     * (`.git` to plik ze wskaźnikiem) ma dostać tę samą nazwę — i obie muszą
     * być różne od nazwy innego katalogu. Inaczej wracamy do stanu, w którym
     * klon i główny checkout dzielą jedną bazę.
     */
    public function test_zwykly_klon_i_worktree_roznia_sie_katalogiem_a_nie_rodzajem_repo(): void
    {
        $klon = $this->tymczasowyKatalogRepo();
        mkdir($klon.'/.git');

        $worktree = $this->tymczasowyKatalogRepo();
        file_put_contents(
            $worktree.'/.git',
            "gitdir: /home/user/kuking.pl/.git/worktrees/agent-abc123\n",
        );

        $bezRepo = $this->tymczasowyKatalogRepo();

        $nazwy = [
            kuking_nazwa_testowej_bazy($klon),
            kuking_nazwa_testowej_bazy($worktree),
            kuking_nazwa_testowej_bazy($bezRepo),
        ];

        $this->assertSame($nazwy, array_values(array_unique($nazwy)));
    }

    public function test_ten_sam_katalog_daje_ta_sama_nazwe_za_kazdym_razem(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();

        $this->assertSame(
            kuking_nazwa_testowej_bazy($katalog),
            kuking_nazwa_testowej_bazy($katalog),
        );
    }

    /**
     * Nazwa nie może być losowa przy każdym uruchomieniu — inaczej bazy mnożą
     * się bez końca i nie ma czego sprzątać. Ten sam katalog podany raz ze
     * ukośnikiem na końcu, raz bez, to nadal ten sam katalog.
     */
    public function test_konczacy_ukosnik_nie_tworzy_drugiej_bazy(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();

        $this->assertSame(
            kuking_nazwa_testowej_bazy($katalog),
            kuking_nazwa_testowej_bazy($katalog.'/'),
        );
    }

    /**
     * Dwie kopie o TEJ SAMEJ nazwie katalogu, ale w różnych katalogach
     * nadrzędnych — realny układ, gdy dwie osoby klonują repo pod tą samą
     * nazwą. Sama nazwa katalogu by tu nie wystarczyła; skrót pełnej ścieżki
     * wystarcza.
     */
    public function test_ta_sama_nazwa_katalogu_w_dwoch_miejscach_daje_dwie_bazy(): void
    {
        $rodzicA = $this->tymczasowyKatalogRepo();
        $rodzicB = $this->tymczasowyKatalogRepo();

        mkdir($rodzicA.'/kuking.pl');
        mkdir($rodzicB.'/kuking.pl');
        $this->doUsuniecia[] = $rodzicA.'/kuking.pl';
        $this->doUsuniecia[] = $rodzicB.'/kuking.pl';

        $this->assertNotSame(
            kuking_nazwa_testowej_bazy($rodzicA.'/kuking.pl'),
            kuking_nazwa_testowej_bazy($rodzicB.'/kuking.pl'),
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
    }

    /**
     * Każda z trzech nazw (testowa, wyścigowa, próby wycofania) musi być
     * poprawnym identyfikatorem PostgreSQL-a BEZ cudzysłowu i musi się zmieścić
     * w 63 znakach — dla dowolnie paskudnej nazwy katalogu. Najdłuższy prefiks
     * to `proba_wycofania`, więc to on wyznacza margines.
     */
    #[DataProvider('katalogiOWieluKsztaltach')]
    public function test_nazwa_jest_bezpiecznym_identyfikatorem_w_limicie(string $nazwaKatalogu): void
    {
        $rodzic = $this->tymczasowyKatalogRepo();
        $katalog = $rodzic.'/'.$nazwaKatalogu;

        if (! @mkdir($katalog)) {
            $this->markTestSkipped('System plików nie przyjął nazwy: '.$nazwaKatalogu);
        }

        $this->doUsuniecia[] = $katalog;

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
        $katalog = $this->tymczasowyKatalogRepo();

        $this->assertStringStartsWith('kuking_test_', kuking_nazwa_testowej_bazy($katalog));
        $this->assertStringStartsWith('kuking_race_', kuking_nazwa_bazy_wyscigow($katalog));
        $this->assertStringStartsWith('proba_wycofania_', kuking_nazwa_bazy_wycofania($katalog));
    }

    /**
     * Gołe `kuking_test` nie może już paść dla ŻADNEGO katalogu. Zwykły klon
     * jest nieodróżnialny od kanonicznego checkoutu, więc „ten jeden katalog
     * bez sufiksu" znaczyłoby „wszystkie klony w jednej bazie" — czyli
     * dokładnie usterkę #736.
     */
    public function test_zaden_katalog_nie_dostaje_golej_nazwy_domyslnej(): void
    {
        foreach ([$this->tymczasowyKatalogRepo(), sys_get_temp_dir(), __DIR__.'/../..'] as $katalog) {
            $this->assertNotSame('kuking_test', kuking_nazwa_testowej_bazy($katalog));
        }
    }

    /**
     * Wpis w rejestrze jest JEDYNYM dowodem, na podstawie którego
     * `scripts/cleanup-test-dbs.sh` wolno skasować bazę. Musi zawierać
     * ścieżkę kopii roboczej, bo sprzątacz sprawdza, czy ta ścieżka jeszcze
     * istnieje.
     */
    public function test_rejestr_zapisuje_sciezke_kopii_roboczej(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();
        $rejestr = $this->tymczasowyKatalogRepo();

        putenv('KUKING_REJESTR_BAZ='.$rejestr);

        try {
            $nazwa = kuking_nazwa_testowej_bazy($katalog);
            kuking_zapisz_rejestr_bazy($nazwa, $katalog);

            $this->assertFileExists($rejestr.'/'.$nazwa);
            $this->doUsuniecia[] = $rejestr.'/'.$nazwa;
            $this->assertSame(
                rtrim(str_replace('\\', '/', (string) realpath($katalog)), '/'),
                trim((string) file_get_contents($rejestr.'/'.$nazwa)),
            );
        } finally {
            putenv('KUKING_REJESTR_BAZ');
        }
    }

    /** @var list<string> */
    private array $doUsuniecia = [];

    private function tymczasowyKatalogRepo(): string
    {
        $katalog = sys_get_temp_dir().'/kuking-nazwa-bazy-'.bin2hex(random_bytes(8));
        mkdir($katalog, 0o777, true);
        $this->doUsuniecia[] = $katalog;

        return $katalog;
    }

    protected function tearDown(): void
    {
        // Katalogi kasujemy od najgłębszego: podkatalogi trafiły na listę
        // później niż ich rodzice.
        foreach (array_reverse($this->doUsuniecia) as $sciezka) {
            if (is_dir($sciezka.'/.git')) {
                @rmdir($sciezka.'/.git');
            } elseif (is_file($sciezka.'/.git')) {
                @unlink($sciezka.'/.git');
            }

            if (is_dir($sciezka)) {
                @rmdir($sciezka);
            } elseif (is_file($sciezka)) {
                @unlink($sciezka);
            }
        }

        $this->doUsuniecia = [];

        parent::tearDown();
    }
}
