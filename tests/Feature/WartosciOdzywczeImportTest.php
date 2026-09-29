<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Domain\Recipes\Odzywcze\JednostkiMiary;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AliasSkladnika;
use App\Models\MiaraDomowa;
use App\Models\SkladnikOdzywczy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('wiersz 3', $e->getMessage());
            $this->assertStringContainsString('kcal', $e->getMessage());
        }

        $this->assertSame($przed, SkladnikOdzywczy::count());
    }

    /**
     * @return array<string, array{0: string, 1: callable(string): string, 2: string}>
     */
    public static function uszkodzoneWejscia(): array
    {
        $pierwszaLiniaDanych = static function (string $t, callable $f): string {
            $linie = explode("\n", $t);
            $linie[1] = $f($linie[1]);

            return implode("\n", $linie);
        };

        return [
            'skladniki ucięte do nagłówka' => ['skladniki.csv', static fn (string $t): string => explode("\n", $t)[0]."\n", 'co najmniej jeden wiersz'],
            'miary ucięte do nagłówka' => ['miary.csv', static fn (string $t): string => explode("\n", $t)[0]."\n", 'co najmniej jeden wiersz'],
            'zły nagłówek' => ['skladniki.csv', static fn (string $t): string => 'x'.$t, 'nagłówek'],
            'wiersz ucięty w połowie' => ['skladniki.csv', static fn (string $t): string => $t."ucieta,Ucięta,ucieta\n", 'zła liczba kolumn'],
            'pusty klucz' => ['skladniki.csv', static fn (string $t): string => $pierwszaLiniaDanych($t, static fn (string $l): string => (string) preg_replace('/^[^,]*/', '', $l)), 'klucz'],
            'pusta wartość energii' => ['skladniki.csv', static fn (string $t): string => $pierwszaLiniaDanych($t, static fn (string $l): string => (string) preg_replace('/,[^,]*,[^,]*,[^,]*,[^,]*$/', ',,1,1,1', $l)), 'kcal'],
            'duplikat klucza' => ['skladniki.csv', static fn (string $t): string => $t."cukier,Cukier drugi,,ciqual,1,,0,Sucre,399,0,0,99.7\n", 'drugi raz'],
            'duplikat miary' => ['miary.csv', static fn (string $t): string => $t.explode("\n", $t)[1]."\n", 'drugi raz'],
            'miara nieznanego składnika' => ['miary.csv', static fn (string $t): string => $t."nie_ma_takiego,lyzka,10,\n", 'nie ma składnika'],
            'miara z pustymi gramami' => ['miary.csv', static fn (string $t): string => $t."cukier,szczypta,,\n", 'gramy'],
        ];
    }

    #[Test]
    #[DataProvider('uszkodzoneWejscia')]
    public function uszkodzony_plik_jest_odrzucony_bez_ruszania_slownika_i_znacznika(string $plik, callable $psuj, string $komunikat): void
    {
        app(ImportujWartosciOdzywcze::class)->handle();
        $stanPrzed = [SkladnikOdzywczy::count(), AliasSkladnika::count(), MiaraDomowa::count()];
        $znacznik = Cache::get('odzywcze:import:hash-plikow');
        $this->assertNotNull($znacznik);

        $katalog = $this->kopiaDanych();
        file_put_contents($katalog.'/'.$plik, $psuj((string) file_get_contents($katalog.'/'.$plik)));

        try {
            app(ImportujWartosciOdzywcze::class)->handle($katalog);
            $this->fail('Uszkodzony plik przeszedł import.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString($komunikat, $e->getMessage());
        }

        $this->assertSame($stanPrzed, [SkladnikOdzywczy::count(), AliasSkladnika::count(), MiaraDomowa::count()]);
        $this->assertSame($znacznik, Cache::get('odzywcze:import:hash-plikow'), 'Nieudany import nie może zmienić znacznika.');
    }

    #[Test]
    public function test_ten_sam_alias_przy_dwoch_skladnikach_jest_bledem(): void
    {
        $katalog = $this->kopiaDanych();
        $plik = $katalog.'/skladniki.csv';
        file_put_contents($plik, (string) file_get_contents($plik)."\nzduplikowany,cukier drugi,cukru,ciqual,31016,,0,Sucre blanc,399,0,0,99.7\n");

        $this->expectException(BladDlaCzlowieka::class);
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

    /**
     * #1963 — miara „kotlet” była w `miary.csv`, ale nie było jej w
     * `JednostkiMiary::SLOWA`, więc parser nigdy nie mógł jej wybrać
     * (kalkulator próbował dla takiego składnika miary „szt”, której
     * w pliku nie ma). Ten test pilnuje odwrotnej kompletności: KAŻDA
     * jednostka, jaka występuje w `miary.csv`, musi być kodem, na który
     * `JednostkiMiary::SLOWA` potrafi wskazać choć jedną formę słowną —
     * inaczej dana miara jest w pliku, ale parser nigdy jej nie wybierze.
     */
    #[Test]
    public function test_kazda_jednostka_z_miary_csv_jest_rozpoznawana_przez_parser(): void
    {
        $kodySlow = array_unique(array_values(JednostkiMiary::SLOWA));
        $jednostkiZPliku = array_unique(array_column(
            $this->wczytajMiary(base_path('database/data/odzywcze/miary.csv')),
            'jednostka',
        ));

        $nieobslugiwane = array_values(array_diff($jednostkiZPliku, $kodySlow));

        $this->assertSame(
            [],
            $nieobslugiwane,
            'W miary.csv są jednostki, których żadna forma słowna nie jest w JednostkiMiary::SLOWA, '
            .'więc parser nigdy ich nie wybierze: '.implode(', ', $nieobslugiwane).'.',
        );
    }

    /**
     * @return list<array{klucz: string, jednostka: string}>
     */
    private function wczytajMiary(string $sciezka): array
    {
        $uchwyt = fopen($sciezka, 'r');
        $this->assertNotFalse($uchwyt, "Nie da się otworzyć {$sciezka}.");

        fgetcsv($uchwyt, escape: ''); // nagłówek
        $wiersze = [];
        while (($w = fgetcsv($uchwyt, escape: '')) !== false) {
            if ($w === [null]) {
                continue;
            }
            $wiersze[] = ['klucz' => (string) $w[0], 'jednostka' => (string) $w[1]];
        }
        fclose($uchwyt);

        return $wiersze;
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
