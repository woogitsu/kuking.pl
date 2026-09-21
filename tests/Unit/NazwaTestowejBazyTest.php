<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Test regresyjny do issue #66 (dwa równoległe przebiegi testów psują sobie
 * bazę): sprawdza samą LOGIKĘ wyboru nazwy testowej bazy z `tests/bootstrap.php`,
 * bez odpalania całego Laravela ani PHPUnit — funkcja `kuking_nazwa_testowej_bazy()`
 * jest już zadeklarowana globalnie, bo to właśnie ten plik ładuje PHPUnit jako
 * bootstrap (patrz `bootstrap` w phpunit.xml).
 *
 * Świadomie `PHPUnit\Framework\TestCase`, nie `Tests\TestCase` — ten test nie
 * dotyka bazy danych w ogóle, więc nie potrzebuje bootowania aplikacji Laravel.
 */
class NazwaTestowejBazyTest extends TestCase
{
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
     * Drzewo BEZ `.git` to kopia (runtime testowy floty powstaje przez
     * `rsync --exclude '.git'`). Do naprawy tej usterki funkcja zwracała
     * wtedy gołe "kuking_test" — czyli WSZYSTKIE runtime'y na jednej bazie,
     * i dokładnie to sypało losową czerwienią przy równoległych przebiegach.
     *
     * Poprzednia wersja tego testu asercją zabetonowała tamto zachowanie
     * („kod rozpakowany z archiwum ma dostać nazwę domyślną"). Asercja była
     * dobrze uzasadniona dla archiwum i błędna dla kopii runtime'u — a że
     * odróżnić ich nie sposób, wygrywa przypadek, który realnie chodzi
     * równolegle. Archiwum dostanie własną bazę i nikomu to nie szkodzi;
     * dziesięć runtime'ów na jednej bazie szkodziło mierzalnie.
     */
    public function test_kopia_drzewa_bez_git_dostaje_wlasny_sufiks(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();

        $this->assertMatchesRegularExpression(
            '/^kuking_test_kopia_[a-zA-Z0-9_]+$/',
            kuking_nazwa_testowej_bazy($katalog),
        );
        $this->assertNotSame('kuking_test', kuking_nazwa_testowej_bazy($katalog));
    }

    public function test_dwie_rozne_kopie_drzewa_dostaja_dwie_rozne_nazwy(): void
    {
        $katalogA = $this->tymczasowyKatalogRepo();
        $katalogB = $this->tymczasowyKatalogRepo();

        $this->assertNotSame(
            kuking_nazwa_testowej_bazy($katalogA),
            kuking_nazwa_testowej_bazy($katalogB),
        );
    }

    public function test_ta_sama_kopia_drzewa_daje_ta_sama_nazwe_za_kazdym_razem(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();

        $this->assertSame(
            kuking_nazwa_testowej_bazy($katalog),
            kuking_nazwa_testowej_bazy($katalog),
        );

        // Ta sama kopia osiągnięta inaczej zapisaną ścieżką to wciąż ta sama
        // kopia: `tests/bootstrap.php` woła funkcję z `__DIR__.'/..'`,
        // a skrypty powłoki z gołej ścieżki katalogu. Gdyby te dwa zapisy
        // dawały dwie bazy, jeden runtime kasowałby bazę sam sobie.
        mkdir($katalog.'/tests');
        $this->assertSame(
            kuking_nazwa_testowej_bazy($katalog),
            kuking_nazwa_testowej_bazy($katalog.'/tests/..'),
        );
        $this->assertSame(
            kuking_nazwa_testowej_bazy($katalog),
            kuking_nazwa_testowej_bazy($katalog.'/'),
        );
        rmdir($katalog.'/tests');
    }

    /**
     * Postgres tnie identyfikatory po 63 bajtach BEZ OSTRZEŻENIA, więc dwie
     * różne nazwy dłuższe niż limit potrafią wskazać jedną bazę. Liczy się
     * NAJDŁUŻSZY przedrostek w repozytorium, a jest nim "kuking_zrodlo_proby"
     * z `tests/skrypty/proba-odtworzenia.sh` — nie "kuking_test".
     */
    public function test_nazwa_kopii_miesci_sie_w_limicie_identyfikatora_postgresa(): void
    {
        $katalog = sys_get_temp_dir().'/kuking-nazwa-bazy-'.str_repeat('a', 80);
        mkdir($katalog, 0o777, true);
        $this->tmpDoUsuniecia[] = $katalog;

        $sufiks = substr(kuking_nazwa_testowej_bazy($katalog), strlen('kuking_test'));

        foreach ([
            kuking_nazwa_testowej_bazy($katalog),
            kuking_nazwa_bazy_wyscigow($katalog),
            kuking_nazwa_bazy_wycofania($katalog),
            // Nazwy sklejane w `tests/skrypty/proba-odtworzenia.sh` z tego
            // samego sufiksu. To NAJGORSZY przypadek w repozytorium, bo tam
            // sufiks traci podkreślniki i dostaje jeszcze końcówkę na wariant
            // bazy — najdłuższą "bezhasla". Bez tej pozycji strażnik świeciłby
            // na zielono przy nazwie, która i tak zostanie po cichu obcięta.
            'kuking_zrodlo_proby'.$sufiks,
            'proba_odtworzenia_test'.str_replace('_', '', $sufiks).'bezhasla',
        ] as $nazwa) {
            $this->assertLessThanOrEqual(63, strlen($nazwa), "za długa nazwa: {$nazwa}");
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/', $nazwa);
        }
    }

    private function tymczasowyKatalogRepo(): string
    {
        $katalog = sys_get_temp_dir().'/kuking-nazwa-bazy-'.bin2hex(random_bytes(8));
        mkdir($katalog, 0o777, true);
        $this->tmpDoUsuniecia[] = $katalog;

        return $katalog;
    }

    /** @var list<string> */
    private array $tmpDoUsuniecia = [];

    protected function tearDown(): void
    {
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

        parent::tearDown();
    }
}
