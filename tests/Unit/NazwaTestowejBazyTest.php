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

    public function test_brak_pliku_git_daje_domyslna_nazwe(): void
    {
        $katalog = $this->tymczasowyKatalogRepo();
        // Celowo bez `.git` w ogóle — np. kod rozpakowany z archiwum,
        // nie sklonowany. Funkcja ma się wtedy zachować jak dla głównego
        // checkoutu, nie wybuchnąć.
        $this->assertSame('kuking_test', kuking_nazwa_testowej_bazy($katalog));
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
