<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Models\AliasSkladnika;
use App\Models\MiaraDomowa;
use App\Models\SkladnikOdzywczy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Import wartości odżywczych ma wejść na produkcję automatycznie,
 * przy KAŻDYM wdrożeniu (#1961, D-299).
 *
 * DLACZEGO TO JEST TEST, A NIE UWAGA W RUNBOOKU
 * PR #1900 dodał migrację trzech tabel słownikowych i komendę
 * `kuking:importuj-wartosci-odzywcze`, ale zostawił jej uruchomienie jako
 * ręczny krok. Deploy migrował tabele i wyglądał na zielony, a tabele
 * zostawały puste — sekcja wartości odżywczych na stronie przepisu milczała.
 * Dokładnie ten sam kształt usterki, którą `WdrozenieUruchamiaTrescZalazkowaTest`
 * już raz złapał dla `db:seed` (8–9 września).
 *
 * Ten test pilnuje dwóch rzeczy:
 *   1. `.railway/railway.ts` NAPRAWDĘ woła komendę importu w `preDeployCommand`,
 *      po migracjach;
 *   2. drugie uruchomienie komendy na TYCH SAMYCH plikach jest szybkie —
 *      nie dotyka bazy — bo inaczej codzienny deploy bez zmiany danych
 *      przepisywałby ~600 wierszy za każdym razem.
 */
final class WdrozenieImportujeWartosciOdzywczeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function wdrozenie_wola_import_wartosci_odzywczych_po_migracjach(): void
    {
        $sciezka = base_path('.railway/railway.ts');
        $this->assertFileExists($sciezka, 'Nie ma .railway/railway.ts. Jeśli plik przeniesiono, popraw ścieżkę tutaj.');
        $konfiguracja = (string) file_get_contents($sciezka);

        $od = mb_strpos($konfiguracja, 'preDeployCommand');
        $this->assertNotFalse($od, 'W .railway/railway.ts nie ma już preDeployCommand.');

        $do = mb_strpos($konfiguracja, '],', $od);
        $this->assertNotFalse($do, 'Nie umiem odczytać listy komend pre-deploy. Jeśli zmienił się jej kształt, popraw ten test razem z nią.');

        $komendy = mb_substr($konfiguracja, $od, $do - $od);

        $pozycjaMigracji = mb_strpos($komendy, 'artisan migrate');
        $this->assertNotFalse($pozycjaMigracji, 'W komendach pre-deploy nie ma migracji — czytam zły fragment pliku.');

        $pozycjaImportu = mb_strpos($komendy, 'artisan kuking:importuj-wartosci-odzywcze');
        $this->assertNotFalse(
            $pozycjaImportu,
            'Wdrożenie nie uruchamia kuking:importuj-wartosci-odzywcze, więc tabele wartości odżywczych '
            .'zostają puste po deployu — dokładnie usterka z #1961. Sama komenda działa i ma testy; '
            .'brakowało tego, żeby ktoś ją wywołał.',
        );

        $this->assertLessThan(
            $pozycjaImportu,
            $pozycjaMigracji,
            'Import wartości odżywczych stoi PRZED migracjami — tabele, do których pisze, jeszcze nie istnieją.',
        );
    }

    #[Test]
    public function drugi_import_na_tych_samych_plikach_pomija_prace_i_niczego_nie_zmienia(): void
    {
        $pierwszy = app(ImportujWartosciOdzywcze::class)->handle();
        $this->assertFalse($pierwszy['pominieto']);

        $stanPrzed = [SkladnikOdzywczy::count(), AliasSkladnika::count(), MiaraDomowa::count()];

        $drugi = app(ImportujWartosciOdzywcze::class)->handle();

        $this->assertTrue($drugi['pominieto'], 'Drugi import na niezmienionych plikach powinien się pominąć, a nie przepisywać tabele przy każdym wdrożeniu.');
        $this->assertSame($stanPrzed, [SkladnikOdzywczy::count(), AliasSkladnika::count(), MiaraDomowa::count()]);
    }

    #[Test]
    public function wymus_powoduje_pelny_import_mimo_niezmienionych_plikow(): void
    {
        app(ImportujWartosciOdzywcze::class)->handle();

        $wynik = app(ImportujWartosciOdzywcze::class)->handle(wymus: true);

        $this->assertFalse($wynik['pominieto']);
    }

    #[Test]
    public function pusta_tabela_z_pasujacym_hashem_w_cache_i_tak_wykonuje_import(): void
    {
        // Scenariusz samoleczenia: baza świeża (np. po przywróceniu kopii),
        // a cache (osobna usługa, może przetrwać) wciąż pamięta stary hash.
        app(ImportujWartosciOdzywcze::class)->handle();
        SkladnikOdzywczy::query()->delete();

        $wynik = app(ImportujWartosciOdzywcze::class)->handle();

        $this->assertFalse($wynik['pominieto'], 'Pusta tabela nie powinna zostać pominięta, nawet gdy cache pamięta hash poprzedniego importu.');
        $this->assertGreaterThan(0, SkladnikOdzywczy::count());
    }

    protected function tearDown(): void
    {
        Cache::forget('odzywcze:import:hash-plikow');
        parent::tearDown();
    }
}
