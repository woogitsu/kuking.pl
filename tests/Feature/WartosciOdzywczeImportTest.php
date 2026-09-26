<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Models\AliasSkladnika;
use App\Models\MiaraDomowa;
use App\Models\SkladnikOdzywczy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Tabela wartości odżywczych z plików w repozytorium (D-299, I-9).
 *
 * Źródło i licencje: `database/data/odzywcze/ZRODLA.md`. Import jest
 * idempotentny, nie pobiera niczego z sieci, a baza sama pilnuje, żeby
 * wartości nie były ujemne.
 */
final class WartosciOdzywczeImportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_import_z_repozytorium_jest_idempotentny(): void
    {
        $this->artisan('kuking:importuj-wartosci-odzywcze')->assertSuccessful();
        $pierwszy = [SkladnikOdzywczy::count(), AliasSkladnika::count(), MiaraDomowa::count()];

        $this->artisan('kuking:importuj-wartosci-odzywcze')->assertSuccessful();

        $this->assertSame($pierwszy, [SkladnikOdzywczy::count(), AliasSkladnika::count(), MiaraDomowa::count()]);
        $this->assertGreaterThanOrEqual(200, $pierwszy[0], 'Projekt zakłada ok. 300 pozycji — mniej niż 200 to znak, że plik się uciął.');
        $this->assertGreaterThan(0, SkladnikOdzywczy::where('zrodlo', 'ciqual')->count());
        $this->assertGreaterThan(0, SkladnikOdzywczy::where('zrodlo', 'usda')->count());
    }

    #[Test]
    public function test_szklanka_maki_nie_wazy_tyle_co_szklanka_cukru(): void
    {
        app(ImportujWartosciOdzywcze::class)->handle();

        $szklanka = static fn (string $klucz): float => (float) MiaraDomowa::query()
            ->whereHas('skladnik', fn ($q) => $q->where('klucz', $klucz))
            ->where('jednostka', 'szklanka')
            ->value('gramy');

        $this->assertSame(140.0, $szklanka('maka_pszenna'));
        $this->assertSame(220.0, $szklanka('cukier'));
        $this->assertNotEquals($szklanka('maka_pszenna'), $szklanka('cukier'));
    }

    #[Test]
    public function test_pozycja_usunieta_z_pliku_znika_z_bazy(): void
    {
        $katalog = $this->kopiaDanych();
        app(ImportujWartosciOdzywcze::class)->handle($katalog);
        $this->assertTrue(SkladnikOdzywczy::where('klucz', 'gnocchi')->exists());

        foreach (['skladniki.csv', 'miary.csv'] as $nazwa) {
            $plik = $katalog.'/'.$nazwa;
            file_put_contents($plik, implode("\n", array_filter(
                explode("\n", (string) file_get_contents($plik)),
                static fn (string $linia): bool => ! str_starts_with($linia, 'gnocchi,'),
            )));
        }
        $wynik = app(ImportujWartosciOdzywcze::class)->handle($katalog);

        $this->assertSame(1, $wynik['usuniete']);
        $this->assertFalse(SkladnikOdzywczy::where('klucz', 'gnocchi')->exists());
        $this->assertFalse(AliasSkladnika::where('alias', 'gnocchi')->exists(), 'Alias usuniętej pozycji nie może zostać.');
    }

    #[Test]
    public function test_bledny_plik_nie_zmienia_niczego_i_mowi_ktory_wiersz(): void
    {
        app(ImportujWartosciOdzywcze::class)->handle();
        $przed = SkladnikOdzywczy::count();

        $katalog = $this->kopiaDanych();
        $plik = $katalog.'/skladniki.csv';
        $linie = explode("\n", (string) file_get_contents($plik));
        $linie[2] = (string) preg_replace('/,[^,]*,[^,]*,[^,]*,[^,]*$/', ',-5,1,1,1', $linie[2]);
        file_put_contents($plik, implode("\n", $linie));

        try {
            app(ImportujWartosciOdzywcze::class)->handle($katalog);
            $this->fail('Ujemna energia przeszła walidację importu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('wiersz 3', $e->getMessage());
            $this->assertStringContainsString('kcal', $e->getMessage());
        }

        $this->assertSame($przed, SkladnikOdzywczy::count());
    }

    #[Test]
    public function test_ten_sam_alias_przy_dwoch_skladnikach_jest_bledem(): void
    {
        $katalog = $this->kopiaDanych();
        $plik = $katalog.'/skladniki.csv';
        file_put_contents($plik, (string) file_get_contents($plik)."\nzduplikowany,cukier drugi,cukru,ciqual,31016,,0,Sucre blanc,399,0,0,99.7\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('jest już przy „cukier”');

        app(ImportujWartosciOdzywcze::class)->handle($katalog);
    }

    #[Test]
    public function test_baza_odrzuca_ujemne_wartosci_nawet_z_pominieciem_importu(): void
    {
        $this->expectException(QueryException::class);

        DB::table('skladniki_odzywcze')->insert([
            'klucz' => 'zly', 'nazwa' => 'zły', 'zrodlo' => 'ciqual', 'zrodlo_id' => '1', 'zrodlo_nazwa' => 'x',
            'kcal_100g' => 10, 'bialko_100g' => -1, 'tluszcz_100g' => 0, 'weglowodany_100g' => 0,
        ]);
    }

    #[Test]
    public function test_baza_odrzuca_nieznane_zrodlo(): void
    {
        $this->expectException(QueryException::class);

        DB::table('skladniki_odzywcze')->insert([
            'klucz' => 'zly', 'nazwa' => 'zły', 'zrodlo' => 'izz', 'zrodlo_id' => '1', 'zrodlo_nazwa' => 'x',
            'kcal_100g' => 10, 'bialko_100g' => 1, 'tluszcz_100g' => 0, 'weglowodany_100g' => 0,
        ]);
    }

    #[Test]
    public function test_pliki_danych_maja_zapisane_zrodla_i_licencje(): void
    {
        $zrodla = (string) file_get_contents(base_path('database/data/odzywcze/ZRODLA.md'));

        $this->assertStringContainsString('Etalab', $zrodla);
        $this->assertStringContainsString('CC0', $zrodla);
        $this->assertStringContainsString('10.57745/RDMHWY', $zrodla, 'Wersja CIQUAL ma być wskazana identyfikatorem, nie tylko nazwą.');
    }

    private function kopiaDanych(): string
    {
        $katalog = sys_get_temp_dir().'/odzywcze-'.bin2hex(random_bytes(4));
        mkdir($katalog);
        copy(base_path('database/data/odzywcze/skladniki.csv'), $katalog.'/skladniki.csv');
        copy(base_path('database/data/odzywcze/miary.csv'), $katalog.'/miary.csv');

        return $katalog;
    }
}
