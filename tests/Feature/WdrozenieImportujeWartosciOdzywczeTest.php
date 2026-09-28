<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Models\AliasSkladnika;
use App\Models\MiaraDomowa;
use App\Models\SkladnikOdzywczy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 *
 * @bez-kontroli-dodatniej Nowe przypadki #2130 wykonują import na PostgreSQL i porównują zawartość trzech tabel; test czytający plik wdrożenia pochodzi z #1961.
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

        $pozycjaMigracji = mb_strpos($komendy, 'artisan kuking:migruj-pod-blokada');
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
        $zapisy = [];
        DB::listen(static function ($query) use (&$zapisy): void {
            if (preg_match('/\b(insert|update|delete)\b.*\b(skladniki_odzywcze|aliasy_skladnikow|miary_domowe)\b/i', $query->sql) === 1) {
                $zapisy[] = $query->sql;
            }
        });

        $drugi = app(ImportujWartosciOdzywcze::class)->handle();

        $this->assertTrue($drugi['pominieto'], 'Drugi import na niezmienionych plikach powinien się pominąć, a nie przepisywać tabele przy każdym wdrożeniu.');
        $this->assertSame($stanPrzed, [SkladnikOdzywczy::count(), AliasSkladnika::count(), MiaraDomowa::count()]);
        $this->assertSame([], $zapisy, 'Szybka ścieżka nie zapisuje żadnej z trzech tabel słownika.');
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

    #[Test]
    public function pasujacy_hash_nie_ukrywa_braku_aliasow_po_czesciowym_restore(): void
    {
        $pierwszy = app(ImportujWartosciOdzywcze::class)->handle();
        $this->assertGreaterThan(0, $pierwszy['aliasy']);
        AliasSkladnika::query()->delete();

        $wynik = app(ImportujWartosciOdzywcze::class)->handle();

        $this->assertFalse($wynik['pominieto']);
        $this->assertSame($pierwszy['aliasy'], AliasSkladnika::query()->count());
    }

    #[Test]
    public function pasujacy_hash_nie_ukrywa_braku_miar_po_czesciowym_restore(): void
    {
        $pierwszy = app(ImportujWartosciOdzywcze::class)->handle();
        $this->assertGreaterThan(0, $pierwszy['miary']);
        MiaraDomowa::query()->delete();

        $wynik = app(ImportujWartosciOdzywcze::class)->handle();

        $this->assertFalse($wynik['pominieto']);
        $this->assertSame($pierwszy['miary'], MiaraDomowa::query()->count());
    }

    #[Test]
    public function pasujacy_hash_nie_ukrywa_braku_jednego_skladnika(): void
    {
        $pierwszy = app(ImportujWartosciOdzywcze::class)->handle();
        $this->assertGreaterThan(1, $pierwszy['skladniki']);
        SkladnikOdzywczy::query()->firstOrFail()->delete();
        $this->assertGreaterThan(0, SkladnikOdzywczy::query()->count());

        $wynik = app(ImportujWartosciOdzywcze::class)->handle();

        $this->assertFalse($wynik['pominieto']);
        $this->assertSame($pierwszy['skladniki'], SkladnikOdzywczy::query()->count());
        $this->assertSame($pierwszy['aliasy'], AliasSkladnika::query()->count());
        $this->assertSame($pierwszy['miary'], MiaraDomowa::query()->count());
    }

    #[Test]
    public function ta_sama_liczba_aliasow_nie_maskuje_zmienionej_tresci(): void
    {
        $pierwszy = app(ImportujWartosciOdzywcze::class)->handle();
        $alias = AliasSkladnika::query()->firstOrFail();
        $oryginal = $alias->alias;
        $alias->forceFill(['alias' => 'testowo_zmieniony_alias'])->save();
        $this->assertSame($pierwszy['aliasy'], AliasSkladnika::query()->count());

        $wynik = app(ImportujWartosciOdzywcze::class)->handle();

        $this->assertFalse($wynik['pominieto']);
        $this->assertTrue(AliasSkladnika::query()->where('alias', $oryginal)->exists());
        $this->assertFalse(AliasSkladnika::query()->where('alias', 'testowo_zmieniony_alias')->exists());
    }

    #[Test]
    public function blad_odbudowy_nie_zmienia_znacznika_ani_nie_zostawia_pol_slownika(): void
    {
        app(ImportujWartosciOdzywcze::class)->handle();
        $znacznik = Cache::get('odzywcze:import:hash-plikow');
        AliasSkladnika::query()->delete();
        DB::unprepared("CREATE FUNCTION test_import_alias_awaria() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'testowa awaria aliasu'; END $$");
        DB::unprepared('CREATE TRIGGER test_import_alias_awaria BEFORE INSERT ON aliasy_skladnikow FOR EACH ROW EXECUTE FUNCTION test_import_alias_awaria()');

        try {
            try {
                app(ImportujWartosciOdzywcze::class)->handle();
                $this->fail('Wstrzyknięta awaria miała przerwać odbudowę.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('testowa awaria aliasu', $e->getMessage());
            }
            $this->assertSame($znacznik, Cache::get('odzywcze:import:hash-plikow'));
            $this->assertSame(0, AliasSkladnika::query()->count());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS test_import_alias_awaria ON aliasy_skladnikow');
            DB::unprepared('DROP FUNCTION IF EXISTS test_import_alias_awaria()');
        }
    }

    protected function tearDown(): void
    {
        Cache::forget('odzywcze:import:hash-plikow');
        parent::tearDown();
    }
}
